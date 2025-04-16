<?php

namespace StripeIntegration\Tax\Test\Integration\Helper;

/**
 * Created to simulate the calculations done by stripe to give back the prices and taxes
 */
class StripeTaxCalculator
{
    /**
     * The Aggregate Base Combined Rate algorithm which is used by Stripe is as follows:
     *
     * 1. Each line item's tax is calculated individually, and rounded.
     * 2. Line items with the same tax rate are aggregated together, and the aggregated tax is calculated.
     * 3. If the aggregated tax differs from the sum of the individual taxes, the individual taxes are
     * adjusted (adjusting the largest amount first).
     *
     * We are using this class to get calculations for quote items, so we will be using the item price
     * and also the prices of other items if they exist in the quote.
     *
     * We assume that currently we won't have differences greater than 0.01, so we add a parameter which tells us if the
     * option is the largest in the quote, and we can adjust it if need be.
     */
    public function calculateForPrice($price, $shipping, $rate, $behaviour, $otherPrices = null, $isLargestPrice = true, $precision = 2)
    {
        // The amount and tax calculated for the current item
        $amount = $this->calculateAmount($price, $rate, $behaviour);
        $tax = $this->calculateTax($price, $rate, $behaviour, $precision);

        // Calculations for other items if they exist. 0 if they do not exist
        $otherPricesTotal = $this->getOtherPricesTotal($otherPrices);
        $otherTaxes = $this->getOtherTaxes($otherPrices, $rate, $behaviour);

        // Shipping tax calculated
        $shippingTax = $this->calculateTax($shipping, $rate, $behaviour, $precision);

        // Tax calculated for the aggregated prices
        $aggregateTax = $this->calculateTax($price + $shipping + $otherPricesTotal, $rate, $behaviour, $precision);

        // Check if there is a difference between the aggregate tax and the summed individual taxes and adjust
        // if the item is the largest of the items in the quote
        if ((round($tax + $shippingTax + $otherTaxes, $precision) > $aggregateTax) && $isLargestPrice) {
            $tax -= 0.01;
        } elseif ((round($tax + $shippingTax + $otherTaxes, $precision) < $aggregateTax) && $isLargestPrice) {
            $tax += 0.01;
        }

        return [
            'amount' => $amount,
            'tax' => round($tax, $precision)
        ];
    }

    public function calculateForShipping($price, $shipping, $rate, $behaviour, $precision = 2)
    {
        $shippingAmount = $this->calculateAmount($shipping, $rate, $behaviour);
        $shippingTax = $this->calculateTax($shipping, $rate, $behaviour, $precision);

        return [
            'amount' => $shippingAmount,
            'tax' => $shippingTax
        ];
    }

    private function calculateAmount($amount, $rate, $behaviour)
    {
        if ($behaviour == 'inclusive') {
            return $this->calculateInclusiveAmount($amount, $rate);
        } elseif ($behaviour == 'exclusive') {
            return $this->calculateExclusiveAmount($amount, $rate);
        } else {
            return $amount;
        }
    }

    private function calculateInclusiveAmount($amount, $rate)
    {
        return $amount;
    }

    private function calculateExclusiveAmount($amount, $rate)
    {
        return $amount;
    }

    private function calculateTax($amount, $rate, $behaviour, $precision = 2)
    {
        if ($behaviour == 'inclusive') {
            return $this->calculateInclusiveTax($amount, $rate, $precision);
        } elseif ($behaviour == 'exclusive') {
            return $this->calculateExclusiveTax($amount, $rate, $precision);
        } else {
            return 0;
        }
    }

    private function calculateInclusiveTax($amount, $rate, $precision = 2)
    {
        $priceExclTax = round($amount / ($rate / 100 + 1), $precision);

        return round($amount - $priceExclTax, $precision);
    }

    private function calculateExclusiveTax($amount, $rate, $precision = 2)
    {
        return round($rate / 100 * $amount, $precision);
    }

    private function getOtherTaxes($otherPrices, $rate, $behaviour, $precision = 2)
    {
        $otherTaxes = 0;

        if ($otherPrices) {
            foreach ($otherPrices as $otherPrice) {
                $otherTaxes += $this->calculateTax($otherPrice, $rate, $behaviour, $precision);
            }
        }

        return $otherTaxes;
    }

    private function getOtherPricesTotal($otherPrices)
    {
        if ($otherPrices) {
            return array_sum($otherPrices);
        }

        return 0;
    }
}
