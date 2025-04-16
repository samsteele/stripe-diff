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