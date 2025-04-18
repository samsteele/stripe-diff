<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\MixedCart;

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
            ->setCart("MixedCart")
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
        $this->assertEquals("$69.80", $formattedAmount);
        $this->assertEquals("succeeded", $paymentStatus);
        $this->assertStringStartsWith("pi_", $paymentIntent->id);
        $this->assertStringStartsWith("sub_", $subscription->id);
        $this->assertEmpty($setupIntent);
        $this->assertStringStartsWith("http", $subscriptionOrderUrl);
        $this->assertStringEndsWith($order->getId() . "/", $subscriptionOrderUrl);
        $this->assertEquals("$31.65 every month", $formattedSubscriptionAmount);
        $this->assertStringStartsWith("cus_", $customerId);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->getSize());

        $stripe = $this->stripeConfig->getStripeClient();

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertEquals(1, count($customer->subscriptions->data));
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->count());

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

        // Partially refund the non-subscription items of the invoice
        $this->tests->refundOnline($invoice, ["simple-product" => 2], $baseShipping = 10);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(31.65, $order->getTotalRefunded());

        $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntent->id, ['expand' => ['latest_charge']]);
        $this->assertEquals(6980, $paymentIntent->latest_charge->amount);
        $this->assertEquals(3165, $paymentIntent->latest_charge->amount_refunded);
    }
}
