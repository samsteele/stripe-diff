<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order;

use Magento\Sales\Model\Order\Item as OrderItem;

class Item
{
    /**
     * Fixes a bug introduced in Magento 2.4.7, where if orders that include downloadable products are invoiced,
     * customers can download the products regardless of whether the invoice is in Open or Paid status.
     * Downloadable products should only be downloadable after the invoice has been paid.
     */
    public function afterGetStatusId(OrderItem $orderItem, $result)
    {
        if ($result === OrderItem::STATUS_INVOICED)
        {
            $order = $orderItem->getOrder();
            if (!$order || !$order->getId())
            {
                return $result;
            }

            if ($order->getState() === 'pending_payment')
            {
                return OrderItem::STATUS_PENDING;
            }
        }

        return $result;
    }
}
