<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\ExpressCheckout\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionPriceCommandTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $subscriptionPriceCommand;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);

        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testSubscriptionMigration()
    {
        $monthlySubscriptionProduct = $this->helper->loadProductBySku("simple-monthly-subscription-product");
        $configurableSubscriptionProduct = $this->helper->loadProductBySku("configurable-subscription");
        $attributeId = $this->quote->getAttributeIdByAttributeCode("subscription");

        $request = [
            "product" => $configurableSubscriptionProduct->getId(),
            "selected_configurable_option" => $monthlySubscriptionProduct->getId(),
            "related_product" => "",
            "item" => $configurableSubscriptionProduct->getId(),
            "super_attribute" => [$attributeId => "monthly"],
            "qty" => 1
        ];
        $result = $this->apiService->addtocart($request);
        $this->assertEquals("[]", $result);

        $address = $this->tests->address()->getExpressCheckoutElementFormat("NewYork");

        $result = $this->apiService->ece_shipping_address_changed($address, "checkout");
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["resolvePayload"]['lineItems']);
        $this->assertNotEmpty($data["resolvePayload"]['shippingRates']);

        $selectedShippingMethod = $data["resolvePayload"]['shippingRates'][0];
        $result = $this->apiService->ece_shipping_rate_changed($address, $selectedShippingMethod["id"]);
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["resolvePayload"]['lineItems']);
        $this->assertNotEmpty($data["resolvePayload"]['shippingRates']);

        $stripe = $this->tests->stripe();
        $confirmationToken = $stripe->testHelpers->confirmationTokens->create([
            'payment_method' => 'pm_card_visa',
            'setup_future_usage' => 'off_session'
        ]);
        $this->assertNotEmpty($confirmationToken);
        $this->assertNotEmpty($confirmationToken->id);

        $address = $this->tests->address()->getStripeFormat("NewYork");
        $result = [
            "elementType" => "expressCheckout",
            "expressPaymentType" => "link",
            "billingDetails" => $address,
            "shippingAddress" => $address,
            "shippingRate" =>  $selectedShippingMethod,
            "confirmationToken" =>  $confirmationToken
        ];

        $result = $this->apiService->place_order($result, "product");
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data["redirect"]);
        $this->assertStringContainsString("checkout/onepage/success", $data["redirect"]);

        // Load the order
        $session = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->assertNotEmpty($session->getLastRealOrderId());
        $orderIncrementId = $session->getLastRealOrderId();
        $order = $this->tests->getLastOrder();
        $this->assertEquals($orderIncrementId, $order->getIncrementId());

        // Load the customer
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);

        // Stripe checks
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);
        $invoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice);
        $this->compare->object($invoice, [
            "amount_due" => 1584,
            "amount_paid" => 1584,
            "amount_remaining" => 0,
            "paid" => 1,
            "status" => "paid",
            "total" => 1584
        ]);
        $this->assertCount(1, $invoice->lines->data);

        // Trigger webhook events
        $this->tests->event()->triggerSubscriptionEvents($subscription, $this);

        // Reset
        $this->helper->clearCache();

        // Change the subscription price
        $monthlySubscriptionProduct->setPrice(15);
        $monthlySubscriptionProduct = $this->tests->saveProduct($monthlySubscriptionProduct);
        $productId = $monthlySubscriptionProduct->getId();

        // Migrate the existing subscription to the new price
        $inputFactory = $this->objectManager->get(\Symfony\Component\Console\Input\ArgvInputFactory::class);
        $input = $inputFactory->create([
            "argv" => [
                null,
                $productId,
                $productId,
                $order->getId(),
                $order->getId()
            ]
        ]);
        $output = $this->objectManager->get(\Symfony\Component\Console\Output\ConsoleOutput::class);

        $orderCount = $this->tests->getOrdersCount();

        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
        $this->assertEquals(0, $exitCode);

        // Ensure that a new order was created
        $newOrderCount = $this->tests->getOrdersCount();
        $this->assertEquals($orderCount + 1, $newOrderCount);

        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId, [
            'expand' => ['subscriptions']
        ]);
        $this->assertCount(1, $customer->subscriptions->data);

        // Trigger webhooks
        $subscription = $customer->subscriptions->data[0];
        $this->tests->event()->triggerSubscriptionEvents($subscription, $this);

        // Stripe checks
        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);
        $this->compare->object($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $newOrder->getGrandTotal() * 100,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $newOrder->getGrandTotal() * 100
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Type" => "SubscriptionsTotal",
                "SubscriptionProductIDs" => $monthlySubscriptionProduct->getId()
            ],
            "status" => "active"
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->compare->object($upcomingInvoice, [
            "total" => $newOrder->getGrandTotal() * 100
        ]);
    }
}
