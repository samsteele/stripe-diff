<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Tax;

use StripeIntegration\Payments\Test\Integration\Helper\CreditMemoCompare;
use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SimpleProductFullCreditmemoTaxInclusiveTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $compare;
    private $calculator;
    private $productRepository;
    private $tests;
    private $orderHelper;
    private $invoiceHelper;
    private $creditmemoCompareHelper;
    private $creditmemoHelper;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new Compare($this);
        $this->calculator = new Calculator('Romania');
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->orderHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Order();
        $this->invoiceHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Invoice();
        $this->creditmemoCompareHelper = new CreditMemoCompare($this);
        $this->creditmemoHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Creditmemo();
    }

    /**
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/Enable.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxClasses.php
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior inclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior inclusive
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testTaxInclusive()
    {
        $taxBehaviour = 'inclusive';
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Simple")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("SuccessCard");

        // Get product data
        $quoteItem = $this->quote->getQuoteItem('simple-product');
        $product = $this->productRepository->get($quoteItem->getSku());
        $price = $product->getPrice();
        $calculatedData = $this->calculator->calculateData($price, 2, 5, $taxBehaviour);

        // Compare quote data
        $this->compare->compareQuoteData($this->quote->getQuote(), $calculatedData);
        $quoteItemData = $this->calculator->calculateQuoteItemData($price, 10, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);

        // Compare order data
        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);
        $this->compare->compareOrderData($order, $calculatedData);
        $orderItem = $this->orderHelper->getOrderItem($order, 'simple-product');
        $this->compare->compareOrderItemData($orderItem, $quoteItemData);

        // Get invoice data and compare the invoice data
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $order = $this->orderHelper->refreshOrder($order);
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->compare->compareInvoiceData($invoice, $calculatedData);
        $invoiceItem = $this->invoiceHelper->getInvoiceItem($invoice, 'simple-product');
        $this->compare->compareInvoiceItemData($invoiceItem, $quoteItemData);

        // You should be able to create credit memos
        $this->assertTrue($order->canCreditmemo());

        $shipping = 8.4;

        // Create the credit memo and compare the data for it
        $creditmemo = $this->tests->refundOnline($invoice, ['simple-product' => 2], $shipping);
        $order = $this->tests->refreshOrder($order);
        $this->creditmemoCompareHelper->compareCreditmemoData($creditmemo, $calculatedData);
        $creditmemoItem = $this->creditmemoHelper->getCreditmemoItem($creditmemo, 'simple-product');
        $this->creditmemoCompareHelper->compareCreditMemoItemData($creditmemoItem, $quoteItemData);

        // Compare order data having the refunded total
        $orderGrandTotal = $order->getGrandTotal();
        $this->tests->compare($order->getData(), [
            "total_invoiced" => $orderGrandTotal,
            "total_paid" => $orderGrandTotal,
            "total_due" => 0,
            "total_refunded" => $orderGrandTotal,
            "total_canceled" => 0,
            "state" => "closed",
            "status" => "closed"
        ]);
        // You should not be able to create credit memos on the order
        $this->assertFalse($order->canCreditmemo());
    }
}
