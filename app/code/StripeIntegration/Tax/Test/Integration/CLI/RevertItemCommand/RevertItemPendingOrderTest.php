<?php

namespace StripeIntegration\Tax\Test\Integration\CLI\RevertItemCommand;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use StripeIntegration\Tax\Commands\RevertItem;
use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;
use Symfony\Component\Console\Tester\CommandTester;
/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */class RevertItemPendingOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $compare;
    private $calculator;
    private $productRepository;
    private $orderHelper;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Tax\Test\Integration\Helper\Quote();
        $this->compare = new Compare($this);
        $this->calculator = new Calculator('Romania');
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
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

        // Order should be in pending status, so its items cannot be reverted
        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 2,
            '--shipping-amount' => 10
        ]);
        $this->assertStringContainsString('This order is in pending status and no items can be reversed.', $tester->getDisplay());
        $this->assertNotEquals(0, $tester->getStatusCode());

        // Change order status to pending payment
        $this->orderHelper->changeStatus($order, Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        // The items should still not be reverted and the message should reflect the different status
        $command = $this->objectManager->create(RevertItem::class);
        $tester = new CommandTester($command);
        $tester->execute([
            '--order-item-sku' => 'tax-simple-product',
            '--order-increment-id' => $order->getIncrementId(),
            '--quantity' => 2,
            '--shipping-amount' => 10
        ]);
        $this->assertStringContainsString('This order is in pending payment state and no items can be reversed.', $tester->getDisplay());
        $this->assertNotEquals(0, $tester->getStatusCode());
    }
}