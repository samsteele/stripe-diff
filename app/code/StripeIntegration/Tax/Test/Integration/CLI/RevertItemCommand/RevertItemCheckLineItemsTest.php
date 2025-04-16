<?php

namespace StripeIntegration\Tax\Test\Integration\CLI\RevertItemCommand;

use StripeIntegration\Tax\Commands\RevertItem;
use Symfony\Component\Console\Tester\CommandTester;
/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */class RevertItemCheckLineItemsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $orderHelper;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Tax\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Tax\Test\Integration\Helper\Tests();
        $this->orderHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Order();
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

        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['tax-simple-product' => 2]);
        $order = $this->orderHelper->refreshOrder($order);

        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 1,
            '--shipping-amount' => 10,
        ]);
        $this->assertStringContainsString('The following reversal(s) were created:', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());

        sleep(1);

        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 2
        ]);
        $this->assertStringContainsString('The amount specified for the item reversal is too high. Please provide a value less or equal to ', $tester->getDisplay());
        $this->assertNotEquals(0, $tester->getStatusCode());
    }
}