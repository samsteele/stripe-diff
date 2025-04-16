<?php

namespace StripeIntegration\Payments\Model\ResourceModel\CheckoutSession;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\CheckoutSession', 'StripeIntegration\Payments\Model\ResourceModel\CheckoutSession');
    }

    public function getByQuoteId($quoteId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);
        $this->addFieldToFilter('quote_id', $quoteId);
        return $this->getFirstItem();
    }

    public function getByOrderIncrementId($orderIncrementId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);
        $this->addFieldToFilter('order_increment_id', $orderIncrementId);
        return $this->getFirstItem();
    }

    public function getByCheckoutSessionId($checkoutSessionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);
        $this->addFieldToFilter('checkout_session_id', $checkoutSessionId);
        return $this->getFirstItem();
    }
}
