<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\TrialSimple;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiringCouponOnceTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testExpiringCouponOnce()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent_apply_once")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);

        //Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        //The subscription setup is correct.
        $this->compare->object($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "discount" => [
                "coupon" => [
                    "amount_off" => 109,
                    "duration" => "repeating",
                    "duration_in_months" => 1,
                    "name" => "$1.09 Discount",
                ]
            ],
            "status" => "trialing"
        ]);

        // Customer has no charges
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(0, $charges->data);

        // Upcoming invoice for subscription
        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);

        // Upcoming invoice has a discount and due amount is discounted (same as order grand total)
        $this->assertNotNull($upcomingInvoice->discount);
        $this->tests->compare($upcomingInvoice, [
            "amount_due" => 1474, // Discounted amount
            "amount_paid" => 0,
            "amount_remaining" => 1474,
            "total" => 1474
        ]);

        // State of order is proper.
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, ['billing_reason' => 'subscription_cycle']);

        // Order has invoice in paid status
        $this->assertEquals($order->getInvoiceCollection()->getFirstItem()->getState(), Invoice::STATE_PAID);
    }
}
