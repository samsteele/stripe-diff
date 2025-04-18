<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionStartDates;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class StartDateTodayTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $objectManager;
    private $quote;
    private $tests;
    private $subscriptionOptionsCollectionFactory;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->subscriptionOptionsCollectionFactory = $this->objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions\CollectionFactory::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPlaceOrder()
    {
        $day = date("d");

        if ($day > 28)
        {
            // The start date will be brought forward to 1st of next month in this case, which is not today
            $this->markTestSkipped("The test will fail if the current day is greater than 28th of the month");
        }

        $product = $this->tests->getProduct('simple-monthly-subscription-product');
        $product->setSubscriptionOptions([
            'start_on_specific_date' => 1,
            'start_date' => "2021-01-$day",
            'first_payment' => 'on_start_date'
        ]);
        $this->tests->helper()->saveProduct($product);

        $subscriptionOptionsCollection = $this->subscriptionOptionsCollectionFactory->create();
        $subscriptionOptionsCollection->addFieldToFilter('product_id', $product->getId());
        $this->assertCount(1, $subscriptionOptionsCollection->getItems());

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $subscriptionId = $order->getPayment()->getAdditionalInformation("subscription_id");
        $this->tests->event()->triggerSubscriptionEventsById($subscriptionId);
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscriptionId);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $subscription->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);

        // Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        // The customer has no charges
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);

        // Start date is today, so the subscription starts immediately
        $this->assertCount(1, $charges->data);
        $this->assertEquals(3165, $charges->data[0]->amount);
        $expectedOrderState = "processing";
        $expectedOrderStatus = "processing";
        $expectedPaidAmount = 31.65;

        $subscription = $customer->subscriptions->data[0];
        // Get the subscription start date
        $subscriptionStartDate = $subscription->billing_cycle_anchor;

        // The subscription start date should be the 10th of the month
        $this->assertEquals($day, date("d", $subscriptionStartDate));

        $this->compare->object($subscription, [
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
                "Order #" => $order->getIncrementId(),
                "SubscriptionProductIDs" => $product->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active",
            "discount" => null
        ]);

        // The order should be canceled
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->getData(),[
            "grand_total" => $expectedPaidAmount,
            "state" => $expectedOrderState,
            "status" => $expectedOrderStatus,
            'total_paid' => $expectedPaidAmount,
            "total_invoiced" => $expectedPaidAmount,
            'total_refunded' => null
        ]);

        // Trigger the next subscription payment immediately
        $subscription = $this->tests->stripe()->subscriptions->update($subscription->id, [
            'billing_cycle_anchor' => 'now',
            'proration_behavior' => "none",
            'expand' => ['latest_invoice']
        ]);

        // Create a recurring order
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, [
            'billing_reason' => 'subscription_cycle'
        ]);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Make sure that the new order amount is the same
        $recurringOrder = $this->tests->getLastOrder();
        $this->tests->compare($recurringOrder->getData(), [
            'grand_total' => 31.65
        ]);
    }
}
