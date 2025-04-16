<?php

namespace StripeIntegration\Tax\Model\StripeTransactionReversal\Request\LineItems;

use StripeIntegration\Tax\Helper\Tax;
use StripeIntegration\Tax\Helper\Creditmemo;
use StripeIntegration\Tax\Helper\GiftOptions;
use StripeIntegration\Tax\Helper\LineItems;

class LineItem
{
    public const AMOUNT_FIELD_NAME = 'amount';
    public const AMOUNT_TAX_FIELD_NAME = 'amount_tax';
    public const ORIGINAL_LINE_ITEM_FIELD_NAME = 'original_line_item';
    public const REFERENCE_FIELD_NAME = 'reference';
    public const QUANTITY_FIELD_NAME = 'quantity';

    private $amount;
    private $amountTax;
    private $originalLineItem;
    private $reference;
    private $lineItemsHelper;
    private $quantity = 1;
    private $giftOptionsHelper;
    private $creditmemoHelper;
    private $taxHelper;

    public function __construct(
        LineItems $lineItemsHelper,
        GiftOptions $giftOptionsHelper,
        Creditmemo $creditmemoHelper,
        Tax $taxHelper
    )
    {
        $this->lineItemsHelper = $lineItemsHelper;
        $this->giftOptionsHelper = $giftOptionsHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->taxHelper = $taxHelper;
    }

    public function formData($item, $creditMemo, $transaction)
    {
        $amount = $this->lineItemsHelper->getAmount($item, $creditMemo->getOrderCurrencyCode());
        $amountTax = $this->lineItemsHelper->getStripeFormattedAmount($item->getTaxAmount(), $creditMemo->getOrderCurrencyCode());
        $this->amount = -$amount;
        $this->amountTax = -$amountTax;
        $this->quantity = $item->getQty();
        $this->reference = $this->lineItemsHelper->getReferenceForInvoiceTax($item, $creditMemo->getOrder());
        $this->processTransactionLineItem($transaction);
    }

    public function formOfflineData($item, $creditMemo, $revertedLineItem)
    {
        // Get the amount from the credit memo item and subtract the amount reverted from it for both price and shipping
        $amount = $this->lineItemsHelper->getAmount($item, $creditMemo->getOrderCurrencyCode());
        $amount -= $item->getAmountReverted();
        $amountTax = $this->lineItemsHelper->getStripeFormattedAmount($item->getTaxAmount(), $creditMemo->getOrderCurrencyCode());
        $amountTax -= $item->getAmountTaxReverted();
        $qty = $item->getQty() - $item->getQtyReverted();
        // If the amount or tax of the line item is larger than the amount which is refunded in the credit memo,
        // we add the amount for the item from the credit memo for a partial revert
        // Otherwise we add the amount of the line item as the amount to be reverted and mark the item as fully
        // reverted
        if ($revertedLineItem->getQtyRemaining() > $qty) {
            $this->amount = -$amount;
            $this->amountTax = -$amountTax;
            $this->quantity = $qty;
        } else {
            $this->amount = -$revertedLineItem->getAmountRemaining();
            $revertedLineItem->setRequestAmountFullRevert(true);
            $this->amountTax = -$revertedLineItem->getAmountTaxRemaining();
            $revertedLineItem->setRequestAmountTaxFullRevert(true);
            $this->quantity = $revertedLineItem->getQtyRemaining();
            $revertedLineItem->setRequestQtyFullRevert(true);
        }
        $item->setAmountReverted($item->getAmountReverted() + abs($this->amount));
        $item->setAmountTaxReverted($item->getAmountTaxReverted() + abs($this->amountTax));
        $item->setQtyReverted($item->getQtyReverted() + $this->quantity);

        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());

        $this->originalLineItem = $revertedLineItem->getStripeId();
        $this->reference = $this->lineItemsHelper->getReferenceForInvoiceTax($item, $creditMemo->getOrder());
        $this->quantity = $item->getQty();
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $item
     * @param $orderItem
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formItemGwData($item, $orderItem, $creditMemo, $transaction)
    {
        $amount = $this->giftOptionsHelper->getItemGiftOptionsAmount($orderItem, $creditMemo->getOrderCurrencyCode()) * $item->getQty();
        $amountTax = $this->lineItemsHelper->getStripeFormattedAmount($orderItem->getGwTaxAmount(), $creditMemo->getOrderCurrencyCode()) * $item->getQty();
        $this->amount = -$amount;
        $this->amountTax = -$amountTax;
        $this->quantity = $item->getQty();
        $this->reference = $this->giftOptionsHelper->getItemGwReferenceForInvoiceTax($item, $creditMemo->getOrder());
        $this->processTransactionLineItem($transaction);
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $item
     * @param $orderItem
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formOfflineItemGwData($item, $orderItem, $creditMemo, $transaction)
    {
        $this->formItemGwData($item, $orderItem, $creditMemo, $transaction);
        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formOrderGwData($creditMemo, $transaction)
    {
        $amount = $this->giftOptionsHelper->getSalseObjectGiftOptionsAmount($creditMemo->getOrder(), $creditMemo->getOrderCurrencyCode());
        $amountTax = $this->lineItemsHelper->getStripeFormattedAmount($creditMemo->getOrder()->getGwTaxAmount(), $creditMemo->getOrderCurrencyCode());
        $this->amount = -$amount;
        $this->amountTax = -$amountTax;
        $this->quantity = 1;
        $this->reference = $this->giftOptionsHelper->getSalesObjectGiftOptionsReference($creditMemo->getOrder());
        $this->processTransactionLineItem($transaction);
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formOfflineOrderGwData($creditMemo, $transaction)
    {
        $this->formOrderGwData($creditMemo, $transaction);
        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formOrderPrintedCardData($creditMemo, $transaction)
    {
        $amount = $this->giftOptionsHelper->getSalesObjectPrintedCardAmount($creditMemo->getOrder(), $creditMemo->getOrderCurrencyCode());
        $amountTax = $this->lineItemsHelper->getStripeFormattedAmount($creditMemo->getOrder()->getGwCardTaxAmount(), $creditMemo->getOrderCurrencyCode());
        $this->amount = -$amount;
        $this->amountTax = -$amountTax;
        $this->quantity = 1;
        $this->reference = $this->giftOptionsHelper->getSalesObjectPrintedCardReference($creditMemo->getOrder());
        $this->processTransactionLineItem($transaction);
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $creditMemo
     * @param $transaction
     * @return void
     */
    public function formOfflineOrderPrintedCardData($creditMemo, $transaction)
    {
        $this->formOrderPrintedCardData($creditMemo, $transaction);
        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());
    }

    public function formItemAdditionalFeeData($item, $creditMemo, $additionalFee, $transaction)
    {
        $this->quantity = $item->getQty();
        $this->amount = $this->lineItemsHelper->getStripeFormattedAmount(-$additionalFee['amount'], $creditMemo->getOrderCurrencyCode());
        $this->amountTax = $this->lineItemsHelper->getStripeFormattedAmount(-$additionalFee['amount_tax'], $creditMemo->getOrderCurrencyCode());
        $this->reference = $this->lineItemsHelper->getReferenceForInvoiceAdditionalFee($item, $creditMemo->getOrder(), $additionalFee['code']);
        $this->processTransactionLineItem($transaction);
    }

    public function formOfflineItemAdditionalFeeData($item, $creditMemo, $additionalFee, $transaction)
    {
        $this->formItemAdditionalFeeData($item, $creditMemo, $additionalFee, $transaction);
        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());
    }

    public function formCreditmemoAdditionalFeeData($creditMemo, $additionalFee, $transaction)
    {
        $this->quantity = 1;
        $this->amount = $this->lineItemsHelper->getStripeFormattedAmount(-$additionalFee['amount'], $creditMemo->getOrderCurrencyCode());
        $this->amountTax = $this->lineItemsHelper->getStripeFormattedAmount(-$additionalFee['amount_tax'], $creditMemo->getOrderCurrencyCode());
        $this->reference = $this->lineItemsHelper->getSalesEntityAdditionalFeeReference($creditMemo->getOrder(), $additionalFee['code']);
        $this->processTransactionLineItem($transaction);
    }

    public function formOfflineCreditmemoAdditionalFeeData($creditMemo, $additionalFee, $transaction)
    {
        $this->formCreditmemoAdditionalFeeData($creditMemo, $additionalFee, $transaction);
        // Add the amounts to the total to be reverted for the credit memo
        $this->creditmemoHelper->updateAmountToRevert($creditMemo, $this->amount, $this->amountTax, $this->taxHelper->isProductAndPromotionTaxExclusive());
    }

    public function formCommandLineData($lineItem, $quantity)
    {
        $this->quantity = $quantity;
        $this->amount = -($lineItem->getAmountRemaining() / $lineItem->getQtyRemaining() * $quantity);
        $this->amountTax = -($lineItem->getAmountTaxRemaining() / $lineItem->getQtyRemaining() * $quantity);
        $this->reference = $lineItem->getReference();
        $this->originalLineItem = $lineItem->getStripeId();
    }

    public function toArray()
    {
        return [
            self::AMOUNT_FIELD_NAME => $this->amount,
            self::AMOUNT_TAX_FIELD_NAME => $this->amountTax,
            self::ORIGINAL_LINE_ITEM_FIELD_NAME => $this->originalLineItem,
            self::REFERENCE_FIELD_NAME => $this->reference,
            self::QUANTITY_FIELD_NAME => $this->quantity,
        ];
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getAmountTax()
    {
        return $this->amountTax;
    }

    public function getReference()
    {
        return $this->reference;
    }

    private function processTransactionLineItem($transaction)
    {
        $lineItem = $transaction->getLineItemForReversalByReference($this->reference);
        $this->originalLineItem = $lineItem->getStripeId();
        $lineItem->checkRemainingValuesForRequest($this->amount, $this->amountTax);
    }
}