<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PartialCaptureCancelTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/expired_authorizations 1
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testPartialCaptures()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('Normal')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $transactionId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($transactionId);

        // Partially invoice the order
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['simple-product' => 2]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(21.65, $order->getTotalDue());
        $this->assertEquals(31.65, $order->getTotalInvoiced());
        $this->assertEquals(31.65, $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id);
        $this->tests->event()->trigger("charge.captured", $paymentIntent->latest_charge);
        $this->tests->event()->trigger("payment_intent.succeeded", $paymentIntent);

        // Stripe checks
        $this->assertEquals(5330, $paymentIntent->amount);
        $this->assertEquals(0, $paymentIntent->amount_capturable);
        $this->assertEquals(3165, $paymentIntent->amount_received);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(21.65, $order->getTotalDue());
        $this->assertEquals(31.65, $order->getTotalInvoiced());
        $this->assertEquals(31.65, $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(31.65, $invoice->getGrandTotal());
        $this->assertEquals(2, $invoice->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Cancel the remaining amount
        $this->assertTrue($order->canCancel());
        $order->cancel();
        $order = $this->tests->orderHelper->saveOrder($order);

        // Order checks
        $this->tests->compare($order->debug(), [
            "state" => "processing",
            "status" => "processing",
            "total_canceled" => 21.65,
            "total_invoiced" => $order->getGrandTotal() - $order->getTotalCanceled(),
            // "total_refunded" => 0
        ]);

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id, ['expand' => ['latest_charge']]);
        $this->tests->compare($paymentIntent, [
            "amount" => $order->getGrandTotal() * 100,
            "amount_capturable" => 0,
            "amount_received" => $order->getTotalInvoiced() * 100,
            "latest_charge" => [
                "amount" => $order->getGrandTotal() * 100,
                "amount_captured" => $order->getTotalInvoiced() * 100,
                "amount_refunded" => $order->getTotalCanceled() * 100,
            ]
        ]);
    }
}
