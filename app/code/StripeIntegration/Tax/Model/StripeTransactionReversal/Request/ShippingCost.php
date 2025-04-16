<?php

namespace StripeIntegration\Tax\Model\StripeTransactionReversal\Request;

use StripeIntegration\Tax\Helper\Creditmemo;
use StripeIntegration\Tax\Helper\Tax;

class ShippingCost
{
    public const AMOUNT_FIELD_NAME = 'amount';
    public const AMOUNT_TAX_FIELD_NAME = 'amount_tax';

    private $amount;
    private $amountTax;
    private $shippingCostHelper;
    private $creditmemoHelper;
    private $taxHelper;

    public function __construct(
        \StripeIntegration\Tax\Helper\ShippingCost $shippingCostHelper,
        Creditmemo $creditmemoHelper,
        Tax $taxHelper
    )
    {
        $this->shippingCostHelper = $shippingCostHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->taxHelper = $taxHelper;
    }

    public function formOnlineData($creditMemo, $shippingItem)
    {
        $this->amount = $this->shippingCostHelper->getShippingCostForReversal($creditMemo);
        $this->amountTax = $this->shippingCostHelper->getShippingCostTaxForReversal($creditMemo);

        $shippingItem->checkRemainingValuesForRequest($this->amount, $this->amountTax);

        return $this;
    }

    public function formOfflineData($creditMemo, $invoice, $shippingItem)
    {
        // If a transaction has shipping data on it, it is the transaction which contains all the shipping.
        // Magento functions so that if you have multiple invoices, the first invoice will contain the shipping for
        // the whole order.
        if ($shippingItem->hasShippingTax() && $this->creditmemoHelper->hasShippingToRevert($creditMemo)) {
            $this->amount = $this->shippingCostHelper->getShippingCostForReversal($creditMemo);
            $this->amountTax = $this->shippingCostHelper->getShippingCostTaxForReversal($creditMemo);
        } else {
            $this->amount = 0;
            $this->amountTax = 0;
        }

        // Add to the amount to be reverted for the credit memo.
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isShippingTaxExclusive());

        $shippingItem->checkRemainingValuesForRequest($this->amount, $this->amountTax);
    }

    public function formCommandLineData($shippingItem, $shipping, $currency)
    {
        $this->clearData();
        if ($shippingItem && $shipping) {
            $remainingAmount = $shippingItem->getAmountRemaining();
            $stripeShippingToRevert = $this->shippingCostHelper->getAmount($shipping, $currency);
            $ratio = $stripeShippingToRevert / $remainingAmount;
            $taxToRevert = round($shippingItem->getAmountTaxRemaining() * $ratio);
            $this->amount = -$stripeShippingToRevert;
            $this->amountTax = -$taxToRevert;
        }
    }

    public function toArray()
    {
        return [
            self::AMOUNT_FIELD_NAME => $this->amount,
            self::AMOUNT_TAX_FIELD_NAME => $this->amountTax,
        ];
    }

    public function canIncludeInRequest()
    {
        if ($this->amount || $this->amountTax) {
            return true;
        }

        return false;
    }

    private function clearData()
    {
        $this->amount = 0;
        $this->amountTax = 0;
    }
}