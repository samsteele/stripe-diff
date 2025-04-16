<?php

namespace StripeIntegration\Tax\Model\ResourceModel;

class Transaction extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_tax_transactions', 'entity_id');
    }
}
