<?php

namespace StripeIntegration\Tax\Model\ResourceModel;

class Reversal extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_tax_reversals', 'entity_id');
    }
}
