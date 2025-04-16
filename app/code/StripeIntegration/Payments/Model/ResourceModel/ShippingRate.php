<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\ResourceModel;

class ShippingRate extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_shipping_rates', 'id');
    }
}
