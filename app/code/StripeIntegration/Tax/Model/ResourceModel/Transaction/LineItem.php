<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Transaction;

class LineItem extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_tax_transaction_line_items', 'entity_id');
    }
}
