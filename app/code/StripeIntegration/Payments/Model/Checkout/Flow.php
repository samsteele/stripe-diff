<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Checkout;

class Flow
{
    public $isExpressCheckout = false;
    public $isFutureSubscriptionSetup = false;
    public $isPendingMicrodepositsVerification = false;
    public $creatingOrderFromCharge = null;
    public $isNewOrderBeingPlaced = false;
    public $isRecurringSubscriptionOrderBeingPlaced = false;
    public $isQuoteCorrupted = false;
    public $isCleaningExpiredOrders = false;
    public $isCheckoutSessionRecreated = false;
    private $disableZeroInitialPrices = false;

    public function shouldNotBillTrialSubscriptionItems()
    {
        return ($this->isNewOrderBeingPlaced || $this->isCheckoutSessionRecreated)
            && !$this->isRecurringSubscriptionOrderBeingPlaced
            && !$this->disableZeroInitialPrices;
    }

    public function disableZeroInitialPrices()
    {
        $this->disableZeroInitialPrices = true;
    }

    public function enableZeroInitialPrices()
    {
        $this->disableZeroInitialPrices = false;
    }
}