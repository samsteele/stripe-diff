<?php

namespace StripeIntegration\Tax\Model\StripeTransactionReversal;

use StripeIntegration\Tax\Helper\Currency;
use StripeIntegration\Tax\Helper\DateTime;
use StripeIntegration\Tax\Model\StripeTransactionReversal\Request\LineItems;
use StripeIntegration\Tax\Model\StripeTransactionReversal\Request\ShippingCost;

class Request
{
    public const ORIGINAL_TRANSACTION_FIELD_NAME = 'original_transaction';
    public const REFERENCE_FIELD_NAME = 'reference';
    public const MODE_FIELD_NAME = 'mode';
    public const EXPAND_FIELD_NAME = 'expand';
    public const SHIPPING_COST_FIELD_NAME = 'shipping_cost';
    public const LINE_ITEMS_FIELD_NAME = 'line_items';
    public const MODE_FULL = 'full';
    public const MODE_PARTIAL = 'partial';

    private $mode;
    private $originalTransaction;
    private $reference;
    private $expand;
    private $shippingCost;
    private $lineItems;
    private $currencyHelper;
    private $dateTimeHelper;

    public function __construct(
        ShippingCost $shippingCost,
        LineItems $lineItems,
        Currency $currencyHelper,
        DateTime $dateTimeHelper
    )
    {
        $this->shippingCost = $shippingCost;
        $this->lineItems = $lineItems;
        $this->currencyHelper = $currencyHelper;
        $this->dateTimeHelper = $dateTimeHelper;
    }

    /**
     * Checks if the credit memo is for all the invoiced amount of the order
     *
     * @param $creditMemo
     * @return bool
     */
    public function isCreditmemoPartial($creditMemo)
    {
        $order = $creditMemo->getOrder();
        if ($creditMemo->getGrandTotal() != $order->getTotalInvoiced()) {
            return true;
        }

        return false;
    }

    public function isPartial()
    {
        return $this->mode === self::MODE_PARTIAL;
    }

    public function formData($creditMemo, $transaction, $invoice = null)
    {
        $this->mode = self::MODE_FULL;
        $this->expand = ['line_items'];
        if ($invoice) {
            $this->originalTransaction = $invoice->getStripeTaxTransactionId();
            $this->reference = sprintf('%s_%s_%s', $creditMemo->getIncrementId(), $invoice->getIncrementId(), $this->dateTimeHelper->getTimestampInMilliseconds());
        } else {
            $this->originalTransaction = $creditMemo->getInvoice()->getStripeTaxTransactionId();
            $this->reference = sprintf('%s_%s_%s', $creditMemo->getIncrementId(), $creditMemo->getInvoice()->getIncrementId(), $this->dateTimeHelper->getTimestampInMilliseconds());
        }

        if ($this->isCreditmemoPartial($creditMemo)) {
            if ($invoice) {
                $shippingItem = $transaction->getShippingItemForReversal();

                $this->shippingCost->formOfflineData($creditMemo, $invoice, $shippingItem);
                $this->lineItems->formOfflineData($creditMemo, $invoice, $transaction);

                $this->mode = self::MODE_PARTIAL;
                // If the transaction has no reversals on it and the shipping and line items were fully reversed,
                // set the reversal request as a full request
                if ($shippingItem->isRequestForFullRevert() && $transaction->isRequestLineItemsFullyReverted()) {
                    if (!$transaction->hasReversals()) {
                        $this->mode = self::MODE_FULL;
                    }
                }
            } else {
                $this->mode = self::MODE_PARTIAL;
                $shippingItem = $transaction->getShippingItemForReversal();
                $this->shippingCost->formOnlineData($creditMemo, $shippingItem);
                $this->lineItems->formOnlineData($creditMemo, $transaction);
            }
        }

        return $this;
    }

    public function toArray()
    {
        $request = [
            self::MODE_FIELD_NAME => $this->mode,
            self::ORIGINAL_TRANSACTION_FIELD_NAME => $this->originalTransaction,
            self::REFERENCE_FIELD_NAME => $this->reference,
            self::EXPAND_FIELD_NAME => $this->expand
        ];

        // Only add shipping and line items to the request if it is a partial revert
        if ($this->isPartial()) {
            if ($this->shippingCost->canIncludeInRequest()) {
                $request[self::SHIPPING_COST_FIELD_NAME] = $this->shippingCost->toArray();
            }
            if ($this->lineItems->canIncludeInRequest()) {
                $request[self::LINE_ITEMS_FIELD_NAME] = $this->lineItems->toArray();
            }
        }

        return $request;
    }

    public function getLineItems()
    {
        return $this->lineItems;
    }

    public function formCommandLineData($lineItem, $quantity, $order, $additionalFeesItems, $shippingItem = null, $shipping = null)
    {
        if (!is_array($lineItem)) {
            if ($lineItem->getQtyRemaining() < $quantity) {
                $quantity = $lineItem->getQtyRemaining();
            }
        }
        $this->expand = ['line_items'];
        $this->mode = $this->getCommandLineMode($lineItem, $quantity, $order, $shippingItem, $shipping);
        $this->originalTransaction = $this->getCommandLineOriginalTransaction($lineItem);
        $this->reference = $this->getCommandLineReference($lineItem);
        $this->lineItems->formCommandLineData($lineItem, $quantity, $additionalFeesItems);
        $this->shippingCost->formCommandLineData($shippingItem, $shipping, $order->getOrderCurrencyCode());

        return $this;
    }

    private function getCommandLineReference($lineItem)
    {
        if (is_array($lineItem)) {
            $lineItemsRefs = [];
            foreach ($lineItem as $item) {
                $lineItemsRefs[] = $item->getReference();
            }
            return sprintf('cli_reversal_%s_%s', implode('-', $lineItemsRefs), $this->dateTimeHelper->getTimestampInMilliseconds());
        } else {
            return sprintf('cli_reversal_%s_%s', $lineItem->getReference(), $this->dateTimeHelper->getTimestampInMilliseconds());
        }
    }

    private function getCommandLineOriginalTransaction($lineItem)
    {
        if (is_array($lineItem)) {
            return $lineItem[0]->getStripeTransactionId();
        } else {
            return $lineItem->getStripeTransactionId();
        }
    }

    public function getCommandLineMode($lineItem, $quantity, $order, $shippingItem = null, $shipping = null)
    {
        if (is_array($lineItem)) {
            $lineItemsFullRevert = true;
            foreach ($lineItem as $item) {
                if ($item->getQty() > ($quantity * $item->getQtyMultiplier())) {
                    $lineItemsFullRevert = false;
                    break;
                }
            }
            if (isset($lineItem[0]) &&
                $lineItem[0]->getTransaction()->getAllLineItemsCount() == count($lineItem) &&
                $lineItemsFullRevert
            ) {
                if ($shippingItem) {
                    if ($shippingItem->getAmount() == $this->currencyHelper->magentoAmountToStripeAmount($shipping, $order->getOrderCurrencyCode())) {
                        return self::MODE_FULL;
                    } else {
                        return self::MODE_PARTIAL;
                    }
                }

                return self::MODE_FULL;
            }
        } else {
            if ($lineItem->getTransaction()->getAllLineItemsCount() == 1 &&
                $lineItem->getQty() == $quantity
            ) {
                if ($shippingItem) {
                    if ($shippingItem->getAmount() == $this->currencyHelper->magentoAmountToStripeAmount($shipping, $order->getOrderCurrencyCode())) {
                        return self::MODE_FULL;
                    } else {
                        return self::MODE_PARTIAL;
                    }
                }

                return self::MODE_FULL;
            }
        }

        return self::MODE_PARTIAL;
    }
}
