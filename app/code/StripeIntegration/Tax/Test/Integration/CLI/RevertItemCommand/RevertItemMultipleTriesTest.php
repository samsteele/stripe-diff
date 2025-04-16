<?php

namespace StripeIntegration\Tax\Test\Integration\CLI\RevertItemCommand;

use StripeIntegration\Tax\Commands\RevertItem;
use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;
use Symfony\Component\Console\Tester\CommandTester;
/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */class RevertItemMultipleTriesTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $compare;
    private $calculator;
    private $productRepository;
    private $tests;
    private $orderHelper;
    private $invoiceHelper;

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
    }

    /**
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior exclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior exclusive
     */
    public function testTaxExclusive()
    {
        $taxBehaviour = 'exclusive';
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteItem = $this->quote->getQuoteItem('tax-simple-product');
        $product = $this->productRepository->get($quoteItem->getSku());
        $price = $product->getPrice();
        $calculatedData = $this->calculator->calculateData($price, 2, 5, $taxBehaviour);

        $this->compare->compareQuoteData($this->quote->getQuote(), $calculatedData);
        $quoteItemData = $this->calculator->calculateQuoteItemData($price, 10, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);

        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);
        $this->compare->compareOrderData($order, $calculatedData);
        $orderItem = $this->orderHelper->getOrderItem($order, 'tax-simple-product');
        $this->compare->compareOrderItemData($orderItem, $quoteItemData);

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['tax-simple-product' => 2]);
        $order = $this->orderHelper->refreshOrder($order);
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->compare->compareInvoiceData($invoice, $calculatedData);
        $invoiceItem = $this->invoiceHelper->getInvoiceItem($invoice, 'tax-simple-product');
        $this->compare->compareInvoiceItemData($invoiceItem, $quoteItemData);

        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 1,
            '--shipping-amount' => 10
        ]);
        $this->assertStringContainsString('The following reversal(s) were created:', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());

        // Pause for 1 second so that we don't get identical references
        sleep(1);

        // Re-run the command on a different instance of the command class, to replicate separate runs of the command.
        // Keep the shipping in the command to check the message for when the shipping is not used, if the transaction
        // does not have shipping to revert.
        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 1,
            '--shipping-amount' => 10
        ]);
        $this->assertStringContainsString('The transactions have no available shipping tax for reversal on them. The --shipping parameter value will be ignored.', $tester->getDisplay());
        $this->assertStringContainsString('The following reversal(s) were created:', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());
    }
}