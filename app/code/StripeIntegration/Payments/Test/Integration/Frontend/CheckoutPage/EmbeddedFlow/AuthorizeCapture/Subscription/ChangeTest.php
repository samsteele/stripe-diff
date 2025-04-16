<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChangeTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $objectManager;
    private $quote;
    private $tests;
    private $subscriptionOptionsCollectionFactory;
    private $customerSubscriptionsController;
    private $orderServicePlugin;
    private $request;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->subscriptionOptionsCollectionFactory = $this->objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions\CollectionFactory::class);
        $this->customerSubscriptionsController = $this->objectManager->get(\StripeIntegration\Payments\Controller\Subscriptions\Change::class);
        $this->orderServicePlugin = $this->objectManager->get(\StripeIntegration\Payments\Plugin\Sales\Model\Service\OrderService::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testUpgrade()
    {
        $product = $this->tests->getProduct('simple-monthly-subscription-product');
        $product->setSubscriptionOptions([
            'upgrades_downgrades' => 1,
            'upgrades_downgrades_use_config' => 0,
        ]);
        $this->tests->helper()->saveProduct($product);

        $subscriptionOptionsCollection = $this->subscriptionOptionsCollectionFactory->create();
        $subscriptionOptionsCollection->addFieldToFilter('product_id', $product->getId());
        $this->assertCount(1, $subscriptionOptionsCollection->getItems());

        $this->quote->create()
            ->addProduct('simple-monthly-subscription-product', 1)
            ->loginOpc()
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $subscription = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $subscription->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);

        // Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        // The customer has 1 charge
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);

        $subscription = $customer->subscriptions->data[0];
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
                        "quantity" => 1,
                        "plan" => [
                            "amount" => 1583
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active",
            "discount" => null
        ]);

        // Upgrade from 1 to 2
        $this->quote->create()->loginOpc()->setPaymentMethod("SubscriptionUpdate")->save();

        // Change the subscription
        $this->request->setParam("subscription_id", $subscription->id);
        $this->customerSubscriptionsController->execute();

        // Change the product qty in the cart from 1 to 2
        $quote = $this->quote->getQuote();
        $item = $quote->getItemsCollection()->getFirstItem();
        $item->setQty(2);
        $quote->save();

        // Place the order
        $newOrder = $this->quote->loginOpc()->setPaymentMethod("SubscriptionUpdate")->placeOrder();
        $subscriptionId = $newOrder->getPayment()->getAdditionalInformation("subscription_id");
        $this->tests->event()->trigger("customer.subscription.updated", $subscriptionId);

        // Refresh the order object
        $newOrder = $this->tests->refreshOrder($newOrder);

        // The order should be closed
        $this->tests->compare($newOrder->getData(), [
            "state" => "complete",
            "status" => "complete"
        ]);

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => 3165
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $newOrder->getIncrementId(),
                "SubscriptionProductIDs" => $product->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active"
        ]);

        // Check if recurring orders work
        $ordersCount = $this->tests->getOrdersCount();
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, [
            'billing_reason' => 'subscription_cycle'
        ]);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the new order
        $recurringOrder = $this->tests->getLastOrder();
        $this->assertEquals(31.65, $recurringOrder->getGrandTotal());
    }

    public function testDowngrade()
    {
        $product = $this->tests->getProduct('simple-monthly-subscription-product');
        $product->setSubscriptionOptions([
            'upgrades_downgrades' => 1,
            'upgrades_downgrades_use_config' => 0,
        ]);
        $this->tests->helper()->saveProduct($product);

        $subscriptionOptionsCollection = $this->subscriptionOptionsCollectionFactory->create();
        $subscriptionOptionsCollection->addFieldToFilter('product_id', $product->getId());
        $this->assertCount(1, $subscriptionOptionsCollection->getItems());

        $this->quote->create()
            ->addProduct('simple-monthly-subscription-product', 2)
            ->loginOpc()
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $subscription = $this->tests->confirmSubscription($order);
        $this->orderServicePlugin->postProcess($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $subscription->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);

        // Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        // The customer has 1 charge
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);

        $subscription = $customer->subscriptions->data[0];
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
                        "quantity" => 1,
                        "plan" => [
                            "amount" => 3165
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active",
            "discount" => null
        ]);

        // Downgrade from 2 to 1
        $this->quote->create()->loginOpc()->setPaymentMethod("SubscriptionUpdate")->save();

        // Change the subscription
        $this->request->setParam("subscription_id", $subscription->id);
        $this->customerSubscriptionsController->execute();

        // Change the product qty in the cart
        $quote = $this->quote->getQuote();
        $quote->getItemsCollection()->getFirstItem()->setQty(1);
        $quote->save();

        // Place the order
        $newOrder = $this->quote->loginOpc()->setPaymentMethod("SubscriptionUpdate")->placeOrder();
        $subscriptionId = $newOrder->getPayment()->getAdditionalInformation("subscription_id");
        $this->tests->event()->trigger("customer.subscription.updated", $subscriptionId);

        // Refresh the order object
        $newOrder = $this->tests->refreshOrder($newOrder);

        // The order should be closed
        $this->tests->compare($newOrder->getData(), [
            "state" => "complete",
            "status" => "complete"
        ]);

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => 1583
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $newOrder->getIncrementId(),
                "SubscriptionProductIDs" => $product->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active"
        ]);

        // Check if recurring orders work
        $ordersCount = $this->tests->getOrdersCount();
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, [
            'billing_reason' => 'subscription_cycle'
        ]);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the new order
        $recurringOrder = $this->tests->getLastOrder();
        $this->assertEquals(15.83, $recurringOrder->getGrandTotal());
    }
}
