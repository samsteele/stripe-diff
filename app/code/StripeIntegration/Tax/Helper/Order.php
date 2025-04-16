<?php

namespace StripeIntegration\Tax\Helper;

use Magento\Framework\Serialize\SerializerInterface;

class Order
{
    private $serializer;
    private $transactionsHelper;

    public function __construct(
        SerializerInterface $serializer,
        Transactions $transactionsHelper
    )
    {
        $this->serializer = $serializer;
        $this->transactionsHelper = $transactionsHelper;
    }

    public function addTransactionMode($order, $transactionId, $mode, $latestReversalTransactionId, $lineItemsData = null)
    {
        $transactionData = ['status' => $mode, 'latest_reversal' => $latestReversalTransactionId];
        if ($lineItemsData) {
            $transactionData['line_items'] = $lineItemsData;
        }

        if ($order->getStripeTaxTransactionsReversalMode()) {
            $modeArray = $this->serializer->unserialize($order->getStripeTaxTransactionsReversalMode());
            $modeArray[$transactionId] = $transactionData;
        } else {
            $modeArray = [$transactionId => $transactionData];
        }

        $order->setStripeTaxTransactionsReversalMode($this->serializer->serialize($modeArray));
    }

    public function isOrderTaxTransactionFullyReversed($order, $transactionId)
    {
        if (!$order->getStripeTaxTransactionsReversalMode()) {
            return false;
        }

        $transactionsModeArray = $this->serializer->unserialize($order->getStripeTaxTransactionsReversalMode());
        if (array_key_exists($transactionId, $transactionsModeArray) &&
            $transactionsModeArray[$transactionId]['status'] === \StripeIntegration\Tax\Model\StripeTransactionReversal\Request::MODE_FULL
        ) {
            return true;
        }

        return false;
    }

    public function getReversalForInvoiceTransaction($order, $transactionId)
    {
        return $this->getTransactionComponent($order, $transactionId, 'latest_reversal');
    }

    public function getReversalLineItemsData($order, $transactionId)
    {
        return $this->getTransactionComponent($order, $transactionId, 'line_items');
    }

    private function getTransactionComponent($order, $transactionId, $component)
    {
        if (!$order->getStripeTaxTransactionsReversalMode()) {
            return false;
        }

        $transactionsModeArray = $this->serializer->unserialize($order->getStripeTaxTransactionsReversalMode());

        if (array_key_exists($transactionId, $transactionsModeArray)) {
            return $transactionsModeArray[$transactionId][$component];
        }

        return false;
    }

    public function getOrderItemBySku($order, $sku)
    {
        foreach ($order->getItems() as $item) {
            if ($item->getSku() === $sku) {
                return $item;
            }
        }

        return null;
    }

    public function getTransactionsForOrder($order)
    {
        $transactions = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            $stripeTransactionId = $invoice->getStripeTaxTransactionId();
            $transactions[] = $this->transactionsHelper->loadByStripeTransactionId($stripeTransactionId);
        }

        return $transactions;
    }
}