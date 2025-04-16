<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Cart;

class Info
{
    private $checkoutFlow;
    private $subscriptionProductFactory;

    // Data
    private $quote = null;

    // State
    private $hasSubscriptions = false;
    private $hasTrialSubscriptions = false;
    private $hasStartDateSubscriptions = false; // These may start today, or in the future
    private $hasFutureStartDateSubscriptions = false; // These start in the future
    private $hasRegularProducts = false;
    private $hasGiftCards = false;

    // True when no payment will be collected today, regardless of whether the cart includes subscriptions or not
    private $isZeroTotal = true;

    public function __construct(
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory
    )
    {
        $this->checkoutFlow = $checkoutFlow;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
    }

    private function reset()
    {
        $this->hasSubscriptions = false;
        $this->hasTrialSubscriptions = false;
        $this->hasStartDateSubscriptions = false;
        $this->hasFutureStartDateSubscriptions = false;
        $this->hasRegularProducts = false;
        $this->isZeroTotal = true;
    }

    public function setQuote($quote)
    {
        if ($quote == $this->quote)
        {
            return;
        }
        else
        {
            $this->quote = $quote;
            $this->reset();
        }

        foreach ($quote->getAllItems() as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                $this->hasSubscriptions = true;

                if ($subscriptionProductModel->hasTrialPeriod())
                {
                    $this->hasTrialSubscriptions = true;
                }

                if ($subscriptionProductModel->startsOnStartDate())
                {
                    $this->hasStartDateSubscriptions = true;

                    if (!$subscriptionProductModel->startDateIsToday())
                    {
                        $this->hasFutureStartDateSubscriptions = true;
                        $this->checkoutFlow->isFutureSubscriptionSetup = true;
                    }
                }

                if (!$subscriptionProductModel->hasZeroInitialOrderPrice())
                {
                    // todo: a subscription's price could be zero
                    $this->isZeroTotal = false;
                }
            }
            else
            {
                $this->hasRegularProducts = true;

                if ($item->getRowTotal() > 0)
                {
                    $this->isZeroTotal = false;
                }
            }

            if ($item->getProductType() == 'giftcard')
            {
                $this->hasGiftCards = true;
            }
        }
    }

    public function hasSubscriptions()
    {
        return $this->hasSubscriptions;
    }

    public function hasTrialSubscriptions()
    {
        return $this->hasTrialSubscriptions;
    }

    public function hasStartDateSubscriptions()
    {
        return $this->hasStartDateSubscriptions;
    }

    public function hasFutureStartDateSubscriptions()
    {
        return $this->hasFutureStartDateSubscriptions;
    }

    public function hasRegularProducts()
    {
        return $this->hasRegularProducts;
    }

    public function hasGiftCards()
    {
        return $this->hasGiftCards;
    }

    public function isZeroTotal()
    {
        return $this->isZeroTotal;
    }

    public function isZeroTotalSubscriptionSetup()
    {
        return $this->isZeroTotal &&
            ($this->hasTrialSubscriptions || $this->hasFutureStartDateSubscriptions);
    }

    public function orderTotalIsDifferentThanQuoteTotal()
    {
        return $this->hasTrialSubscriptions || $this->hasFutureStartDateSubscriptions;
    }
}