<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper\Stripe;

class InvoiceItem
{
    public function shouldIncludeOnInvoice($orderItem)
    {
        if ($orderItem->getParentItem() && $orderItem->getParentItem()->getProductType() == 'configurable')
        {
            // We add the parent configurable product, not the children.
            return false;
        }

        if ($orderItem->getProductType() == 'bundle')
        {
            // We add each child of the bundle item separately, and not the bundle item itself.
            // Tax is applied on the child items, so this solves some tax rounding errors.
            return false;
        }

        return true;
    }

    public function getOrderItemAppliedRuleIds($orderItem)
    {
        if ($orderItem->getParentItem() && $orderItem->getParentItem()->getProductType() == 'bundle')
        {
            return $orderItem->getParentItem()->getAppliedRuleIds();
        }

        return $orderItem->getAppliedRuleIds();
    }
}