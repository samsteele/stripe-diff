<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Reversal\LineItem;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\Reversal\LineItem', 'StripeIntegration\Tax\Model\ResourceModel\Reversal\LineItem');
    }
}