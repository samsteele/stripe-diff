<?php

namespace StripeIntegration\Payments\Model;

class TaxRate extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\TaxRate');
    }
}
