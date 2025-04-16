<?php

namespace StripeIntegration\Tax\Model\Order\Transaction;

class Item
{
    private $quantity;
    private $unitPrice;
    private $discountAmount;
    private $stripeTotalCalculatedAmount;
    private $stripeTotalCalculatedTax;
    private $stripeCurrency;
    private $useBaseCurrency;
    private $priceIncludesTax;

    /**
     * @param $invoiceItem
     * @param $lineItem
     * @return $this
     *
     * Prepare a calculation item for getting the values related to items. This preparation is done so that we can
     * re-use the calculations from the quote calculations
     */
    public function prepare($invoiceItem, $lineItem)
    {
        $this->setCommonFields($lineItem);
        $this->quantity = $invoiceItem->getQty();

        if ($this->priceIncludesTax) {
            $unitPrice = $invoiceItem->getPriceInclTax();
        } else {
            $unitPrice = $invoiceItem->getPrice();
        }

        $this->discountAmount = $invoiceItem->getDiscountAmount();
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @param $invoice
     * @param $lineItem
     * @return $this
     *
     * Prepare a calculation item for getting the values related to shipping. This preparation is done so that we can
     * re-use the calculations from the quote calculations
     */
    public function prepareForShipping($invoice, $lineItem)
    {
        $this->setCommonFields($lineItem);
        $this->quantity = 1;

        if ($this->priceIncludesTax) {
            $unitPrice = $invoice->getShippingInclTax();
        } else {
            $unitPrice = $invoice->getShippingAmount();
        }

        $this->discountAmount = $invoice->getOrder()->getShippingDiscountAmount();
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $invoiceItem
     * @param $lineItem
     * @return $this
     */
    public function prepareItemGW($invoiceItem, $lineItem)
    {
        $this->setCommonFields($lineItem);
        $this->quantity = $invoiceItem->getQty();
        $this->discountAmount = 0;
        $this->unitPrice = $invoiceItem->getOrderItem()->getGwPrice();

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @param $lineItem
     * @return $this
     */
    public function prepareSalesObjectGW($object, $lineItem)
    {
        $this->setCommonFields($lineItem);
        $this->quantity = 1;
        $this->discountAmount = 0;
        $this->unitPrice = $object->getGwPrice();

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @param $lineItem
     * @return $this
     */
    public function preparePrintedCard($object, $lineItem)
    {
        $this->setCommonFields($lineItem);
        $this->quantity = 1;
        $this->discountAmount = 0;
        $this->unitPrice = $object->getGwCardPrice();

        return $this;
    }

    /**
     * @param $invoiceItem
     * @return Item
     *
     * When we use the base currency for calculating, we need to change the price and discount amount to the
     * base values.
     */
    public function setBaseCurrencyPrices($invoiceItem)
    {
        if ($this->priceIncludesTax) {
            $unitPrice = $invoiceItem->getBasePriceInclTax();
        } else {
            $unitPrice = $invoiceItem->getBasePrice();
        }

        $this->discountAmount = $invoiceItem->getBaseDiscountAmount();
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @param $invoice
     * @return Item
     *
     * When we use the base currency for calculating, we need to change the price and discount amount to the
     * base values.
     */
    public function setBaseCurrencyPricesForShipping($invoice)
    {
        if ($this->priceIncludesTax) {
            $unitPrice = $invoice->getBaseShippingInclTax();
        } else {
            $unitPrice = $invoice->getBaseShippingAmount();
        }

        $this->discountAmount = $invoice->getOrder()->getBaseShippingDiscountAmount();
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $invoiceItem
     * @return $this
     */
    public function setItemGwBaseCurrencyPrices($invoiceItem)
    {
        $this->unitPrice = $invoiceItem->getOrderItem()->getGwBasePrice();

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @return $this
     */
    public function setSalesObjectGwBaseCurrencyPrices($object)
    {
        $this->unitPrice = $object->getGwBasePrice();

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @return $this
     */
    public function setPrintedCardBaseCurrencyPrices($object)
    {
        $this->unitPrice = $object->getGwCardBasePrice();

        return $this;
    }

    private function setCommonFields($lineItem)
    {
        $this->useBaseCurrency = false;
        $this->stripeTotalCalculatedAmount = $lineItem['stripe_total_calculated_amount'];
        $this->stripeTotalCalculatedTax = $lineItem['stripe_total_calculated_tax'];
        $this->stripeCurrency = $lineItem['stripe_currency'];
        $this->priceIncludesTax = $lineItem['price_includes_tax'];

        return $this;
    }

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function getUnitPrice()
    {
        return $this->unitPrice;
    }

    public function getDiscountAmount()
    {
        return $this->discountAmount;
    }

    public function getStripeTotalCalculatedAmount()
    {
        return $this->stripeTotalCalculatedAmount;
    }

    public function getStripeTotalCalculatedTax()
    {
        return $this->stripeTotalCalculatedTax;
    }

    public function getStripeCurrency()
    {
        return $this->stripeCurrency;
    }

    public function getUseBaseCurrency()
    {
        return $this->useBaseCurrency;
    }

    public function setUseBaseCurrency($useBaseCurrency)
    {
        $this->useBaseCurrency = $useBaseCurrency;
        return $this;
    }

    public function getPriceIncludesTax()
    {
        return $this->priceIncludesTax;
    }
}