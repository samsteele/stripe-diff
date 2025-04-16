<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use StripeIntegration\Payments\Test\Integration\Mock\StripeIntegration\Payments\Model\Stripe\Event\InvoiceUpcoming as MockInvoiceUpcoming;
/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DynamicTaxMulticurrencyTaxInclTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $orderHelper;
    private $address;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $this->objectManager->configure([
            'preferences' => [
                \StripeIntegration\Payments\Model\Stripe\Event\InvoiceUpcoming::class => MockInvoiceUpcoming::class,
            ]
        ]);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->orderHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Order::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     *
     * @magentoConfigFixture current_store customer/create_account/default_group 1
     * @magentoConfigFixture current_store customer/create_account/auto_group_assign 1
     * @magentoConfigFixture current_store tax/classes/shipping_tax_class 2
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/shipping_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/discount_tax 1
     */
    public function testDynamicTax()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Get the order tax percent
        $appliedTaxes = $this->orderHelper->getAppliedTaxes($order->getId());
        $this->assertCount(1, $appliedTaxes);
        $this->assertEquals("8.2500", $appliedTaxes[0]['percent']);

        // Stripe checks
        $orderTotal = $order->getGrandTotal() * 100;

        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->tests->compare($paymentIntent, [
            "amount" => $orderTotal,
            "amount_received" => $orderTotal
        ]);

        $customerId = $paymentIntent->customer;
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
                            "amount" => $orderTotal,
                            "currency" => "eur",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $orderTotal
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active"
        ]);

        // Change the order's shipping and billing address, so that the tax rate becomes 8.375%
        $newYorkData = $this->address->getMagentoFormat("NewYork");
        $order->getShippingAddress()->addData($newYorkData)->save();
        $order->getBillingAddress()->addData($newYorkData)->save();
        $this->tests->helper()->clearCache();

        // Increase the product price by $10
        $sku = $order->getItemsCollection()->getFirstItem()->getSku();
        $product = $this->tests->getProduct($sku);
        $product->setPrice($product->getPrice() + 10)->save();

        // Count the active quotes
        $count = $this->countActiveQuotes();

        // Trigger an invoice.upcoming webhook
        $this->tests->event()->trigger("invoice.upcoming", $subscription->latest_invoice);

        // Count the active quotes
        $this->assertEquals($count, $this->countActiveQuotes());

        // The subscription price should now have been updated to match the new tax rate. Check if that is indeed the case
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscription->id);

        $productPriceInternalRoundingError = 0.01;
        $shippingPriceInternalRoundingError = 0.01;

        // €8.50 product price tax inclusive, €4.25 shipping
        $expectedTotal = (
            2 * (8.5 + $productPriceInternalRoundingError) +
            (2 * 4.25) + $shippingPriceInternalRoundingError
         ) * 100;
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $expectedTotal,
                            "currency" => "eur",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $expectedTotal
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active"
        ]);

        // Change the original tax back to 8.25%
        $newYorkData = $this->address->getMagentoFormat("California");
        $order->getShippingAddress()->addData($newYorkData)->save();
        $order->getBillingAddress()->addData($newYorkData)->save();

        // Triggering a second invoice.upcoming and check price again
        $this->tests->event()->trigger("invoice.upcoming", $subscription->latest_invoice);
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscription->id);
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $expectedTotal,
                            "currency" => "eur",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $expectedTotal
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active"
        ]);
    }

    protected function countActiveQuotes()
    {
        $quoteCollection = $this->objectManager->create(\Magento\Quote\Model\ResourceModel\Quote\Collection::class);
        $quoteCollection->addFieldToFilter('is_active', 1);
        return $quoteCollection->count();
    }
}
