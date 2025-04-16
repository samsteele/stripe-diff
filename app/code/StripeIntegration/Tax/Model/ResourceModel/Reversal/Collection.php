<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Reversal;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\Reversal', 'StripeIntegration\Tax\Model\ResourceModel\Reversal');
    }

    public function getReversalsForTransaction($transactionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('original_transaction_id', ['eq' => $transactionId]);

        return $collection->getItems();
    }
}