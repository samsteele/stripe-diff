<?php

namespace StripeIntegration\Tax\Helper;

use StripeIntegration\Tax\Model\ResourceModel\Transaction;
use StripeIntegration\Tax\Model\Transaction as TransactionModel;
use StripeIntegration\Tax\Model\TransactionFactory;
use StripeIntegration\Tax\Model\ReversalFactory;
use Stripe\Tax\Transaction as StripeTransaction;
use StripeIntegration\Tax\Model\Transaction\LineItemFactory;
use StripeIntegration\Tax\Model\Reversal\LineItemFactory as ReversalLineItemFactory;
use StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem\CollectionFactory as LineItemCollectionFactory;

class Transactions
{
    private $transactionsFactory;
    private $transactionsResource;
    private $lineItemFactory;
    private $reversalFactory;
    private $transactionLineItemsCollection;
    private $reversalLineItemFactory;


    public function __construct(
        TransactionFactory $transactionsFactory,
        Transaction $transactionsResource,
        LineItemFactory $lineItemFactory,
        ReversalFactory $reversalFactory,
        LineItemCollectionFactory $transactionLineItemsCollection,
        ReversalLineItemFactory $reversalLineItemFactory
    ) {
        $this->transactionsFactory = $transactionsFactory;
        $this->transactionsResource = $transactionsResource;
        $this->lineItemFactory = $lineItemFactory;
        $this->reversalFactory = $reversalFactory;
        $this->transactionLineItemsCollection = $transactionLineItemsCollection;
        $this->reversalLineItemFactory = $reversalLineItemFactory;
    }

    /**
     * Because models should not be responsible for loading themselves, we will add the loading function here so that
     * it can be reused.
     *
     * @param $transactionId
     * @return \StripeIntegration\Tax\Model\Transaction
     */
    public function loadByStripeTransactionId($transactionId)
    {
        $model = $this->transactionsFactory->create();
        $this->transactionsResource->load($model, $transactionId, 'stripe_transaction_id');

        return $model;
    }

    public function loadByTransactionId($transactionId)
    {
        $model = $this->transactionsFactory->create();
        $this->transactionsResource->load($model, $transactionId, 'entity_id');

        return $model;
    }

    public function saveTransaction(TransactionModel $transaction)
    {
        $this->transactionsResource->save($transaction);
    }

    public function processStripeTransaction(StripeTransaction $transaction, $invoice)
    {
        $transactionModel = $this->transactionsFactory->create()->createFromStripeTransaction($transaction, $invoice);
        foreach ($transaction->line_items->data as $lineItem) {
            $this->lineItemFactory->create()->createFromStripeTransactionLineItem($lineItem, $transactionModel);
        }
        if ($transaction->shipping_cost) {
            $this->lineItemFactory->create()->createShippingFromStripeTransaction($transaction, $transactionModel);
        }

        return $transactionModel;
    }

    public function processStripeReversal(StripeTransaction $reversalTransaction, $creditmemo, $transaction, $invoice = null)
    {
        $transactionModel = $this->reversalFactory->create()->createFromStripeTransaction($reversalTransaction, $creditmemo, $invoice);
        $transactionLineItemsCollection = $this->transactionLineItemsCollection->create();

        if (isset($reversalTransaction->line_items->data)) {
            foreach ($reversalTransaction->line_items->data as $lineItem) {
                $reversalItem = $this->reversalLineItemFactory->create()->createFromStripeTransactionLineItem($lineItem, $transactionModel);
                $lineItem = $transactionLineItemsCollection->getItemByStripeId($reversalItem->getOriginalStripeId());
                $lineItem->updateAmounts($reversalItem);
            }
        }

        if (isset($reversalTransaction->shipping_cost)) {
            $shippingReversal = $this->reversalLineItemFactory->create()->createShippingFromStripeTransaction($reversalTransaction, $transactionModel);
            $lineItem = $transactionLineItemsCollection->getShippingItemForTransaction($transactionModel->getOriginalTransactionId());
            $lineItem->updateAmounts($shippingReversal);
        }

        $transaction->refresh()->updateReversalStatus();
        $this->saveTransaction($transaction);

        return $transactionModel;
    }

    public function processStripeCommandLineReversal($reversalTransaction, $transaction)
    {
        $transactionModel = $this->reversalFactory->create()->createFromStripeCommandLineTransaction($reversalTransaction, $transaction);
        $transactionLineItemsCollection = $this->transactionLineItemsCollection->create();

        if (isset($reversalTransaction->line_items->data)) {
            foreach ($reversalTransaction->line_items->data as $lineItem) {
                $reversalItem = $this->reversalLineItemFactory->create()->createFromStripeTransactionLineItem($lineItem, $transactionModel);
                $lineItem = $transactionLineItemsCollection->getItemByStripeId($reversalItem->getOriginalStripeId());
                $lineItem->updateAmounts($reversalItem);
            }
        }

        if (isset($reversalTransaction->shipping_cost)) {
            $shippingReversal = $this->reversalLineItemFactory->create()->createShippingFromStripeTransaction($reversalTransaction, $transactionModel);
            $lineItem = $transactionLineItemsCollection->getShippingItemForTransaction($transactionModel->getOriginalTransactionId());
            $lineItem->updateAmounts($shippingReversal);
        }

        $transaction->refresh()->updateReversalStatus();
        $this->saveTransaction($transaction);

        return $transactionModel;
    }
}