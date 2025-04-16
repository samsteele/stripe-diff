<?php

namespace StripeIntegration\Payments\Helper;

class InitialFee
{
    private $checkoutSessionHelper;
    private $subscriptionProductFactory;
    private $paymentMethodHelper;
    private $currencyHelper;
    private $checkoutFlow;

    public function __construct(
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper
    ) {
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->checkoutFlow = $checkoutFlow;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->currencyHelper = $currencyHelper;
    }

    public function getTotalInitialFeeForCreditmemo($creditmemo, $orderRate = true)
    {
        $payment = $creditmemo->getOrder()->getPayment();

        $defaults = [
            "initial_fee" => 0,
            "base_initial_fee" => 0
        ];

        if (empty($payment))
            return $defaults;

        if (!$this->paymentMethodHelper->supportsSubscriptions($payment->getMethod()))
            return $defaults;

        if ($payment->getAdditionalInformation("is_recurring_subscription") || $payment->getAdditionalInformation("remove_initial_fee"))
            return $defaults;

        $currencyPrecision = $this->currencyHelper->getCurrencyPrecision($creditmemo->getOrder()->getOrderCurrencyCode());
        $items = $creditmemo->getAllItems();

        $total = 0;
        $baseTotal = 0;
        foreach ($items as $item)
        {
            $orderItem = $item->getOrderItem();

            // When getting the initial fee
            // 1. Parent products do not have any initial fee.
            // 2. The initial fee includes the QTY calculation.
            // 3. The getInitialFee() amount is in the order currency.
            $qtyRefunded = $item->getQty();
            $qtyOrdered = $orderItem->getQtyOrdered();
            if ($qtyRefunded > 0 && $qtyRefunded <= $qtyOrdered)
            {
                $proportion = $qtyRefunded / $qtyOrdered;
                $total += round($orderItem->getInitialFee() * $proportion, $currencyPrecision);
                $baseTotal += round($orderItem->getBaseInitialFee() * $proportion, $currencyPrecision);
            }
        }

        return [
            "initial_fee" => $total,
            "base_initial_fee" => $baseTotal
        ];
    }

    public function getTotalInitialFeeTaxForCreditmemo($creditmemo)
    {
        $payment = $creditmemo->getOrder()->getPayment();

        if (empty($payment))
            return 0;

        if (!$this->paymentMethodHelper->supportsSubscriptions($payment->getMethod()))
            return 0;

        if ($payment->getAdditionalInformation("is_recurring_subscription") || $payment->getAdditionalInformation("remove_initial_fee"))
            return 0;

        return $this->getInitialFeeTaxForCreditmemoItems($creditmemo);
    }

    public function getTotalInitialFeeForInvoice($invoice, $invoiceRate = true)
    {
        $payment = $invoice->getOrder()->getPayment();

        if (empty($payment))
            return 0;

        if (!$this->paymentMethodHelper->supportsSubscriptions($payment->getMethod()))
            return 0;

        if ($payment->getAdditionalInformation("is_recurring_subscription") || $payment->getAdditionalInformation("remove_initial_fee"))
            return 0;

        $items = $invoice->getAllItems();

        if ($invoiceRate)
            $rate = $invoice->getBaseToOrderRate();
        else
            $rate = 1;

        return $this->getInitialFeeForItems($items, $rate);
    }

    public function getTotalInitialFeeForOrder($filteredOrderItems, $order): array
    {
        if ($this->checkoutFlow->isRecurringSubscriptionOrderBeingPlaced || $order->getRemoveInitialFee() || $order->getPayment()->getAdditionalInformation("is_recurring_subscription")) {
            return [
                "initial_fee" => 0,
                "base_initial_fee" => 0
            ];
        }

        if ($this->checkoutSessionHelper->isSubscriptionUpdate()) {
            return [
                "initial_fee" => 0,
                "base_initial_fee" => 0
            ];
        }

        if ($this->checkoutSessionHelper->isSubscriptionReactivate()) {
            return [
                "initial_fee" => 0,
                "base_initial_fee" => 0
            ];
        }

        $baseTotal = $total = 0;

        foreach ($filteredOrderItems as $orderItem)
        {
            if ($orderItem->getInitialFee() > 0)
            {
                // From 3.4.0 onwards, the initial fee is saved on the order item
                $total += $orderItem->getInitialFee();
                $baseTotal += $orderItem->getBaseInitialFee();
            }
        }

        return [
            "initial_fee" => $total,
            "base_initial_fee" => $baseTotal
        ];
    }

    public function getTotalInitialFeeFor($items, $quote, $quoteRate = 1)
    {
        if ($this->checkoutFlow->isRecurringSubscriptionOrderBeingPlaced || $quote->getRemoveInitialFee())
            return 0;

        return $this->getInitialFeeForItems($items, $quoteRate);
    }

    public function getInitialFeeForItems($items, $rate)
    {
        if ($this->checkoutSessionHelper->isSubscriptionUpdate())
            return 0;

        if ($this->checkoutSessionHelper->isSubscriptionReactivate())
            return 0;

        $total = 0;

        foreach ($items as $item)
        {
            $productId = $item->getProductId();
            $qty = $this->getItemQty($item, $productId);
            $total += $this->getInitialFeeForProductId($productId, $rate, $qty);
        }

        return $total;
    }

    public function getInitialFeeTaxForCreditmemoItems($creditmemo)
    {
        if ($this->checkoutSessionHelper->isSubscriptionUpdate())
            return 0;

        if ($this->checkoutSessionHelper->isSubscriptionReactivate())
            return 0;

        $totalInitialFeeTax = 0;
        $totalInitialFeeBaseTax = 0;
        $currencyPrecision = $this->currencyHelper->getCurrencyPrecision($creditmemo->getOrder()->getOrderCurrencyCode());

        foreach ($creditmemo->getAllItems() as $item)
        {
            $orderItem = $item->getOrderItem();
            $qtyRefunded = $item->getQty();
            $qtyOrdered = $orderItem->getQtyOrdered();
            if ($qtyRefunded > 0 && $qtyRefunded <= $qtyOrdered)
            {
                $proportion = $qtyRefunded / $qtyOrdered;
                $orderInitialFeeTax = $orderItem->getInitialFeeTax();
                $orderBaseInitialFeeTax = $orderItem->getBaseInitialFeeTax();

                $totalInitialFeeTax += round($orderInitialFeeTax * $proportion, $currencyPrecision);
                $totalInitialFeeBaseTax += round($orderBaseInitialFeeTax * $proportion, $currencyPrecision);
            }
        }

        return ['tax' => $totalInitialFeeTax, 'base_tax' => $totalInitialFeeBaseTax];
    }

    private function getInitialFeeForProductId($productId, $rate, $qty)
    {
        $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromProductId($productId);

        if (!$subscriptionProductModel->isSubscriptionProduct())
            return 0;

        return $subscriptionProductModel->getInitialFeeAmount($qty, $rate);
    }

    public function getAdditionalOptionsForQuoteItem($quoteItem, $currencyCode = null)
    {
        if (!empty($quoteItem->getQtyOptions()))
        {
            return $this->getAdditionalOptionsForChildrenOf($quoteItem, $currencyCode);
        }
        else
        {
            return $this->getAdditionalOptionsForProductId($quoteItem->getProductId(), $quoteItem, $currencyCode);
        }
    }

    private function getAdditionalOptionsForChildrenOf($item, $currencyCode)
    {
        $additionalOptions = [];

        foreach ($item->getQtyOptions() as $productId => $option)
        {
            $additionalOptions = array_merge($additionalOptions, $this->getAdditionalOptionsForProductId($productId, $item, $currencyCode));
        }

        return $additionalOptions;
    }

    private function getAdditionalOptionsForProductId($productId, $quoteItem, $currencyCode)
    {
        $qty = $this->getItemQty($quoteItem, $productId);

        $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromProductId($productId);
        if (!$subscriptionProductModel->isSubscriptionProduct())
            return [];

        $additionalOptions = [
            [
                'label' => 'Repeats Every',
                'value' => $subscriptionProductModel->getFormattedInterval()
            ]
        ];

        $initialFee = $subscriptionProductModel->getInitialFeeAmount($qty, null, $currencyCode);

        if ($initialFee > 0)
        {
            $additionalOptions[] = [
                'label' => 'Initial Fee',
                'value' => $this->currencyHelper->addCurrencySymbol($initialFee, $currencyCode)
            ];
        }

        $trialDays = $subscriptionProductModel->getTrialDays();

        if ($trialDays > 0)
        {
            $additionalOptions[] = [
                'label' => 'Trial Period',
                'value' => $trialDays . " days"
            ];
        }

        return $additionalOptions;
    }

    public function getItemQty($item, $productId)
    {
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());

        if ($item->getParentItem())
        {
            // The child product was passed
            $parentProductType = $item->getParentItem()->getProductType();
            if (in_array($parentProductType, ["configurable", "bundle"]))
            {
                $parentQty = max(/* quote */ $item->getParentItem()->getQty(), /* order */ $item->getParentItem()->getQtyOrdered());
                if (is_numeric($parentQty))
                    $qty *= $parentQty;
            }
        }
        else if (!empty($item->getQtyOptions()))
        {
            // The parent product was passed
            foreach ($item->getQtyOptions() as $qtyProductId => $option)
            {
                if ($qtyProductId == $productId)
                {
                    $qty *= $option->getValue();
                    break;
                }
            }
        }

        return $qty;
    }
}
