<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\FixedBundleMixedTrial;

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

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     */
    public function testFixedBundleMixedTrialCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("FixedBundleMixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $quote = $this->quote->getQuote();

        // Place the order
        $order = $this->quote->placeOrder();

        // 96.60 for the bundled trial subscription + 31.65 for the regular products
        $this->assertEquals(31.65, $order->getGrandTotal());

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();
        $expectedChargeAmount = 31.65;

        $expectedChargeAmount = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);

        // Retrieve the created session
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $this->assertNotEmpty($checkoutSessionId);

        $stripe = $this->tests->stripe();
        $session = $stripe->checkout->sessions->retrieve($checkoutSessionId);

        $this->tests->log($session);
        $this->assertEquals($expectedChargeAmount, $session->amount_total);

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order, "FixedBundleMixedTrial");
        $response = $this->tests->checkout()->confirm($session, $order, $method, "California");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Assert order status, amount due, invoices
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Stripe subscription checks
        $customer = $stripe->customers->retrieve($session->customer, [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);
        $this->tests->log($subscription);
        $this->assertEquals(9660, $subscription->items->data[0]->price->unit_amount);

        $subscriptionId = $subscription->id;

        // Process the charge.succeeded event
        $this->tests->event()->trigger("charge.succeeded", $paymentIntent->latest_charge);

        // Process invoice.payment_succeeded event
        $ordersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $customer = $stripe->customers->retrieve($session->customer, [
            'expand' => ['subscriptions']
        ]);
        $invoiceId = $customer->subscriptions->data[0]->latest_invoice;
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId);

        // Ensure that no new order was created
        $newOrdersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        $ordersCount = $this->tests->getOrdersCount();

        // End the trial
        $this->tests->endTrialSubscription($subscriptionId);

        // Ensure that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(96.60, $newOrder->getGrandTotal());
        $this->assertEquals(96.60, $newOrder->getTotalPaid());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());

        // Process a recurring subscription billing webhook
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }
}
