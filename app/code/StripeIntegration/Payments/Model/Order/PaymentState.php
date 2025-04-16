<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Order;

class PaymentState
{
    private $paymentIntentHelper;
    private $paymentIntent = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper
    ) {
        $this->paymentIntentHelper = $paymentIntentHelper;
    }

    public function setConfirmedPaymentIntent($paymentIntent)
    {
        $this->paymentIntent = $paymentIntent;
    }

    public function isPaid()
    {
        if ($this->paymentIntent)
        {
            return $this->paymentIntentHelper->isSuccessful($this->paymentIntent) ||
                $this->paymentIntentHelper->isAsyncProcessing($this->paymentIntent) ||
                $this->paymentIntentHelper->requiresOfflineAction($this->paymentIntent);
        }

        return false;
    }

    public function getPaymentUrl()
    {
        if ($this->paymentIntent)
        {
            $livemode = $this->paymentIntent->livemode;
            if ($livemode)
            {
                $url = "https://dashboard.stripe.com/payments/" . $this->paymentIntent->id;
            }
            else
            {
                $url = "https://dashboard.stripe.com/test/payments/" . $this->paymentIntent->id;
            }

            return $url;
        }

        return null;
    }

    public function getPaymentIntent()
    {
        return $this->paymentIntent;
    }
}