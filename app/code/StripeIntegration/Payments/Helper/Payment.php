<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class Payment
{
    private $config;
    private $tokenHelper;
    private $stripePaymentIntentFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentFactory,
        \StripeIntegration\Payments\Helper\Token $tokenHelper
    )
    {
        $this->config = $config;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
        $this->tokenHelper = $tokenHelper;
    }

    public function getStripePaymentIntentModel($order): \StripeIntegration\Payments\Model\Stripe\PaymentIntent
    {
        $stripePaymentIntentModel = $this->stripePaymentIntentFactory->create();

        if (!$order->getPayment() || !$order->getPayment()->getLastTransId())
        {
            return $stripePaymentIntentModel;
        }

        $token = $order->getPayment()->getLastTransId();
        $token = $this->tokenHelper->cleanToken($token);
        if (!$this->tokenHelper->isPaymentIntentToken($token))
        {
            return $stripePaymentIntentModel;
        }

        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), null);
        return $stripePaymentIntentModel->fromPaymentIntentId($token);
    }
}