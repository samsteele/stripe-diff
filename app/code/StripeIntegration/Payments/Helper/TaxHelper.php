<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\Exception;

class TaxHelper
{
    public function taxInclusiveTaxCalculator($fullAmount, $taxPercent)
    {
        if ($taxPercent <= 0 || $fullAmount <= 0 || !is_numeric($fullAmount))
            return 0;

        $taxDivider = (1 + $taxPercent / 100); // i.e. Convert 8.25 to 1.0825
        $amountWithoutTax = round(floatval($fullAmount / $taxDivider), 2); // Magento seems to sometimes be flooring instead of rounding tax inclusive prices
        return  $fullAmount - $amountWithoutTax;
    }

    public function taxExclusiveTaxCalculator($fullAmount, $taxPercent)
    {
        if ($taxPercent <= 0 || $fullAmount <= 0 || !is_numeric($fullAmount))
            return 0;

        return round(floatval($fullAmount * ($taxPercent / 100)), 2);
    }

    public function getOrderItemTaxPercent($orderItem): float
    {
        if (is_numeric($orderItem->getTaxPercent()) && $orderItem->getTaxPercent() > 0)
        {
            return floatval($orderItem->getTaxPercent());
        }

        // Some bundle products will have a tax amount but no tax percent,
        // which means that tax applies on individual child products, and not the bundle item itself.
        if (is_numeric($orderItem->getTaxAmount()) && $orderItem->getTaxAmount() > 0)
        {
            throw new Exception("The tax percent is not set for the order item.");
        }

        return floatval(0);
    }

    public function getTaxCountryCode($order)
    {
        $store = $order->getStore();
        if ($store->getConfig('tax/calculation/based_on') == 'shipping')
        {
            if (!$order->getIsVirtual() && $order->getShippingAddress()->getCountryId())
            {
                return $order->getShippingAddress()->getCountryId();
            }
            else if ($order->getBillingAddress()->getCountryId())
            {
                return $order->getBillingAddress()->getCountryId();
            }
        }
        else if ($store->getConfig('tax/calculation/based_on') == 'billing')
        {
            if ($order->getBillingAddress()->getCountryId())
            {
                return $order->getBillingAddress()->getCountryId();
            }
        }
        else if ($store->getConfig('tax/defaults/country'))
        {
            return $store->getConfig('tax/defaults/country');
        }

        throw new Exception("The tax country code could not be determined.");
    }
}
