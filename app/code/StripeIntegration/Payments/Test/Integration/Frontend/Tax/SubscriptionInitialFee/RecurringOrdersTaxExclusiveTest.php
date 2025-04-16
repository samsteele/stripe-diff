<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Tax\SubscriptionInitialFee;

use StripeIntegration\Tax\Test\Integration\Helper\Calculator;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RecurringOrdersTaxExclusiveTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $quote;
    private $tests;
    private $calculator;
    private $subscriptionsHelper;

    public function setUp(): void
    {
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->calculator = new Calculator('Romania');
        $this->subscriptionsHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Subscriptions($this);
    }

    /**
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/Enable.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Tax/TaxClasses.php
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior exclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior exclusive
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testRecurringOrders()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('SubscriptionInitialFee')
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        $subscriptionDetails = [
            'sku' => 'simple-monthly-subscription-initial-fee-product',
            'qty' => 1,
            'price' => 10,
            'shipping' => 5,
            'initial_fee' => 3,
            'discount' => 0,
            'tax_percent' => $this->calculator->getTaxRate(),
            'mode' => 'exclusive'
        ];
        $this->subscriptionsHelper->compareSubscriptionDetails($order, $subscriptionDetails);

        // Stripe checks
        $orderTotal = 2142; // 10 for item, 5 shipping, 3 initial fee, tax 19% for all: 18 * 1.19 = 21.42
        $subscriptionTotal = 1785; // $10 for the item, $5 for the shipping, $2.85 for tax
        $initialFee = 357; // $3 fee + $0.57 tax
        $initialFeeTax = 0.57;

        $this->assertEquals($initialFeeTax, $order->getInitialFeeTax());

        // Trigger webhook events for recurring order
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $invoice->amount = $subscriptionTotal; // Remove the initial fee from the next invoice
        $invoice->amount_paid = $subscriptionTotal;

        $this->tests->event()->trigger("charge.succeeded", $invoice->charge);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // Make sure a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Get the recurring order
        $recurringOrder = $this->tests->getLastOrder();

        // Order checks
        $this->compare->object($recurringOrder->getData(), [
            'grand_total' => round(floatval($order->getGrandTotal()) - ($initialFee / 100), 2),
            'shipping_amount' => $order->getShippingAmount(),
            'tax_amount' => round(floatval($order->getTaxAmount()) - $initialFeeTax, 2), // Minus intial fee tax
        ]);
    }
}