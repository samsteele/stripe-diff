<?php

namespace StripeIntegration\Payments\Model\ResourceModel\ShippingRate;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ShippingRate', 'StripeIntegration\Payments\Model\ResourceModel\ShippingRate');
    }

    public function findBy($stripeAccountId, $displayName, $amount, $currency)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $this->addFieldToSelect('*')
            ->addFieldToFilter('stripe_account_id', $stripeAccountId)
            ->addFieldToFilter('display_name', $displayName)
            ->addFieldToFilter('amount', $amount)
            ->addFieldToFilter('currency', $currency);

        return $this->getFirstItem();
    }
}
