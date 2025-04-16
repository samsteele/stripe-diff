<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model;

class ShippingRate extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\ShippingRate');
    }
}
