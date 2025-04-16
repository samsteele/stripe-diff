<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Tax\BaseOrderValues;

use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

class DifferentCurrenciesTaxInclusiveTest extends AbstractBaseValues
{
    private $quote;
    private $compare;
    private $calculator;
    private $orderHelper;
    private $invoiceHelper;

    public function setUp(): void
    {
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new Compare($this);
        $this->calculator = new Calculator('Romania');
        $this->orderHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Order();
        $this->invoiceHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Invoice();
    }

    /**
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/Enable.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxClasses.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/ExchangeRatesEURUSD.php
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior inclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior inclusive
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testTaxExclusive()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Simple")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("SuccessCard");
        $taxBehaviour = 'inclusive';

        // Compare order data
        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);

        $orderItem = $this->orderHelper->getOrderItem($order, 'simple-product');
        $price = $orderItem->getPriceInclTax();
        // USD -> EUR
        $baseToOrderRatio = $order->getBaseToOrderRate();
        // get shipping price using the ratio
        $shippingPrice = round(5 * $baseToOrderRatio, 2);
        // get the inverse of the base to order ratio to have it for checking the base values (EUR -> USD)
        $orderToBaseRatio = round(1 / $baseToOrderRatio, 4);

        $calculatedData = $this->calculator->calculateData($price, 2, $shippingPrice, $taxBehaviour);
        $calculatedItemData = $this->calculator->calculateQuoteItemData($price, 2 * $shippingPrice, 2, $taxBehaviour);

        // calculate the data using the current values multiplied by the inverse of the base to order ratio to get the values in USD
        $calculatedBaseData = $this->getBaseValuesArray($this->calculator->calculateData(round($price * $orderToBaseRatio, 2), 2, round($shippingPrice * $orderToBaseRatio, 2), $taxBehaviour));
        $calculatedBaseItemData = $this->getBaseValuesArray($this->calculator->calculateQuoteItemData(round($price * $orderToBaseRatio, 2), 2 * round($shippingPrice * $orderToBaseRatio, 2), 2, $taxBehaviour));

        $this->compare->compareOrderData($order, $calculatedData);
        $this->compare->compareOrderItemData($orderItem, $calculatedItemData);
        $this->compare->compareOrderData($order, $calculatedBaseData);
        $this->compare->compareOrderItemData($orderItem, $calculatedBaseItemData);

        // Get invoice data and compare the invoice data
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $order = $this->orderHelper->refreshOrder($order);
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->compare->compareInvoiceData($invoice, $calculatedData);
        $this->compare->compareInvoiceData($invoice, $calculatedBaseData);
        $invoiceItem = $this->invoiceHelper->getInvoiceItem($invoice, 'simple-product');
        $this->compare->compareInvoiceItemData($invoiceItem, $calculatedItemData);
        $this->compare->compareInvoiceItemData($invoiceItem, $calculatedBaseItemData);
    }
}