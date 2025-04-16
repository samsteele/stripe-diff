<?php

namespace StripeIntegration\Tax\Model\StripeTransactionReversal\Request;

use StripeIntegration\Tax\Helper\GiftOptions;
use StripeIntegration\Tax\Helper\Logger;
use StripeIntegration\Tax\Model\Config;
use StripeIntegration\Tax\Model\StripeTransactionReversal\Request\LineItems\LineItem;
use StripeIntegration\Tax\Helper\Order;
use Stripe\Collection;
use Magento\Framework\Event\ManagerInterface;
use StripeIntegration\Tax\Model\AdditionalFees\ItemAdditionalFees;
use StripeIntegration\Tax\Model\AdditionalFees\SalesEntityAdditionalFees;

class LineItems
{
    private $data = [];
    private $lineItem;
    private $config;
    private $logger;
    private $giftOptionsHelper;
    private $includeOrderGW;
    private $includeOrderPrintedCard;
    private $orderHelper;
    private $lineItemsData;
    private $lineItemsHelper;
    private $eventManager;
    private $itemAdditionalFees;
    private $salesEntityAdditionalFees;

    public function __construct(
        LineItem $lineItem,
        Config $config,
        Logger $logger,
        GiftOptions $giftOptionsHelper,
        Order $orderHelper,
        \StripeIntegration\Tax\Helper\LineItems $lineItemsHelper,
        ManagerInterface $eventManager,
        ItemAdditionalFees $itemAdditionalFees,
        SalesEntityAdditionalFees $salesEntityAdditionalFees
    )
    {
        $this->lineItem = $lineItem;
        $this->config = $config;
        $this->logger = $logger;
        $this->giftOptionsHelper = $giftOptionsHelper;
        $this->includeOrderGW = false;
        $this->includeOrderPrintedCard = false;
        $this->orderHelper = $orderHelper;
        $this->lineItemsHelper = $lineItemsHelper;
        $this->eventManager = $eventManager;
        $this->itemAdditionalFees = $itemAdditionalFees;
        $this->salesEntityAdditionalFees = $salesEntityAdditionalFees;
    }

    private function clearData()
    {
        $this->data = [];
    }
    public function formOnlineData($creditMemo, $transaction)
    {
        $this->clearData();
        if ($transaction->hasLineItemsForReversal()) {
            foreach ($creditMemo->getAllItems() as $item) {
                $orderItem = $item->getOrderItem();
                if ($orderItem->isDummy() || $item->getQty() <= 0) {
                    // in case the order item is a bundle dynamic product, add the GW options to the request before skipping
                    if ($orderItem->getHasChildren() &&
                        $orderItem->isChildrenCalculated()

                    ) {
                        if ($this->giftOptionsHelper->itemHasGiftOptions($orderItem)) {
                            $this->lineItem->formItemGwData($item, $orderItem, $creditMemo, $transaction);
                            $this->data[] = $this->lineItem->toArray();
                        }

                        $this->handleOnlineItemAdditionalFee($item, $creditMemo, $transaction);
                    }
                    continue;
                }
                $this->lineItem->formData($item, $creditMemo, $transaction);
                $this->data[] = $this->lineItem->toArray();
                if ($this->giftOptionsHelper->itemHasGiftOptions($orderItem)) {
                    $this->lineItem->formItemGwData($item, $orderItem, $creditMemo, $transaction);
                    $this->data[] = $this->lineItem->toArray();
                }

                $this->handleOnlineItemAdditionalFee($item, $creditMemo, $transaction);
            }
            if ($this->includeOrderGW) {
                $this->lineItem->formOrderGwData($creditMemo, $transaction);
                $this->data[] = $this->lineItem->toArray();
            }
            if ($this->includeOrderPrintedCard) {
                $this->lineItem->formOrderPrintedCardData($creditMemo, $transaction);
                $this->data[] = $this->lineItem->toArray();
            }

            $this->handleOnlineCreditmemoAdditionalFee($creditMemo, $transaction);
        }
    }

    public function formOfflineData($creditMemo, $invoice, $transaction)
    {
        $this->clearData();

        // Start going through the items only if the transaction has line items or the credit memo hasn't met
        // the amount necessary to be reverted
        if ($transaction->hasLineItemsForReversal() && $creditMemo->getGrandTotal() > $creditMemo->getAmountToRevert()) {
            foreach ($creditMemo->getAllItems() as $item) {
                $orderItem  = $item->getOrderItem();
                if (!$item->getAmountReverted()) {
                    $item->setAmountReverted(0);
                }
                if (!$item->getAmountTaxReverted()) {
                    $item->setAmountTaxReverted(0);
                }
                if (!$item->getQtyReverted()) {
                    $item->setQtyReverted(0);
                }
                $reference = $this->lineItemsHelper->getReferenceForInvoiceTax($item, $creditMemo->getOrder());
                $revertedLineItem = $transaction->getLineItemForReversalByReference($reference);
                // If the credit memo item is not found in the transaction items or
                // if there is no more amount available for the item to revert from this transaction or
                // if the credit memo item is already reverted in previous transactions, go to the next credit memo item
                if ($orderItem->isDummy() ||
                    $item->getQty() <= 0 ||
                    !$revertedLineItem ||
                    ($this->lineItemsHelper->getAmount($item, $creditMemo->getOrderCurrencyCode()) <= $item->getAmountReverted() &&
                        $this->lineItemsHelper->getStripeFormattedAmount($item->getTaxAmount(), $creditMemo->getOrderCurrencyCode()) <= $item->getAmountTaxReverted())
                ) {
                    // in case the order item is a bundle dynamic product, add the GW options to the request before skipping
                    if ($orderItem->getHasChildren() &&
                        $orderItem->isChildrenCalculated()
                    ) {
                        if ($this->giftOptionsHelper->itemHasGiftOptions($orderItem)) {
                            $this->lineItem->formOfflineItemGwData($item, $orderItem, $creditMemo, $transaction);
                            $this->processAdditionalFeeLineItem($transaction, false);
                        }

                        $this->handleOfflineItemAdditionalFee($item, $creditMemo, $transaction);
                    }
                    continue;
                }
                $this->lineItem->formOfflineData($item, $creditMemo, $revertedLineItem);
                $this->data[] = $this->lineItem->toArray();
                $transaction->addItemProcessed();

                if ($this->giftOptionsHelper->itemHasGiftOptions($orderItem)) {
                    $this->lineItem->formOfflineItemGwData($item, $orderItem, $creditMemo, $transaction);
                    $this->processAdditionalFeeLineItem($transaction);
                }

                $this->handleOfflineItemAdditionalFee($item, $creditMemo, $transaction);

                // If all the items in the transaction are processed or the total to be refunded is reached, stop the
                // loop through the credit memo items
                if ($transaction->isProcessed() || ($creditMemo->getGrandTotal() == $creditMemo->getAmountToRevert())) {
                    break;
                }
            }
            if ($this->includeOrderGW && $this->giftOptionsHelper->invoiceHasGw($invoice)) {
                $this->lineItem->formOfflineOrderGwData($creditMemo, $transaction);
                $this->processAdditionalFeeLineItem($transaction);
            }
            if ($this->includeOrderPrintedCard && $this->giftOptionsHelper->invoiceHasPrintedCard($invoice)) {
                $this->lineItem->formOfflineOrderPrintedCardData($creditMemo, $transaction);
                $this->processAdditionalFeeLineItem($transaction);
            }

            $this->handleOfflineCreditmemoAdditionalFee($creditMemo, $transaction);
        }
    }

    public function formCommandLineData($lineItem, $quantity, $additionalFeesItems)
    {
        $this->clearData();
        $itemForAdditionalFeesCheck = null;
        if (is_array($lineItem)) {
            foreach ($lineItem as $item) {
                $this->lineItem->formCommandLineData($item, $quantity);
                $this->data[] = $this->lineItem->toArray();
                $itemForAdditionalFeesCheck = $item;
            }
        } else {
            $this->lineItem->formCommandLineData($lineItem, $quantity);
            $this->data[] = $this->lineItem->toArray();
            $itemForAdditionalFeesCheck = $lineItem;
        }

        if (isset($additionalFeesItems[$itemForAdditionalFeesCheck->getTransactionId()])) {
            foreach ($additionalFeesItems[$itemForAdditionalFeesCheck->getTransactionId()] as $item) {
                if ($item->getQty() == 1 && $quantity > 1) {
                    $this->lineItem->formCommandLineData($item, 1);
                } else {
                    $this->lineItem->formCommandLineData($item, $quantity);
                }

                $this->data[] = $this->lineItem->toArray();
            }
        }
    }

    public function hasRemainingAmount()
    {
        foreach ($this->lineItemsData as $lineItem) {
            if ($lineItem['remaining_amount'] > 0 && $lineItem['remaining_amount_tax'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function toArray()
    {
        return $this->data;
    }

    public function canIncludeInRequest()
    {
        return !empty($this->data);
    }

    public function setIncludeOrderGW($includeOrderGW)
    {
        $this->includeOrderGW = $includeOrderGW;

        return $this;
    }

    public function setIncludeOrderPrintedCard($includeOrderPrintedCard)
    {
        $this->includeOrderPrintedCard = $includeOrderPrintedCard;

        return $this;
    }

    private function initLineItemsData($creditMemo, $transaction)
    {
        $lineItemsData = $this->orderHelper->getReversalLineItemsData($creditMemo->getOrder(), $transaction->getId());
        if (!$lineItemsData) {
            $lineItemsData = $transaction->formLineItemsData();
        }

        $this->lineItemsData = $lineItemsData;
    }

    public function getLineItemsData()
    {
        return $this->lineItemsData;
    }

    private function processAdditionalFeeLineItem($transaction, $canAddItemAsProcessed = true)
    {
        $this->data[] = $this->lineItem->toArray();
        if ($canAddItemAsProcessed) {
            $transaction->addItemProcessed();
        }
    }

    private function updateRemainingAmounts($reference, $amountReverted, $taxAmountReverted)
    {
        $this->lineItemsData[$reference]['remaining_amount'] = $this->lineItemsData[$reference]['remaining_amount'] + $amountReverted;
        $this->lineItemsData[$reference]['remaining_amount_tax'] = $this->lineItemsData[$reference]['remaining_amount_tax'] + $taxAmountReverted;
    }

    private function itemHasRemainingAmount($reference)
    {
        return $this->lineItemsData[$reference]['remaining_amount'] > 0 || $this->lineItemsData[$reference]['remaining_amount_tax'] > 0;
    }

    private function handleOnlineItemAdditionalFee($item, $creditMemo, $transaction)
    {
        $this->eventManager->dispatch(
            'stripe_tax_additional_fee_creditmemo_item',
            [
                'item' => $item,
                'creditmemo' => $creditMemo,
                'invoice' => $creditMemo->getInvoice(),
                'order' => $creditMemo->getOrder(),
                'additional_fees_container' => $this->itemAdditionalFees->clearValues()
            ]
        );
        foreach ($this->itemAdditionalFees->getAdditionalFees() as $additionalFee) {
            $this->lineItem->formItemAdditionalFeeData($item, $creditMemo, $additionalFee, $transaction);
            $this->data[] = $this->lineItem->toArray();
        }
    }

    private function handleOnlineCreditmemoAdditionalFee($creditMemo, $transaction)
    {
        $this->eventManager->dispatch(
            'stripe_tax_additional_fee_creditmemo',
            [
                'creditmemo' => $creditMemo,
                'invoice' => $creditMemo->getInvoice(),
                'order' => $creditMemo->getOrder(),
                'additional_fees_container' => $this->salesEntityAdditionalFees->clearValues()
            ]
        );
        foreach ($this->salesEntityAdditionalFees->getAdditionalFees() as $additionalFee) {
            $this->lineItem->formCreditmemoAdditionalFeeData($creditMemo, $additionalFee, $transaction);
            $this->data[] = $this->lineItem->toArray();
        }
    }

    private function handleOfflineItemAdditionalFee($item, $creditMemo, $transaction)
    {
        $this->eventManager->dispatch(
            'stripe_tax_additional_fee_creditmemo_item',
            [
                'item' => $item,
                'creditmemo' => $creditMemo,
                'invoice' => $creditMemo->getInvoice(),
                'order' => $creditMemo->getOrder(),
                'additional_fees_container' => $this->itemAdditionalFees->clearValues()
            ]
        );
        foreach ($this->itemAdditionalFees->getAdditionalFees() as $additionalFee) {
            $this->lineItem->formOfflineItemAdditionalFeeData($item, $creditMemo, $additionalFee, $transaction);
            $this->processAdditionalFeeLineItem($transaction);
        }
    }

    private function handleOfflineCreditmemoAdditionalFee($creditMemo, $transaction)
    {
        $this->eventManager->dispatch(
            'stripe_tax_additional_fee_creditmemo',
            [
                'creditmemo' => $creditMemo,
                'invoice' => $creditMemo->getInvoice(),
                'order' => $creditMemo->getOrder(),
                'additional_fees_container' => $this->salesEntityAdditionalFees->clearValues()
            ]
        );
        foreach ($this->salesEntityAdditionalFees->getAdditionalFees() as $additionalFee) {
            $this->lineItem->formOfflineCreditmemoAdditionalFeeData($creditMemo, $additionalFee, $transaction);
            $this->processAdditionalFeeLineItem($transaction);
        }
    }
}
