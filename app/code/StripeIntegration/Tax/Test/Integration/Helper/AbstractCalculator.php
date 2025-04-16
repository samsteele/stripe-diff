<?php

namespace StripeIntegration\Tax\Test\Integration\Helper;

/**
 * This class holds the common calculations for Quote, Order and Invoice.
 * Will be extended by the child class if we will need to add new specific calculations and to keep
 * the quote calculation methods which already exist so as not to change all the tests
 */
class AbstractCalculator
{
    private $objectManager;
    private $taxRate;
    private $rateHelper;
    private $stripeTaxCalculator;

    public function __construct($country)
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->rateHelper = $this->objectManager->get(\StripeIntegration\Tax\Test\Integration\Helper\Rate::class);
        $this->stripeTaxCalculator = $this->objectManager->get(\StripeIntegration\Tax\Test\Integration\Helper\StripeTaxCalculator::class);
        switch ($country) {
            case 'Romania':
            case 'California':
            case 'California2':
                $this->taxRate = $this->rateHelper->getTaxRate($country);
                break;
            case 'US':
                $this->taxRate = 10;
                break;
            default:
                $this->taxRate = 25;
                break;
        }
    }

    public function calculateData($product, $qty, $shippingRate,  $taxBehaviour, $precision = 2)
    {
        if ($taxBehaviour == 'exclusive') {
            return $this->calculateExclusiveData($product, $qty, $shippingRate, $precision);
        } elseif ($taxBehaviour == 'inclusive') {
            return $this->calculateInclusiveData($product, $qty, $shippingRate, $precision);
        } else {
            return $this->calculateFreeData($product, $qty, $shippingRate);
        }
    }

    private function calculateExclusiveData($price, $qty, $shippingRate, $precision = 2)
    {
        $totalExclTax = ($price + $shippingRate) * $qty;
        $tax = round($this->taxRate / 100 * $totalExclTax, $precision);
        return [
            'grand_total' => $totalExclTax + $tax
        ];
    }

    private function calculateInclusiveData($price, $qty, $shippingRate, $precision = 2)
    {
        $grandTotal = ($price + $shippingRate) * $qty;
        return [
            'grand_total' => $grandTotal
        ];
    }

    private function calculateFreeData($price, $qty, $shippingRate)
    {
        $grandTotal = ($price + $shippingRate) * $qty;
        return [
            'grand_total' => $grandTotal
        ];
    }

    public function calculateDataMultipleTaxes($product, $qty, $shippingRate,  $taxBehaviour)
    {
        if ($taxBehaviour == 'exclusive') {
            return $this->calculateExclusiveDataMultipleTaxeas($product, $qty, $shippingRate);
        } elseif ($taxBehaviour == 'inclusive') {
            return $this->calculateInclusiveData($product, $qty, $shippingRate);
        } else {
            return $this->calculateFreeData($product, $qty, $shippingRate);
        }
    }

    private function calculateExclusiveDataMultipleTaxeas($price, $qty, $shippingRate)
    {
        $totalExclTax = ($price + $shippingRate) * $qty;
        $tax = round($this->taxRate / 100 * ($price * $qty), 2);
        return [
            'grand_total' => $totalExclTax + $tax
        ];
    }

    public function calculateItemData($price, $shipping, $qty, $taxBehaviour, $precision = 2)
    {
        $stripeCalculatedData = $this->stripeTaxCalculator->calculateForPrice($price * $qty, $shipping, $this->taxRate, $taxBehaviour, null, true, $precision);
        if ($taxBehaviour == 'exclusive') {
            return $this->calculateExclusiveItemData($price, $qty, $stripeCalculatedData, $precision);
        } else {
            return $this->calculateInclusiveItemData($price, $qty, $stripeCalculatedData, $precision);
        }
    }

    private function calculateExclusiveItemData($price, $qty, $stripeCalculatedData, $precision = 2)
    {
        $taxAmount = $stripeCalculatedData['tax'];
        $rowTotal = $stripeCalculatedData['amount'];
        $price = round($rowTotal / $qty, $precision);
        $tax = round($taxAmount / $qty, $precision);
        $priceInclTax = $price + $tax;
        $rowTotalInclTax = $rowTotal + $taxAmount;
        return [
            'price' => $price,
            'row_total' => $rowTotal,
            'tax_amount' => $taxAmount,
            'price_incl_tax' => $priceInclTax,
            'row_total_incl_tax' => $rowTotalInclTax
        ];
    }

    private function calculateInclusiveItemData($price, $qty, $stripeCalculatedData, $precision = 2)
    {
        $taxAmount = $stripeCalculatedData['tax'];
        $rowTotal = round($stripeCalculatedData['amount'] - $taxAmount, $precision);
        $price = round($rowTotal / $qty, $precision);
        $priceInclTax = round($stripeCalculatedData['amount'] / $qty, $precision);
        $rowTotalInclTax = $stripeCalculatedData['amount'];
        return [
            'price' => $price,
            'row_total' => $rowTotal,
            'tax_amount' => $taxAmount,
            'price_incl_tax' => $priceInclTax,
            'row_total_incl_tax' => $rowTotalInclTax
        ];
    }

    private function getUnRoundedTaxAmount($taxAmount)
    {
        $newTax = $taxAmount * 100;

        return (int)$newTax / 100;
    }

    public function calculateShippingData($price, $shipping, $qty, $taxBehaviour, $precision = 2)
    {
        $stripeCalculatedData = $this->stripeTaxCalculator->calculateForShipping($price * $qty, $shipping, $this->taxRate, $taxBehaviour, $precision);
        if ($taxBehaviour == 'exclusive') {
            return $this->calculateExclusiveShippingData($price, $qty, $stripeCalculatedData, $precision);
        } elseif ($taxBehaviour == 'inclusive') {
            return $this->calculateInclusiveShippingData($price, $qty, $stripeCalculatedData);
        } else {
            return $this->calculateFreeShippingData($price, $qty, $stripeCalculatedData);
        }
    }

    private function calculateExclusiveShippingData($price, $qty, $stripeCalculatedData, $precision = 2)
    {
        $taxAmount = $stripeCalculatedData['tax'];
        $shippingAmount = $stripeCalculatedData['amount'];
        $shippingInclTax = round($shippingAmount + $taxAmount, $precision);
        return [
            'shipping_amount' => $shippingAmount,
            'shipping_tax_amount' => $taxAmount,
            'shipping_incl_tax' => $shippingInclTax
        ];
    }

    private function calculateInclusiveShippingData($price, $qty, $stripeCalculatedData, $precision = 2)
    {
        $shippingInclTax = $stripeCalculatedData['amount'];
        $taxAmount = $stripeCalculatedData['tax'];
        $shippingAmount = round($shippingInclTax - $taxAmount, $precision);
        return [
            'shipping_amount' => $shippingAmount,
            'shipping_tax_amount' => $taxAmount,
            'shipping_incl_tax' => $shippingInclTax
        ];
    }

    private function calculateFreeShippingData($price, $qty, $stripeCalculatedData)
    {
        $shippingAmount = $stripeCalculatedData['amount'];
        $taxAmount = $stripeCalculatedData['tax'];
        $shippingInclTax = round($shippingAmount + $taxAmount, 2);
        return [
            'shipping_amount' => $shippingAmount,
            'shipping_tax_amount' => $taxAmount,
            'shipping_incl_tax' => $shippingInclTax
        ];
    }

    public function getTaxRate()
    {
        return $this->taxRate;
    }
}