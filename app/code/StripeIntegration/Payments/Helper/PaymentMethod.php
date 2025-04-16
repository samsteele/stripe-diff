<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Model\ResourceModel\StripePaymentMethod as ResourceStripePaymentMethod;
use StripeIntegration\Payments\Model\StripePaymentMethodFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderExtensionFactory;

class PaymentMethod
{
    private $methodDetails = [];
    private $themeModel = null;
    private const CAN_BE_SAVED_ON_SESSION = [
        'acss_debit',
        'au_becs_debit',
        'boleto',
        'card',
        'sepa_debit',
        'us_bank_account', // ACHv2
        // Commented due to an issue with mandate data. Saved Korean payment methods cannot be reused for follow up orders.
        // 'kr_card',
        // 'kakao_pay'
    ];

    // These should always have setup_future_usage=off_session,
    // because they do not support on_session saving.
    // Do not add methods that can be saved on_session here.
    private const CAN_ONLY_BE_SAVED_OFF_SESSION = [
        'bancontact',
        'ideal',
        'link',
        'revolut_pay',
        'paypal'
    ];

    public const SUPPORTS_SUBSCRIPTIONS = [
        'card',
        'sepa_debit',
        'revolut_pay',
        'us_bank_account'
    ];
    public const SETUP_INTENT_PAYMENT_METHOD_OPTIONS = [
        'acss_debit',
        'card',
        'sepa_debit',
        'us_bank_account'
    ];
    public const CAN_AUTHORIZE_ONLY = [
        'card',
        'link',
        'afterpay_clearpay',
        'klarna',
        'paypal',
        'amazon_pay',
        'mobilepay',
        'samsung_pay',
        'kr_card',
        'kakao_pay',
        'naver_pay',
        'payco',
        'revolut_pay'
    ];
    public const REQUIRES_VOUCHER_PAYMENT = [
        'boleto',
        'oxxo',
        'konbini'
    ];

    public const STRIPE_CHECKOUT_ON_SESSION_PM = [
        'acss_debit',
        'bacs_debit',
        'boleto',
        'card',
        'cashapp',
        'sepa_debit',
        'us_bank_account'
    ];

    public const STRIPE_CHECKOUT_OFF_SESSION_PM = [
        'link',
        'paypal'
    ];

    public const STRIPE_CHECKOUT_NONE_PM = [
        'affirm',
        'afterpay_clearpay',
        'alipay',
        'au_becs_debit',
        'bancontact',
        'eps',
        'fpx',
        'giropay',
        'grabpay',
        'ideal',
        'klarna',
        'konbini',
        'oxxo',
        'p24',
        'paynow'
    ];

    private $dataHelper;
    private $request;
    private $assetRepo;
    private $scopeConfig;
    private $storeManager;
    private $themeProvider;
    private $checkoutFlow;

    protected $stripePaymentMethodFactory;

    protected $resourceStripePaymentMethod;

    protected $json;

    protected $orderExtensionFactory;

    private $appEmulation;

    private $areaCodeHelper;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        ResourceStripePaymentMethod $resourceStripePaymentMethod,
        StripePaymentMethodFactory $stripePaymentMethodFactory,
        Json $json,
        OrderExtensionFactory $orderExtensionFactory,
        \Magento\Store\Model\App\Emulation $appEmulation
    ) {
        $this->request = $request;
        $this->assetRepo = $assetRepo;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->themeProvider = $themeProvider;
        $this->dataHelper = $dataHelper;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->resourceStripePaymentMethod = $resourceStripePaymentMethod;
        $this->json = $json;
        $this->orderExtensionFactory = $orderExtensionFactory;
        $this->appEmulation = $appEmulation;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->checkoutFlow = $checkoutFlow;
    }

    public function getCardIcon($brand)
    {
        $icon = $this->getPaymentMethodIcon($brand);
        if ($icon)
            return $icon;

        return $this->getPaymentMethodIcon('generic');
    }

    public function getCardLabel($card, $hideLast4 = false)
    {
        if (!empty($card->last4) && !$hideLast4)
            return __("•••• %1", $card->last4);

        if (!empty($card->brand))
            return $this->getCardName($card->brand);

        return __("Card");
    }

    protected function getCardName($brand)
    {
        if (empty($brand))
            return "Card";

        $details = $this->getPaymentMethodDetails();
        if (isset($details[$brand]))
            return $details[$brand]['name'];

        return ucfirst($brand);
    }

    public function getIcon($method, $format = null)
    {
        if (is_array($method)) {
            $method = (object) $method;
        }
        $type = $method->type;

        $defaultIcon = $this->getPaymentMethodIcon($type);
        if ($defaultIcon)
        {
            $icon = $defaultIcon;
        }
        else if ($type == "card" && !empty($method->card->brand))
        {
            $icon = $this->getCardIcon($method->card->brand);
        }
        else
        {
            $icon = $this->getPaymentMethodIcon("bank");
        }

        if ($format)
            $icon = str_replace(".svg", ".$format", $icon);

        return $icon;
    }

    public function getPaymentMethodIcon($code)
    {
        $details = $this->getPaymentMethodDetails();
        if (isset($details[$code]))
            return $details[$code]['icon'];

        return null;
    }

    public function getPaymentMethodName($code)
    {
        $details = $this->getPaymentMethodDetails();

        if (isset($details[$code]))
            return $details[$code]['name'];

        return ucwords(str_replace("_", " ", $code));
    }

    public function getCVCIcon()
    {
        return $this->getViewFileUrl("StripeIntegration_Payments::img/icons/cvc.svg");
    }

    public function getPaymentMethodDetails()
    {
        if (!empty($this->methodDetails))
            return $this->methodDetails;

        return $this->methodDetails = [
            // APMs
            'acss_debit' => [
                'name' => "ACSS Direct Debit / Canadian PADs",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'afterpay_clearpay' => [
                'name' => "Afterpay / Clearpay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/afterpay_clearpay.svg")
            ],
            'alipay' => [
                'name' => "Alipay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/alipay.svg")
            ],
            'alma' => [
                'name' => "Alma",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/alma.svg")
            ],
            'amazon_pay' => [
                'name' => "Amazon Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/amazon_pay.svg")
            ],
            'bacs_debit' => [
                'name' => "BACS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bacs_debit.svg")
            ],
            'au_becs_debit' => [
                'name' => "BECS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'bancontact' => [
                'name' => "Bancontact",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bancontact.svg")
            ],
            'boleto' => [
                'name' => "Boleto",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/boleto.svg")
            ],
            'customer_balance' => [
                'name' => "Bank transfer",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'eps' => [
                'name' => 'EPS',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/eps.svg")
            ],
            'fpx' => [
                'name' => "FPX",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/fpx.svg")
            ],
            'giropay' => [
                'name' => "Giropay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/giropay.svg")
            ],
            'grabpay' => [
                'name' => "GrabPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/grabpay.svg")
            ],
            'ideal' => [
                'name' => "iDEAL",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/ideal.svg")
            ],
            'kakao_pay' => [
                'name' => "Kakao Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/kakao_pay.svg")
            ],
            'klarna' => [
                'name' => "Klarna",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'konbini' => [
                'name' => "Konbini",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/konbini.svg")
            ],
            'kr_card' => [
                'name' => "Korean Card",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/generic.svg")
            ],
            'naver_pay' => [
                'name' => "Naver Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/naver_pay.svg")
            ],
            // Intentionally left empty because the logo is the same as the name
            'paypal' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paypal.svg")
            ],
            'mb_way' => [
                'name' => "MB WAY",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/mb_way.svg")
            ],
            'multibanco' => [
                'name' => "Multibanco",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/multibanco.svg")
            ],
            'p24' => [
                'name' => "P24",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/p24.svg")
            ],
            'revolut_pay' => [
                'name' => "Revolut Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/revolut_pay.svg")
            ],
            'samsung_pay' => [
                'name' => "Samsung Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/samsung_pay.svg")
            ],
            'sepa_debit' => [
                'name' => "SEPA Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_debit.svg")
            ],
            'sepa_credit' => [
                'name' => "SEPA Credit Transfer",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_credit.svg")
            ],
            'sofort' => [
                'name' => "Klarna",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'wechat_pay' => [
                'name' => "WeChat Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/wechat.svg")
            ],
            'ach_debit' => [
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'us_bank_account' => [ // ACHv2
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'oxxo' => [
                'name' => "OXXO",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/oxxo.svg")
            ],
            'payco' => [
                'name' => "PayCo",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/payco.svg")
            ],
            'paynow' => [
                'name' => "PayNow",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paynow.svg")
            ],
            'mobilepay' => [
                'name' => "MobilePay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/mobilepay.svg")
            ],
            'link' => [
                'name' => 'Link',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/link.svg")
            ],
            'bank' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'google_pay' => [
                'name' => "Google Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/google_pay.svg")
            ],
            'apple_pay' => [
                'name' => "Apple Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/apple_pay.svg")
            ],

            // Cards
            'amex' => [
                'name' => "American Express",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/amex.svg")
            ],
            'cartes_bancaires' => [
                'name' => "Cartes Bancaires",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/cartes_bancaires.svg")
            ],
            'diners' => [
                'name' => "Diners Club",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/diners.svg")
            ],
            'discover' => [
                'name' => "Discover",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/discover.svg")
            ],
            'generic' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/generic.svg")
            ],
            'jcb' => [
                'name' => "JCB",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/jcb.svg")
            ],
            'mastercard' => [
                'name' => "MasterCard",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/mastercard.svg")
            ],
            'visa' => [
                'name' => "Visa",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/visa.svg")
            ],
            'unionpay' => [
                'name' => "UnionPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/unionpay.svg")
            ]
        ];
    }

    public function getPaymentMethodLabel($method)
    {
        $type = $method->type;
        $methodName = $this->getPaymentMethodName($type);
        /** @var \stdClass $details */
        $details = $method->{$type};

        if ($type == "card")
        {
            return $this->getCardLabel($details);
        }
        else if ($type == "paypal")
        {
            return __("PayPal");
        }
        else if (isset($details->last4))
        {
            if (!empty($details->brand))
            {
                return __("%1 •••• %2", ucfirst($details->brand), $details->last4);
            }
            else
            {
                return __("%1 •••• %2", $methodName, $details->last4);
            }
        }
        else if (isset($details->tax_id)) // Boleto
        {
            return __("%1 - %2", $methodName, $details->tax_id);
        }
        else if ($this->getPaymentMethodName($type))
        {
            return $this->getPaymentMethodName($type);
        }
        else
        {
            return ucfirst($type);
        }
    }

    public function formatPaymentMethods($methods)
    {
        $savedMethods = [];

        if ($this->dataHelper->getConfigData("payment/stripe_payments/cvc_code") == "new_saved_cards")
        {
            $cvc = 1;
        }
        else
        {
            $cvc = 0;
        }

        foreach ($methods as $type => $methodList)
        {
            $methodName = $this->getPaymentMethodName($type);

            switch ($type)
            {
                case "kr_card":
                    foreach ($methodList as $method)
                    {
                        $details = $method->kr_card;
                        $key = $details->fingerprint ?? $method->id;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $method->fingerprint ?? $method->id,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getPaymentMethodIcon($type),
                            "cvc" => $cvc,
                            "brand" => null,
                            "exp_month" => null,
                            "exp_year" => null,
                        ];
                    }
                    break;
                case "card":
                    foreach ($methodList as $method)
                    {
                        $details = $method->card;
                        $key = $details->fingerprint ?? $method->id;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $key,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($details->brand),
                            "cvc" => $cvc,
                            "brand" => $details->brand,
                            "exp_month" => $details->exp_month,
                            "exp_year" => $details->exp_year,
                        ];
                    }
                    break;
                case "link":
                    foreach ($methodList as $method)
                    {
                        $details = $method->link;
                        $key = $details->fingerprint ?? $method->id;
                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getPaymentMethodIcon($type),
                            "fingerprint" => $key
                        ];
                    }
                    break;
                default:
                    foreach ($methodList as $method)
                    {
                        /** @var \Stripe\PaymentMethod $details */
                        $details = $method->{$type};

                        $icon = $this->getPaymentMethodIcon($type);
                        if (!$icon)
                            $icon = $this->getPaymentMethodIcon("bank");

                        $key = $details->fingerprint ?? $method->id;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);
                        if (empty($label))
                            continue;

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $key,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $icon
                        ];
                    }
                    break;
            }
        }

        return $savedMethods;
    }

    protected function getViewFileUrl($fileId)
    {
        $areaCode = $this->areaCodeHelper->getAreaCode();
        $compatibleAreaCodes = ['frontend', 'adminhtml'];

        if (!in_array($areaCode, $compatibleAreaCodes)) {
            $this->appEmulation->startEnvironmentEmulation($this->storeManager->getStore()->getId(), \Magento\Framework\App\Area::AREA_FRONTEND, true);
        }

        try
        {
            $params = [
                '_secure' => $this->request->isSecure()
            ];

            $return = $this->assetRepo->getUrlWithParams($fileId, $params);
        }
        catch (LocalizedException $e)
        {
            $return = null;
        }

        if (!in_array($areaCode, $compatibleAreaCodes)) {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        return $return;
    }

    protected function getThemeModel()
    {
        if ($this->themeModel)
            return $this->themeModel;

        $themeId = $this->scopeConfig->getValue(
            \Magento\Framework\View\DesignInterface::XML_PATH_THEME_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        $this->themeModel = $this->themeProvider->getThemeById($themeId);

        return $this->themeModel;
    }

    public function getIconFromPaymentType($type, $cardType = 'visa', $format = null)
    {
        if ($type === 'card') {
            $icon = $this->getCardIcon($cardType);
        } else {
            $icon = $this->getPaymentMethodIcon($type);
        }

        if (!$icon) {
            $icon = $this->getPaymentMethodIcon("bank");
        }

        if ($format)
            $icon = str_replace(".svg", ".$format", $icon);

        return $icon;
    }

    public function savePaymentMethod($order, $paymentMethodType, $cardData)
    {
        // Used in grid label
        $modelClass = $this->stripePaymentMethodFactory->create();
        $this->resourceStripePaymentMethod->load($modelClass, $order->getId(), 'order_id');
        $modelClass->setOrderId($order->getId());
        $modelClass->setPaymentMethodType($paymentMethodType);
        $modelClass->setPaymentMethodCardData($this->json->serialize($cardData));
        $this->resourceStripePaymentMethod->save($modelClass);

        // Used in grid search
        $searchablePaymentMethodType = $this->getSearchablePaymentMethodType($paymentMethodType, $cardData);
        if (!empty($searchablePaymentMethodType))
        {
            $order->setStripePaymentMethodType($searchablePaymentMethodType);
        }
    }

    public function getSearchablePaymentMethodType($paymentMethodType, $cardData)
    {
        $type = strtolower($this->getPaymentMethodName($paymentMethodType) ?? "");

        if (!empty($cardData['card_type']))
        {
            $type .= " " . strtolower($cardData['card_type']);
        }

        if (!empty($cardData['wallet']))
        {
            $wallet = explode("_", $cardData['wallet']);
            $wallet = implode(" ", $wallet);
            $type .= " " . $wallet;
        }

        return $type;
    }

    public function loadPaymentMethod($orderId)
    {
        $modelClass = $this->stripePaymentMethodFactory->create();
        $this->resourceStripePaymentMethod->load($modelClass, $orderId, 'order_id');
        return $modelClass;
    }

    public function getPaymentMethodsThatCanBeSaved()
    {
        $methods = array_merge(self::CAN_BE_SAVED_ON_SESSION, self::CAN_ONLY_BE_SAVED_OFF_SESSION);

        if ($this->checkoutFlow->isExpressCheckout)
        {
            // Express Checkout does not support setup_future_usage when used with PayPal.
            $methods = array_diff($methods, ['paypal']);
        }

        return $methods;
    }

    public function getPaymentMethodsThatCanOnlyBeSavedOffSession()
    {
        $methods = self::CAN_ONLY_BE_SAVED_OFF_SESSION;

        if ($this->checkoutFlow->isExpressCheckout)
        {
            // Express Checkout does not support setup_future_usage when used with PayPal.
            $methods = array_diff($methods, ['paypal']);
        }

        return $methods;
    }

    public function getPaymentMethodsThatCanCaptureManually()
    {
        return self::CAN_AUTHORIZE_ONLY;
    }

    public function supportsSubscriptions(?string $methodCode)
    {
        if (empty($methodCode))
            return false;

        return in_array($methodCode, ["stripe_payments", "stripe_payments_checkout", "stripe_payments_express"]);
    }

    public function getExternalPaymentMethods($quote): array
    {
        $methods = [];

        // $methods[] = [
        //     'code' => 'external_payment_method_code',
        //     'redirect_url' => "https://example.com/checkout?merchant=stripeintegration&amount=" . $quote->getGrandTotal() * 100
        // ];

        return $methods;
    }
}
