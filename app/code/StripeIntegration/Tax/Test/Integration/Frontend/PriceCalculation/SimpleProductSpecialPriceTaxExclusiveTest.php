<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\PriceCalculation;

use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SimpleProductSpecialPriceTaxExclusiveTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $compare;
    private $calculator;
    private $productRepository;
    private $tests;
    private $orderHelper;
    private $invoiceHelper;
    private $creditmemoHelper;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Tax\Test\Integration\Helper\Quote();
        $this->compare = new Compare($this);
        $this->calculator = new Calculator('Romania');
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->tests = new \StripeIntegration\Tax\Test\Integration\Helper\Tests();
        $this->orderHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Order();
        $this->invoiceHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Invoice();
        $this->creditmemoHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Creditmemo();
    }

    /**
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior exclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior exclusive
     */
    public function testTaxExclusive()
    {
        $taxBehaviour = 'exclusive';
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("SpecialPrice")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteItem = $this->quote->getQuoteItem('tax-simple-product-special-price');
        $product = $this->productRepository->get($quoteItem->getSku());
        $price = $product->getSpecialPrice();
        $calculatedData = $this->calculator->calculateData($price, 2, 5, $taxBehaviour);

        $this->compare->compareQuoteData($this->quote->getQuote(), $calculatedData);
        $quoteItemData = $this->calculator->calculateQuoteItemData($price, 10, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);

        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);
        $this->compare->compareOrderData($order, $calculatedData);
        $orderItem = $this->orderHelper->getOrderItem($order, 'tax-simple-product-special-price');
        $this->compare->compareOrderItemData($orderItem, $quoteItemData);

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['tax-simple-product-special-price' => 2]);
        $order = $this->orderHelper->refreshOrder($order);
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->compare->compareInvoiceData($invoice, $calculatedData);
        $invoiceItem = $this->invoiceHelper->getInvoiceItem($invoice, 'tax-simple-product-special-price');
        $this->compare->compareInvoiceItemData($invoiceItem, $quoteItemData);

        $this->assertTrue($order->canCreditmemo());

        // Create the credit memo and compare the data for it
        $shipping = 10;
        $creditmemo = $this->tests->refundOffline($order, ['tax-simple-product-special-price' => 2], $shipping);
        $order = $this->orderHelper->refreshOrder($order);
        $creditmemoData = $this->calculator->calculateData($price, 2, 5, $taxBehaviour);
        $creditmemoItemData = $this->calculator->calculateQuoteItemData($price, $shipping, 2, $taxBehaviour);
        $creditmemoItem = $this->creditmemoHelper->getCreditmemoItem($creditmemo, 'tax-simple-product-special-price');
        // Compare credit memo data
        $this->compare->compareCreditmemoData($creditmemo, $creditmemoData);
        $this->compare->compareCreditMemoItemData($creditmemoItem, $creditmemoItemData);
        // You can still create credit memos for the order
        $this->assertFalse($order->canCreditmemo());
    }
}
