<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Exception\Exception;

class CheckoutSession extends \Magento\Framework\Model\AbstractModel
{
    private ?\StripeIntegration\Payments\Model\Stripe\Checkout\Session $stripeCheckoutSession = null;
    private $stripeCheckoutSessionFactory;
    private \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper;
    private \StripeIntegration\Payments\Helper\Generic $paymentsHelper;
    private $localeHelper;
    private $stripeCouponFactory;
    private $customer;
    private $config;
    private $addressHelper;
    private $scopeConfig;
    private $stripeProductFactory;
    private $stripePriceFactory;
    private $compare;
    private $startDateFactory;
    private $quote = null;
    private $stripeCustomer;
    private $resourceModel;
    private $addressFactory;
    private $quoteHelper;
    private $orderHelper;
    private $checkoutSession;
    private $convert;
    private $cartInfo;
    private $checkoutFlow;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Checkout\SessionFactory $stripeCheckoutSessionFactory,
        \StripeIntegration\Payments\Model\Cart\Info $cartInfo,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $stripeCouponFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Model\Stripe\ProductFactory $stripeProductFactory,
        \StripeIntegration\Payments\Model\Stripe\PriceFactory $stripePriceFactory,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Model\Subscription\StartDateFactory $startDateFactory,
        \StripeIntegration\Payments\Model\ResourceModel\CheckoutSession $resourceModel,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Model\Context $context,
        \Magento\Customer\Model\AddressFactory $addressFactory,
        \Magento\Framework\Registry $registry,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->stripeCheckoutSessionFactory = $stripeCheckoutSessionFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->config = $config;
        $this->paymentsHelper = $paymentsHelper;
        $this->localeHelper = $localeHelper;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->customer = $this->paymentsHelper->getCustomerModel();
        $this->addressHelper = $addressHelper;
        $this->scopeConfig = $scopeConfig;
        $this->stripeProductFactory = $stripeProductFactory;
        $this->stripePriceFactory = $stripePriceFactory;
        $this->compare = $compare;
        $this->startDateFactory = $startDateFactory;
        $this->resourceModel = $resourceModel;
        $this->checkoutFlow = $checkoutFlow;
        $this->addressFactory = $addressFactory;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->checkoutSession = $checkoutSession;
        $this->convert = $convert;
        $this->cartInfo = $cartInfo;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\CheckoutSession');
    }

    // Creates a session if one does not exist
    public function fromOrder($order)
    {
        $this->checkoutFlow->isCheckoutSessionRecreated = true;
        $this->quote = null;

        if (empty($order))
        {
            throw new GenericException('Invalid Stripe Checkout order.');
        }

        $this->resourceModel->load($this, $order->getQuoteId(), 'quote_id');

        $this->quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());

        if (empty($this->quote) || empty($this->quote->getId()))
        {
            throw new GenericException('Could not find quote for order.');
        }

        $params = $this->getParamsFromOrder($order);
        $this->stripeCheckoutSession = $this->stripeCheckoutSessionFactory->create()->fromParams($params);
        $checkoutSessionObject = $this->stripeCheckoutSession->getStripeObject();

        $this->setQuoteId($this->quote->getId());
        $this->setOrderIncrementId($order->getIncrementId());
        $this->setCheckoutSessionId($checkoutSessionObject->id);
        $this->resourceModel->save($this);

        $this->checkoutSession->setStripePaymentsCheckoutSessionId($checkoutSessionObject->id);
        $this->checkoutSession->setStripePaymentsCheckoutSessionURL($checkoutSessionObject->url);

        return $this;
    }

    private function createDummyCheckoutSessionFrom($quote)
    {
        $params = $this->getParamsFromQuote($quote);
        $stripeCheckoutSession = $this->stripeCheckoutSessionFactory->create()->fromParams($params);
        return $stripeCheckoutSession->getStripeObject();
    }

    public function getAvailablePaymentMethods($quote)
    {
        $methods = [];

        if (empty($quote) || empty($quote->getId()))
            return $methods;

        $checkoutSession = $this->createDummyCheckoutSessionFrom($quote);

        if (!empty($checkoutSession->payment_method_types))
            $methods = $checkoutSession->payment_method_types;

        return $methods;
    }

    public function getStripeObject()
    {
        if (empty($this->stripeCheckoutSession))
            return null;

        return $this->stripeCheckoutSession->getStripeObject();
    }

    public function getParamsFromOrder($order)
    {
        $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);

        $grandTotal = $order->getGrandTotal();
        if (!empty($subscription['profile']['deducted_order_amount']))
            $grandTotal += $subscription['profile']['deducted_order_amount'];

        $lineItems = $this->getLineItems($subscription, $grandTotal, $order->getOrderCurrencyCode());
        $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());
        $params = $this->getParamsFrom($lineItems, $subscription, $quote, $order);

        return $params;
    }

    public function getParamsFromQuote($quote)
    {
        if (empty($quote))
            throw new GenericException("No quote specified for Checkout params.");

        $subscription = $this->subscriptionsHelper->getSubscriptionFromQuote($quote);
        $lineItems = $this->getLineItems($subscription, $quote->getGrandTotal(), $quote->getQuoteCurrencyCode());
        $params = $this->getParamsFrom($lineItems, $subscription, $quote);

        return $params;
    }

    protected function getLineItems($subscription, $grandTotal, $currencyCode)
    {
        $currency = strtolower($currencyCode);
        $lines = [];

        if (empty($subscription['profile']))
        {
            $oneTimePayment = $this->getOneTimePayment($grandTotal, $currency);
            if ($oneTimePayment)
                $lines[] = $oneTimePayment;
        }
        else
        {
            $profile = $subscription['profile'];
            $subscriptionsProductIDs[] = $subscription['product']->getId();
            $interval = $profile['interval'];
            $intervalCount = $profile['interval_count'];

            $subscriptionTotal = $this->subscriptionsHelper->getSubscriptionTotalFromProfile($profile);
            $currencyPrecision = $this->convert->getCurrencyPrecision($currency);
            $subscriptionTotal = round(floatval($subscriptionTotal), $currencyPrecision);

            $remainingAmount = $grandTotal - $subscriptionTotal;

            $oneTimePayment = $this->getOneTimePayment($remainingAmount, $currency, true);
            if ($oneTimePayment)
                $lines[] = $oneTimePayment;

            $recurringPayment = $this->getRecurringPayment($subscription, $subscriptionsProductIDs, $subscriptionTotal, $currency, $interval, $intervalCount);
            if ($recurringPayment)
                $lines[] = $recurringPayment;
        }

        return $lines;
    }

    protected function getParamsFrom($lineItems, $subscription, $quote, $order = null)
    {
        $returnUrl = $this->paymentsHelper->getUrl('stripe/payment/index', ["payment_method" => "stripe_checkout"]);
        $cancelUrl = $this->paymentsHelper->getUrl('stripe/payment/cancel', ["payment_method" => "stripe_checkout"]);

        $params = [
            'expires_at' => $this->getExpirationTime(),
            'cancel_url' => $cancelUrl,
            'success_url' => $returnUrl,
            'locale' => $this->localeHelper->getStripeCheckoutLocale()
        ];

        if (!empty($subscription))
        {
            $params["mode"] = "subscription";
            $params["line_items"] = $lineItems;
            $params["subscription_data"] = [
                "metadata" => $this->subscriptionsHelper->collectMetadataForSubscription($subscription['profile'])
            ];

            $profile = $subscription['profile'];

            if ($profile['expiring_coupon'])
            {
                $coupon = $this->stripeCouponFactory->create()->fromSubscriptionProfile($profile);
                if ($coupon->getId())
                {
                    $params['discounts'][] = ['coupon' => $coupon->getId()];
                }
            }

            $startDateModel = $this->startDateFactory->create()->fromProfile($profile);
            $hasOneTimePayment = false;
            foreach($lineItems as $lineItem)
            {
                if ($this->isOneTimePayment($lineItem))
                {
                    $hasOneTimePayment = true;
                    break;
                }
            }
            if ($startDateModel->isCompatibleWithTrials($hasOneTimePayment))
            {
                if ($profile['trial_end'])
                {
                    $params['subscription_data']['trial_period_days'] = $startDateModel->getDaysUntilStartDate($profile['trial_end']);
                }
                else if ($profile['trial_days'])
                {
                    $params['subscription_data']['trial_period_days'] = $profile['trial_days'];
                }
            }

            $startDateParams = $startDateModel->getParams($hasOneTimePayment, true);
            if (!empty($startDateParams))
            {
                $params['subscription_data'] = array_merge_recursive($params['subscription_data'], $startDateParams);
            }

            $params["payment_method_options"] = $this->getPaymentMethodOptions();
        }
        else if ($this->config->getPaymentAction() == "order")
        {
            $params['mode'] = 'setup';
            $params['payment_method_types'] = ['card'];
        }
        else
        {
            $params["mode"] = "payment";
            $params["line_items"] = $lineItems;
            $params["payment_intent_data"] = $this->getPaymentIntentData($quote, $order);
            $params["submit_type"] = "pay";
            $params["payment_method_options"] = $this->getPaymentMethodOptions();
        }

        if ($this->config->alwaysSaveCards())
        {
            try
            {
                $this->customer->createStripeCustomerIfNotExists(false, $order);
                $this->stripeCustomer = $this->customer->retrieveByStripeID();
                if (!empty($this->stripeCustomer->id))
                    $params['customer'] = $this->stripeCustomer->id;

                if ($params["mode"] == "payment")
                {
                    $stripeCheckoutOnSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_ON_SESSION_PM;
                    $value = ['setup_future_usage' => 'on_session'];
                    foreach ($stripeCheckoutOnSession as $code)
                    {
                        $params["payment_method_options"][$code] = $value + ($params["payment_method_options"][$code] ?? []);
                    }

                    $stripeCheckoutOffSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_OFF_SESSION_PM;
                    $value = ['setup_future_usage' => 'off_session'];
                    foreach ($stripeCheckoutOffSession as $code)
                    {
                        $params["payment_method_options"][$code] = $value + ($params["payment_method_options"][$code] ?? []);
                    }

                    $stripeCheckoutNoneSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_NONE_PM;
                    $value = ['setup_future_usage' => 'none'];
                    foreach ($stripeCheckoutNoneSession as $code)
                    {
                        $params["payment_method_options"][$code] = $value + ($params["payment_method_options"][$code] ?? []);
                    }
                }
            }
            catch (\Stripe\Exception\CardException $e)
            {
                throw new LocalizedException(__($e->getMessage()));
            }
            catch (\Exception $e)
            {
                $this->paymentsHelper->throwError(__('An error has occurred. Please contact us to complete your order.'), $e);
            }
        }
        else
        {
            if ($this->paymentsHelper->isCustomerLoggedIn())
                $this->customer->createStripeCustomerIfNotExists(false, $order);

            $this->stripeCustomer = $this->customer->retrieveByStripeID();
            if (!empty($this->stripeCustomer->id))
                $params['customer'] = $this->stripeCustomer->id;
            else if ($order)
                $params['customer_email'] = $order->getCustomerEmail();
            else if ($quote->getCustomerEmail())
                $params['customer_email'] = $quote->getCustomerEmail();
        }

        return $params;
    }

    protected function getPaymentMethodOptions()
    {
        return [
            "acss_debit" => [
                "mandate_options" => [
                    "payment_schedule" => "sporadic",
                    "transaction_type" => "personal"
                ]
            ]
        ];
    }

    protected function getExpirationTime()
    {
        $storeId = $this->paymentsHelper->getStoreId();
        $cookieLifetime = $this->scopeConfig->getValue("web/cookie/cookie_lifetime", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $oneHour = 1 * 60 * 60;
        $twentyFourHours = 24 * 60 * 60;
        $cookieLifetime = max($oneHour, $cookieLifetime);
        $cookieLifetime = min($twentyFourHours, $cookieLifetime);
        $timeDifference = $this->paymentsHelper->getStripeApiTimeDifference();

        return time() + $cookieLifetime + $timeDifference;
    }


    protected function getOneTimePayment($oneTimeAmount, $currency, $isUsedWithSubscription = false)
    {
        if ($oneTimeAmount > 0)
        {
            if ($isUsedWithSubscription)
            {
                $productId = "one_time_payment";
                $name = __("One time payment");
            }
            else
            {
                $productId = "amount_due";
                $name = __("Amount due");
            }

            $metadata = [
                'Type' => 'RegularProductsTotal',
            ];

            $stripeAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($oneTimeAmount, $currency);

            $stripeProductModel = $this->stripeProductFactory->create()->fromData($productId, $name, $metadata);
            $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency);

            $lineItem = [
                'price' => $stripePriceModel->getId(),
                'quantity' => 1,
            ];

            return $lineItem;
        }

        return null;
    }

    protected function getRecurringPayment($subscription, $subscriptionsProductIDs, $allSubscriptionsTotal, $currency, $interval, $intervalCount)
    {
        if (!empty($subscription['profile']))
        {
            $profile = $subscription['profile'];

            $interval = $profile['interval'];
            $intervalCount = $profile['interval_count'];
            $currency = $profile['currency'];
            $magentoAmount = $this->subscriptionsHelper->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);
            $stripeAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

            if (!empty($subscription['quote_item']))
            {
                $stripeProductModel = $this->stripeProductFactory->create()->fromQuoteItem($subscription['quote_item']);
            }
            else if (!empty($subscription['order_item']))
            {
                $stripeProductModel = $this->stripeProductFactory->create()->fromOrderItem($subscription['order_item']);
            }
            else
            {
                throw new LocalizedException(__("Could not create subscription product in Stripe."));
            }

            $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency, $interval, $intervalCount);

            $lineItem = [
                'price' => $stripePriceModel->getId(),
                'quantity' => 1,

            ];

            return $lineItem;
        }

        return null;
    }

    private function getPaymentIntentData($quote, $order = null)
    {
        $params = [
            'capture_method' => $this->config->getCaptureMethod()
        ];

        if ($order)
        {
            $params["metadata"] = $this->config->getMetadata($order);
            $params['description'] = $this->orderHelper->getOrderDescription($order);
        }
        else
        {
            $params['description'] = $this->quoteHelper->getQuoteDescription($quote);
        }

        $futureUsage = $this->config->getSetupFutureUsage($quote);
        if ($futureUsage)
        {
            $params['setup_future_usage'] = $futureUsage;
        }

        $statementDescriptor = $this->config->getStatementDescriptor();
        if (!empty($statementDescriptor))
        {
            $params["statement_descriptor"] = $statementDescriptor;
        }

        $shipping = $this->getShippingAddressFrom($quote, $order);
        if ($shipping)
        {
            $params['shipping'] = $shipping;
        }

        $customerEmail = $quote->getCustomerEmail();
        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
        {
            $params["receipt_email"] = $customerEmail;
        }

        return $params;
    }

    public function getShippingAddressFrom($quote, $order = null)
    {
        if ($order)
            $obj = $order;
        else if ($quote)
            $obj = $quote;
        else
            throw new GenericException("No quote or order specified");

        if (!$obj || $obj->getIsVirtual())
            return null;

        $address = $obj->getShippingAddress();

        if (empty($address))
            return null;

        if (empty($address->getFirstname()))
            $address = $this->addressFactory->create()->load($address->getAddressId());

        if (empty($address->getFirstname()))
            return null;

        return $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
    }

    public function getOrder()
    {
        $orderIncrementId = $this->getOrderIncrementId();

        if (empty($orderIncrementId))
            return null;

        $order = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);
        if ($order && $order->getId())
            return $order;

        return null;
    }

    public function hasExpired()
    {
        if (!$this->getCheckoutSessionId() || !$this->stripeCheckoutSession)
            return false;

        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        return ($checkoutSession && $checkoutSession->status == "expired");
    }

    public function isComplete()
    {
        if (!$this->getCheckoutSessionId() || !$this->stripeCheckoutSession)
            return false;

        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        return ($checkoutSession && $checkoutSession->status == "complete");
    }

    public function cancelOrder($orderComment)
    {
        if (!$this->getOrderIncrementId())
        {
            throw new Exception("No order was placed for this Stripe Checkout session.");
        }

        $order = $this->getOrder();
        if (!$order || !$order->getId())
        {
            throw new Exception("Could not load order for Stripe Checkout session.");
        }

        $state = \Magento\Sales\Model\Order::STATE_CANCELED;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $order->addStatusToHistory($status, $orderComment, $isCustomerNotified = false);
        $this->orderHelper->removeTransactions($order);
        $this->orderHelper->saveOrder($order);
        $this->forgetOrder();
    }

    public function forgetOrder()
    {
        $this->setOrderIncrementId(null)->save();
    }

    public function cancelCheckoutSession()
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            return;
        }

        try
        {
            if ($this->canCancel())
            {
                $this->config->getStripeClient()->checkout->sessions->expire($checkoutSession->id, []);
                $this->setCheckoutSessionId(null)->save();
            }
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError("Cannot cancel checkout session: " . $e->getMessage());
        }
    }

    protected function canCancel()
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            return false;
        }

        if (in_array($checkoutSession->status, ["expired", "complete"]))
            return false;

        return true;
    }

    protected function isOneTimePayment($lineItem)
    {
        if (!empty($lineItem['price']['recurring']))
            return false;

        if (!empty($lineItem['price_data']['recurring']))
            return false;

        if (is_string($lineItem['price']))
        {
            $price = $this->config->getStripeClient()->prices->retrieve($lineItem['price'], []);
            if (!empty($price['recurring']))
                return false;
        }

        return true;
    }

    public function quoteIsDifferentFromOrder($quote)
    {
        $order = $this->getOrder();
        if (!$order)
        {
            throw new Exception("No order was placed for this Stripe Checkout session.");
        }

        // Check if the grand total is different between the quote and the order
        $this->cartInfo->setQuote($quote);
        if ($quote->getGrandTotal() != $order->getGrandTotal() && !$this->cartInfo->orderTotalIsDifferentThanQuoteTotal())
        {
            return true;
        }

        // Check if the currency is different between the quote and the order
        if ($quote->getQuoteCurrencyCode() != $order->getOrderCurrencyCode())
        {
            return true;
        }

        // Check if the order is a guest order but the quote is a registered customer
        if ($order->getCustomerIsGuest() && $quote->getCustomerIsGuest() == 0)
        {
            return true;
        }

        // Check if the order is a registered customer but the quote is a guest
        if ($order->getCustomerIsGuest() == 0 && $quote->getCustomerIsGuest())
        {
            return true;
        }

        // Check if the email is different between the quote and the order
        if ($quote->getCustomerEmail() != $order->getCustomerEmail())
        {
            return true;
        }

        // Check if the quote is virtual but the order is not, and vice versa
        if ($quote->getIsVirtual() != $order->getIsVirtual())
        {
            return true;
        }

        if (!$quote->getIsVirtual())
        {
            // Check if the shipping address is different between the quote and the order
            $quoteShippingData = $this->addressHelper->filterAddressDataAndRemoveEmpty($quote->getShippingAddress()->getData());
            $orderShippingData = $this->addressHelper->filterAddressDataAndRemoveEmpty($order->getShippingAddress()->getData());
            if (count($quoteShippingData) != count($orderShippingData))
            {
                return true;
            }
            else if ($this->compare->isDifferent($quoteShippingData, $orderShippingData))
            {
                return true;
            }

            // Check if the shipping method has changed
            if ($quote->getShippingAddress()->getShippingMethod() != $order->getShippingMethod())
            {
                return true;
            }
        }

        // Check if the billing address is different between the quote and the order.
        $quoteBillingData = $this->addressHelper->filterAddressDataAndRemoveEmpty($quote->getBillingAddress()->getData());
        $orderBillingData = $this->addressHelper->filterAddressDataAndRemoveEmpty($order->getBillingAddress()->getData());
        if (count($quoteBillingData) != count($orderBillingData))
        {
            return true;
        }
        else if ($this->compare->isDifferent($quoteBillingData, $orderBillingData))
        {
            return true;
        }

        // Check if the items are different
        $quoteItems = $quote->getAllVisibleItems();
        $orderItems = $order->getAllVisibleItems();
        if (count($quoteItems) != count($orderItems))
        {
            return true;
        }

        foreach ($quoteItems as $quoteItem)
        {
            $found = false;
            foreach ($orderItems as $orderItem)
            {
                if ($quoteItem->getProductId() == $orderItem->getProductId())
                {
                    $found = true;
                    break;
                }
            }

            if (!$found)
            {
                return true;
            }
        }

        return false;
    }

    public function canCancelOrder()
    {
        $order = $this->getOrder();
        if (!$order)
        {
            throw new Exception("No order was placed for this Stripe Checkout session.");
        }

        if (!$this->getCheckoutSessionId())
        {
            throw new Exception("No Stripe Checkout session found.");
        }

        if ($order->getState() == \Magento\Sales\Model\Order::STATE_CANCELED)
            return false;

        if ($order->getState() == \Magento\Sales\Model\Order::STATE_COMPLETE)
            return false;

        if ($order->getState() == \Magento\Sales\Model\Order::STATE_CLOSED)
            return false;

        if (!$this->stripeCheckoutSession)
        {
            $this->stripeCheckoutSession = $this->stripeCheckoutSessionFactory->create()->load($this->getCheckoutSessionId());
        }

        if ($this->isComplete())
            return false;

        return true;
    }
}
