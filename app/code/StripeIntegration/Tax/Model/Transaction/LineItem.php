<?php

namespace StripeIntegration\Tax\Model\Transaction;

use StripeIntegration\Tax\Helper\Transactions;

class LineItem extends \Magento\Framework\Model\AbstractModel
{
    public const LINE_ITEM_TYPE_LINE_ITEM = 'line_item';
    public const LINE_ITEM_TYPE_SHIPPING = 'shipping';

    private $resourceModel;
    private $transactionsHelper;

    private $transaction = null;

    public function __construct(
        \StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem  $resourceModel,
        Transactions $transactionsHelper,
        \Magento\Framework\Model\Context                        $context,
        \Magento\Framework\Registry                             $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb           $resourceCollection = null,
        array                                                   $data = []
    )
    {
        $this->resourceModel = $resourceModel;
        $this->transactionsHelper = $transactionsHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem');
    }

    public function createFromStripeTransactionLineItem($lineItem, $transactionModel)
    {
        $this->setTransactionId($transactionModel->getId());
        $this->setStripeTransactionId($transactionModel->getStripeTransactionId());
        $this->setStripeId($lineItem->id);
        $this->setAmount($lineItem->amount);
        $this->setAmountTax($lineItem->amount_tax);
        $this->setAmountRemaining($lineItem->amount);
        $this->setAmountTaxRemaining($lineItem->amount_tax);
        $this->setQty($lineItem->quantity);
        $this->setQtyRemaining($lineItem->quantity);
        $this->setReference($lineItem->reference);
        $this->setTaxBehavior($lineItem->tax_behavior);
        $this->setTaxCode($lineItem->tax_code);
        $this->setType(self::LINE_ITEM_TYPE_LINE_ITEM);

        $this->resourceModel->save($this);

        return $this;
    }

    public function createShippingFromStripeTransaction($transaction, $transactionModel)
    {
        $shipping = $transaction->shipping_cost;
        $this->setTransactionId($transactionModel->getId());
        $this->setStripeTransactionId($transactionModel->getStripeTransactionId());
        $this->setAmount($shipping->amount);
        $this->setAmountTax($shipping->amount_tax);
        $this->setAmountRemaining($shipping->amount);
        $this->setAmountTaxRemaining($shipping->amount_tax);
        $this->setTaxBehavior($shipping->tax_behavior);
        $this->setTaxCode($shipping->tax_code);
        $this->setType(self::LINE_ITEM_TYPE_SHIPPING);

        $this->resourceModel->save($this);

        return $this;
    }

    public function isShippingItem()
    {
        return $this->getType() === self::LINE_ITEM_TYPE_SHIPPING;
    }

    public function hasShippingTax()
    {
        return $this->isShippingItem() && ($this->getAmountTaxRemaining() > 0);
    }

    /**
     * Check id the item is set to be reverted after the reversal request line item is formed.
     * This was created to be able to set the request type for the reversal transaction request.
     *
     * @return bool
     */
    public function isRequestForFullRevert()
    {
        return $this->getRequestAmountFullRevert() && $this->getRequestAmountTaxFullRevert();
    }

    public function isAmountFullReverted()
    {
        return $this->getAmountRemaining() <= 0;
    }

    public function isAmountTaxFullReverted()
    {
        return $this->getAmountTaxRemaining() <= 0;
    }

    public function updateAmounts($reversalItem)
    {
        $this->setAmountRemaining($this->getAmountRemaining() + $reversalItem->getAmount());
        $this->setAmountTaxRemaining($this->getAmountTaxRemaining() + $reversalItem->getAmountTax());
        $this->setQtyRemaining($this->getQtyRemaining() - $reversalItem->getQty());
        $this->resourceModel->save($this);
    }

    public function checkRemainingValuesForRequest($amount, $amountTax)
    {
        if (floatval($this->getAmountTaxRemaining() + $amountTax) === 0.0) {
            $this->setRequestAmountTaxFullRevert(true);
        }
        if (floatval($this->getAmountRemaining() + $amount) === 0.0) {
            $this->setRequestAmountFullRevert(true);
        }
    }

    public function getTransaction()
    {
        if ($this->transaction === null) {
            $this->transaction = $this->transactionsHelper->loadByTransactionId($this->getTransactionId());
        }

        return $this->transaction;
    }
}