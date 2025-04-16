<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SimpleProductVirtualSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testMixedCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SimpleProductVirtualSubscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $ordersCount = $this->tests->getOrdersCount();

        $paymentIntent = $this->tests->confirmSubscription($order);

        // Create the payment info block for $order
        $paymentInfoBlock = $this->objectManager->create(\StripeIntegration\Payments\Block\PaymentInfo\Element::class);
        $paymentInfoBlock->setOrder($order);
        $paymentInfoBlock->setInfo($order->getPayment());

        // Test the payment info block
        $paymentMethod = $paymentInfoBlock->getPaymentMethod();
        $formattedAmount = $paymentInfoBlock->getFormattedAmount();
        $paymentStatus = $paymentInfoBlock->getPaymentStatus();
        $paymentIntent = $paymentInfoBlock->getPaymentIntent();
        $subscription = $paymentInfoBlock->getSubscription();
        $setupIntent = $paymentInfoBlock->getSetupIntent();
        $subscriptionOrderUrl = $paymentInfoBlock->getSubscriptionOrderUrl($order->getIncrementId());
        $formattedSubscriptionAmount = (string)$paymentInfoBlock->getFormattedSubscriptionAmount();
        $customerId = $paymentInfoBlock->getCustomerId();

        $this->assertNotEmpty($paymentIntent);
        $this->assertNotEmpty($paymentMethod);
        $this->assertNotEmpty($subscription);

        $this->assertStringStartsWith("pm_", $paymentMethod->id);
        $this->assertEquals("$53.30", $formattedAmount);
        $this->assertEquals("succeeded", $paymentStatus);
        $this->assertStringStartsWith("pi_", $paymentIntent->id);
        $this->assertStringStartsWith("sub_", $subscription->id);
        $this->assertEmpty($setupIntent);
        $this->assertStringStartsWith("http", $subscriptionOrderUrl);
        $this->assertStringEndsWith($order->getId() . "/", $subscriptionOrderUrl);
        $this->assertEquals("$21.65 every month", $formattedSubscriptionAmount);
        $this->assertStringStartsWith("cus_", $customerId);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // The order should be invoiced and in processing state
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->getSize());

        // Check that the customer has one subscription
        $stripe = $this->stripeConfig->getStripeClient();

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertEquals(1, count($customer->subscriptions->data));
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);

        // Check the status of the invoice
        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(2, count($invoice->getAllItems()));
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals($paymentIntent->id, $invoice->getTransactionId());

        // Check that the transaction IDs have been associated with the order
        $transactions = $this->helper->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            $this->assertEquals($paymentIntent->id, $transaction->getTxnId());
            $this->assertEquals("capture", $transaction->getTxnType());
        }

        // Process a recurring subscription billing webhook
        $invoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, ['billing_reason' => 'subscription_cycle']);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("complete", $newOrder->getState());
        $this->assertEquals("complete", $newOrder->getStatus());
        $this->assertEquals(21.65, $newOrder->getGrandTotal());
        $this->assertEquals(0, $newOrder->getTotalDue());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());
        $this->assertStringContainsString("pi_", $newOrder->getInvoiceCollection()->getFirstItem()->getTransactionId());

    }
}
