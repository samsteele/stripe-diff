<?php

namespace StripeIntegration\Tax\Test\Integration\CLI\RevertItemCommand;

use StripeIntegration\Tax\Commands\RevertItem;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RevertDynamicBundleMultipleInvoicesTest extends \PHPUnit\Framework\TestCase
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
            ->setCustomer('LoggedIn')
            ->setCart("BundleProductDynamicX3")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        // Compare Order data
        $order = $this->quote->placeOrder();
        $order = $this->orderHelper->refreshOrder($order);

        // Create invoice and compare data
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['tax-bundle-dynamic-tax-simple-product-bundle-1-tax-simple-product-bundle-3' => 2]);
        $order = $this->orderHelper->refreshOrder($order);
        sleep(1);
        $this->tests->invoiceOnline($order, ['tax-bundle-dynamic-tax-simple-product-bundle-1-tax-simple-product-bundle-3' => 1]);
        $order = $this->orderHelper->refreshOrder($order);

        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-bundle-dynamic-tax-simple-product-bundle-1-tax-simple-product-bundle-3',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 3,
            '--shipping-amount' => 15
        ]);
        $this->assertStringContainsString('The following reversal(s) were created:', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());
    }
}
