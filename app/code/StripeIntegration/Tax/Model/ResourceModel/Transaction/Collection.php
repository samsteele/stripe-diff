<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Transaction;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\Transaction', 'StripeIntegration\Tax\Model\ResourceModel\Transaction');
    }
}