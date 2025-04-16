<?php

namespace StripeIntegration\Payments\Test\Integration\Cron\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PendingPaymentOrderLifetimeTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;
    private $cronJob;
    private $service;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->cronJob = $this->objectManager->get(\Magento\Sales\Model\CronJob\CleanExpiredOrders::class);
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\ServiceInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store sales/orders/delete_pending_after -1
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testPendingPaymentOrderLifetime()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("RedirectBasedMethod");

        $order = $this->quote->placeOrder();
        $orderId = $order->getIncrementId();

        // Check that there was no new order email
        $this->assertEquals(0, $order->getEmailSent(), "The order email was sent.");

        // Order checks
        $this->assertCount(1, $order->getInvoiceCollection()); // Created in v3.4.0 and newer
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $order->getInvoiceCollection()->getFirstItem()->getState());
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals(false, $order->canEdit());
        $this->assertEquals(false, $order->canCancel()); // Disabled in v3.4.0 and newer

        // Check if the order requires further action
        $clientSecret = $this->service->get_requires_action();

        // For RedirectBasedMethod payment, action is typically required as it redirects the customer to bank login
        $this->assertNotNull($clientSecret, "Client secret should be returned when action is required");

        // Verify if the quote was restored properly
        $activeQuote = $this->quote->getQuote();
        $this->assertNotNull($activeQuote, "Quote should be restored");
        $this->assertEquals($order->getQuoteId(), $activeQuote->getId(), "Restored quote ID should match the original");
        $this->assertEquals(1, $activeQuote->getIsActive(), "Quote should be active after restoration");

        // Cancel the pending order
        $this->cronJob->execute();

        // Check if the order was canceled
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getState());
        $this->assertEquals("canceled", $order->getStatus());
    }
}
