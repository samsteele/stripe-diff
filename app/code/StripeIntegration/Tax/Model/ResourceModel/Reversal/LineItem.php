<?php

namespace StripeIntegration\Tax\Model\ResourceModel\Reversal;

class LineItem extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_tax_reversal_line_items', 'entity_id');
    }
}
