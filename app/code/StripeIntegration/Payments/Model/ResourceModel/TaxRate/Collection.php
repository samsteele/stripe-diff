<?php

namespace StripeIntegration\Payments\Model\ResourceModel\TaxRate;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\TaxRate', 'StripeIntegration\Payments\Model\ResourceModel\TaxRate');
    }

    public function findBy($stripeAccountId, $displayName, $inclusive, $percentage, $countryCode)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $this->addFieldToSelect('*')
            ->addFieldToFilter('stripe_account_id', $stripeAccountId)
            ->addFieldToFilter('display_name', $displayName)
            ->addFieldToFilter('inclusive', $inclusive)
            ->addFieldToFilter('percentage', $percentage)
            ->addFieldToFilter('country_code', $countryCode);

        return $this->getFirstItem();
    }

}
