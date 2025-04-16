<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem;

use StripeIntegration\Tax\Model\Transaction\LineItem;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\Transaction\LineItem', 'StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem');
    }

    public function getItemByStripeId($itemStripeId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('stripe_id', ['eq' => $itemStripeId]);

        return $collection->getFirstItem();
    }

    public function getShippingItemForTransaction($transactionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('stripe_transaction_id', ['eq' => $transactionId])
            ->addFieldToFilter('type', ['eq' => LineItem::LINE_ITEM_TYPE_SHIPPING]);

        return $collection->getFirstItem();
    }

    /**
     * Returns the line items related to a transaction which have tax remaining on them
     *
     * @param $transactionId
     * @return \Magento\Framework\DataObject[]
     */
    public function getLineItemsForReversal($transactionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('transaction_id', ['eq' => $transactionId])
            ->addFieldToFilter('type', ['eq' => LineItem::LINE_ITEM_TYPE_LINE_ITEM])
            ->addFieldToFilter('amount_tax_remaining', ['gt' => 0]);

        return $collection->getItems();
    }

    /**
     * Returns the items which have a certain reference and can be reverted (have remaining un-reverted qty
     * greater than 0). The reference is formed from the product sku and the order increment id, so it is possible
     * to have multiple items, if they are part of different transactions on the same order
     * (multiple invoices on an order).
     *
     * @param $reference
     * @return \Magento\Framework\DataObject[]
     */
    public function getItemsByReference($reference)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('reference', ['eq' => $reference])
            ->addFieldToFilter('type', ['eq' => LineItem::LINE_ITEM_TYPE_LINE_ITEM])
            ->addFieldToFilter('qty_remaining', ['gt' => 0]);

        return $collection->getItems();
    }

    public function getAllLineItems($transactionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('transaction_id', ['eq' => $transactionId])
            ->addFieldToFilter('type', ['eq' => LineItem::LINE_ITEM_TYPE_LINE_ITEM]);

        return $collection->getItems();
    }
}