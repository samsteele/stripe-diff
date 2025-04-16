<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\EmbeddedFlow\AuthorizeCapture\DynamicBundleMixedTrialInitialFee;

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

    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("DynamicBundleMixedTrialInitialFee")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();
        // 66.29 = 53.30 for the trial subscription, 12 for the initial fee, 0.99 for the initial fee tax
        $this->assertEquals(66.29, $quote->getGrandTotal());

        // Place the order
        $order = $this->quote->placeOrder();

        $initialFee = 12.99;
        $this->assertEquals($initialFee, $order->getGrandTotal());
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
        $customer = $this->tests->stripe()->customers->retrieve($order->getPayment()->getAdditionalInformation("customer_stripe_id"), [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);
        // The subscription plan should include the subscription price + shipping + tax on these 2 prices.
        // It should not include the initial fee and tax for initial fee, so we will take out the
        // initial fee tax amount from the expected total
        $expectedChargeAmount = $initialFee;
        $expectedChargeAmountStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);
        $this->assertEquals(5330, $subscription->plan->amount);

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
            "grand_total" => 53.30,
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
            "grand_total" => 53.30,
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
