<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class PaymentIntentPartiallyFunded
{
    use StripeObjectTrait;

    private $webhooksHelper;
    private $helper;
    private $currencyHelper;
    private $convert;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Convert $convert
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->helper = $helper;
        $this->currencyHelper = $currencyHelper;
        $this->convert = $convert;
    }

    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $currency = $object['currency'];
        $totalAmount = $object['amount'];
        $remainingAmount = 0;
        if (!empty($object['next_action']['display_bank_transfer_instructions']['amount_remaining']))
        {
            $remainingAmount = $object['next_action']['display_bank_transfer_instructions']['amount_remaining'];
        }

        if ($remainingAmount == 0)
            return;

        $orderAmountPaid = $this->convert->stripeAmountToOrderAmount($totalAmount - $remainingAmount, $currency, $order);
        $orderAmountRemaining = $this->convert->stripeAmountToOrderAmount($remainingAmount, $currency, $order);
        $humanReadablePaidAmount = $this->currencyHelper->addCurrencySymbol($orderAmountPaid, $currency);
        $humanReadableRemainingAmount = $this->currencyHelper->addCurrencySymbol($orderAmountRemaining, $currency);

        $comment = __("Customer paid %1 using bank transfer. To complete payment, ask the customer to transfer %2 more.", $humanReadablePaidAmount, $humanReadableRemainingAmount);
        $this->webhooksHelper->addOrderComment($order, $comment);
    }
}