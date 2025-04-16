<?php

namespace StripeIntegration\Tax\Model;

use StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem\Collection;

class Transaction extends \Magento\Framework\Model\AbstractModel
{
    public const TRANSACTION_STATUS_NOT_REVERTED = 'not_refunded';
    public const TRANSACTION_STATUS_PARTIAL_REVERT = 'partial';
    public const TRANSACTION_STATUS_FULL_REVERT = 'full';

    private $resourceModel;
    private $lineItemsCollection;
    private $reversalCollection;

    private $lineItems = null;
    private $shippingItem = null;
    private $lineItemsCount = null;
    private $hasReversals = null;
    private $lineItemsProcessed = 0;
    private $allItems = null;

    public function __construct(
        \StripeIntegration\Tax\Model\ResourceModel\Transaction $resourceModel,
        \StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem\Collection $lineItemsCollection,
        \StripeIntegration\Tax\Model\ResourceModel\Reversal\Collection $reversalCollection,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        $this->resourceModel = $resourceModel;
        $this->lineItemsCollection = $lineItemsCollection;
        $this->reversalCollection = $reversalCollection;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\ResourceModel\Transaction');
    }

    public function createFromStripeTransaction($transaction, $invoice)
    {
        $this->setStripeTransactionId($transaction->id);
        $this->setOrderIncrementId($invoice->getOrder()->getIncrementId());
        $this->setInvoiceIncrementId($invoice->getIncrementId());
        $this->setReference($transaction->reference);
        $this->setStripeCreatedAt($transaction->created);
        $this->setReversalStatus(self::TRANSACTION_STATUS_NOT_REVERTED);

        $this->resourceModel->save($this);

        return $this;
    }

    public function hasLineItemsForReversal()
    {
        $this->formLineItemsForReversal();

        return count($this->lineItems) > 0;
    }

    public function getLineItemsForReversal()
    {
        $this->formLineItemsForReversal();

        return $this->lineItems;
    }

    public function getLineItemForReversalByReference($reference)
    {
        $this->formLineItemsForReversal();

        if (isset($this->lineItems[$reference])) {
            return $this->lineItems[$reference];
        }

        return null;
    }

    public function getShippingItemForReversal()
    {
        if ($this->shippingItem === null) {
            $this->shippingItem = $this->lineItemsCollection->getShippingItemForTransaction($this->getStripeTransactionId());
        }

        return $this->shippingItem;
    }

    public function addItemProcessed()
    {
        $this->lineItemsProcessed++;
    }

    public function isProcessed()
    {
        return $this->lineItemsProcessed == $this->lineItemsCount;
    }

    public function hasReversals()
    {
        if ($this->hasReversals === null) {
            $this->hasReversals = (count($this->reversalCollection->getReversalsForTransaction($this->getStripeTransactionId())) > 0);
        }

        return $this->hasReversals;
    }

    public function isRequestLineItemsFullyReverted()
    {
        $this->formLineItemsForReversal();

        foreach ($this->lineItems as $lineItem) {
            // If one of the line items available for reversal is not fully reverted, then the whole transaction
            // is not fully reverted
            if (!$lineItem->isRequestForFullRevert()) {
                return false;
            }
        }

        return true;
    }

    public function isFullyReverted()
    {
        if ($this->getReversalStatus() === self::TRANSACTION_STATUS_FULL_REVERT) {
            return true;
        }

        $reversalItems = $this->getLineItemsForReversal();
        $shippingItem = $this->getShippingItemForReversal();

        if ($shippingItem->hasShippingTax()) {
            return false;
        }

        if ($reversalItems) {
            return false;
        }

        return true;
    }

    /**
     * Reloads the line items for reversal and the shipping items of the transaction without checking if they are set
     *
     * @return $this
     */
    public function refresh()
    {
        $this->shippingItem = $this->lineItemsCollection->getShippingItemForTransaction($this->getStripeTransactionId());

        $collectionItems = $this->lineItemsCollection->getLineItemsForReversal($this->getId());
        $this->lineItems = [];
        foreach ($collectionItems as $lineItem) {
            $this->lineItems[$lineItem->getReference()] = $lineItem;
        }

        return $this;
    }

    public function updateReversalStatus()
    {
        if ($this->isFullyReverted()) {
            $this->setReversalStatus(self::TRANSACTION_STATUS_FULL_REVERT);
        } else {
            $this->setReversalStatus(self::TRANSACTION_STATUS_PARTIAL_REVERT);
        }
    }

    public function getAllLineItemsCount()
    {
        $this->formAllItems();

        return count($this->allItems);
    }

    private function formLineItemsForReversal()
    {
        if ($this->lineItems === null ) {
            $collectionItems = $this->lineItemsCollection->getLineItemsForReversal($this->getId());
            $this->lineItems = [];
            foreach ($collectionItems as $lineItem) {
                $this->lineItems[$lineItem->getReference()] = $lineItem;
            }
            $this->lineItemsCount = count($this->lineItems);
        }
    }

    private function formAllItems()
    {
        if ($this->allItems === null) {
            $this->allItems = $this->lineItemsCollection->getAllLineItems($this->getId());
        }
    }
}