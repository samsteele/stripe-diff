<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $service;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->service = $this->tests->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Verify that a checkout session ID is returned after placing the order
        $checkoutSessionId = $this->service->get_checkout_session_id();
        $this->assertNotEmpty($checkoutSessionId, "The checkout session ID should not be empty after placing an order");

        // Confirm the payment
        $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertCount(1, $order->getInvoiceCollection());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($order->getGrandTotal(), $invoice->getGrandTotal());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Switch to the admin area
        $this->objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        $order = $this->tests->refreshOrder($order);

        // Create the payment info block for $order
        $this->assertNotEmpty($this->tests->renderPaymentInfoBlock(\StripeIntegration\Payments\Block\PaymentInfo\Checkout::class, $order));
    }
}
