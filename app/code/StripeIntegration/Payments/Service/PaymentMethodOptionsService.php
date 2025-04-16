<?php

namespace StripeIntegration\Payments\Service;

use StripeIntegration\Payments\Exception\GenericException;
use StripeIntegration\Payments\Api\PaymentMethodOptionsServiceInterface;

class PaymentMethodOptionsService implements PaymentMethodOptionsServiceInterface
{
    private $paymentMethodHelper;
    private $areaCodeHelper;
    private $config;
    private $quote = null;
    private $savePaymentMethod = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper
    )
    {
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->config = $config;
    }

    public function setQuote($quote) : PaymentMethodOptionsServiceInterface
    {
        $this->quote = $quote;
        return $this;
    }

    public function setSavePaymentMethod($savePaymentMethod) : PaymentMethodOptionsServiceInterface
    {
        $this->savePaymentMethod = $savePaymentMethod;
        return $this;
    }

    public function getPaymentMethodOptions() : array
    {
        if (empty($this->quote))
            throw new GenericException("PaymentMethodOptions unavailable: Quote is not set");

        $sfuOptions = $captureOptions = [];

        if ($this->areaCodeHelper->isAdmin() && $this->savePaymentMethod)
        {
            $setupFutureUsage = "on_session";
        }
        else if ($this->savePaymentMethod === false)
        {
            $setupFutureUsage = "none";
        }
        else
        {
            // Get the default setting
            $setupFutureUsage = $this->config->getSetupFutureUsage($this->quote);
        }

        if ($setupFutureUsage)
        {
            $value = ["setup_future_usage" => $setupFutureUsage];

            $sfuOptions['card'] = $value;

            $canBeSaved = $this->paymentMethodHelper->getPaymentMethodsThatCanBeSaved();
            foreach ($canBeSaved as $code)
            {
                $sfuOptions[$code] = $value;
            }

            if ($setupFutureUsage == "on_session")
            {
                // The following methods do not display if we request an on_session setup
                $value = ["setup_future_usage" => "off_session"];
                $canBeSavedOffSession = $this->paymentMethodHelper->getPaymentMethodsThatCanOnlyBeSavedOffSession();
                foreach ($canBeSavedOffSession as $code)
                {
                    $sfuOptions[$code] = $value;
                }
            }
        }

        if ($this->config->isAuthorizeOnly())
        {
            $value = [ "capture_method" => "manual" ];

            $methodCodes = $this->paymentMethodHelper->getPaymentMethodsThatCanCaptureManually();

            foreach ($methodCodes as $methodCode)
            {
                $captureOptions[$methodCode] = $value;
            }
        }

        $wechatOptions["wechat_pay"]["client"] = 'web';

        if ($this->config->isOvercaptureEnabled())
        {
            $overcaptureOptions["card"]["request_overcapture"] = "if_available";
        }
        else
        {
            $overcaptureOptions = [];
        }

        if ($this->config->isMulticaptureEnabled())
        {
            $multiCaptureOptions = [
                'card' => [
                    'request_multicapture' => 'if_available'
                ]
            ];
        }
        else
        {
            $multiCaptureOptions = [];
        }

        return array_merge_recursive($sfuOptions, $captureOptions, $wechatOptions, $overcaptureOptions, $multiCaptureOptions);
    }

    public function getPaymentElementTerms(): array
    {
        $terms = [];
        $options = $this->getPaymentMethodOptions();

        foreach ($options as $code => $values)
        {
            switch ($code)
            {
                case "card":
                    if ($this->hasSaveOption($values))
                    {
                        $terms["card"] = "always";
                        $terms["applePay"] = "always";
                        $terms["googlePay"] = "always";
                        $terms["paypal"] = "always";
                    }
                    break;
                case "au_becs_debit":
                case "bancontact":
                case "cashapp":
                case "ideal":
                case "paypal":
                case "sepa_debit":
                case "sofort":
                case "us_bank_account":
                    $camelCaseCode = $this->snakeCaseToCamelCase($code);
                    $terms[$camelCaseCode] = "always";
                    break;
                default:
                    break;
            }
        }

        return $terms;
    }

    private function hasSaveOption($options)
    {
        if (!isset($options["setup_future_usage"]))
            return false;

        if (in_array($options["setup_future_usage"], ["on_session", "off_session"]))
            return true;

        return false;
    }

    private function snakeCaseToCamelCase($string)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
    }
}