<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\DynamicBundleMixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    public function testDynamicBundleMixedTrialCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("DynamicBundleMixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();
        $this->assertEquals(53.30, $quote->getGrandTotal());

        // Place the order
        $order = $this->quote->placeOrder();
        $this->assertEquals(0, $order->getGrandTotal());
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $ordersCount = $this->tests->getOrdersCount();

        // Assert order status, amount due, invoices
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Check that the subscription plan amount is correct
        $customerModel = $this->tests->helper()->getCustomerModel();
        $customer = $this->tests->stripe()->customers->retrieve($customerModel->getStripeId(), [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);
        $trialSubscriptionTotal = 53.30;
        $expectedChargeAmount = $order->getGrandTotal();
        $expectedChargeAmountStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);
        $trialSubscriptionTotalStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($trialSubscriptionTotal, $currency);
        $this->assertEquals($trialSubscriptionTotalStripe, $subscription->plan->amount);

        // Check that the last subscription invoice matched the order total
        $latestInvoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice, []);
        $this->assertEquals($expectedChargeAmountStripe, $latestInvoice->amount_paid);

        $subscriptionId = $subscription->id;

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->tests->compare($order->getData(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => 0
        ]);
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memos check
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize());

        // End the trial
        $this->tests->endTrialSubscription($subscriptionId);
        $order = $this->tests->refreshOrder($order);

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->tests->compare($newOrder->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => $trialSubscriptionTotal,
            "total_due" => 0,
            "total_paid" => $newOrder->getGrandTotal(),
            "total_refunded" => 0,
            "shipping_amount" => 10
        ]);
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());

        // Check that the new order includes the bundle item and not the simple subscription item
        foreach ($newOrder->getAllVisibleItems() as $orderItem)
        {
            $this->assertEquals("bundle", $orderItem->getProductType());
        }

        // Process a recurring subscription billing webhook
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscriptionId, []);
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, ['billing_reason' => 'subscription_cycle']);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->tests->compare($newOrder->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => $trialSubscriptionTotal,
            "total_due" => 0,
            "total_paid" => $newOrder->getGrandTotal(),
            "total_refunded" => 0,
            "shipping_amount" => 10
        ]);
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        // Check that the new order includes the bundle item and not the simple subscription item
        foreach ($newOrder->getAllVisibleItems() as $orderItem)
        {
            $this->assertEquals("bundle", $orderItem->getProductType());
        }
    }
}
