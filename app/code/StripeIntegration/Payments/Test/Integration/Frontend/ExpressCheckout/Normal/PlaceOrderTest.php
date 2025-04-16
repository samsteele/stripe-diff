<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\ExpressCheckout\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $helper;
    private $objectManager;
    private $request;
    private $session;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->request = $this->objectManager->get(\Magento\Framework\App\Request\Http::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->session = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testAddresses()
    {
        $product = $this->helper->loadProductBySku("simple-product");

        // Define the parameters as specified in the requirements
        $location = "product";
        $productId = $product->getId();

        // Call the ece_params method with the specified parameters
        $result = $this->apiService->ece_params($location, $productId);

        // Verify the response is not empty
        $this->assertNotEmpty($result);

        // Parse the JSON response
        $data = json_decode($result, true);

        // Verify the structure of the response
        $this->assertIsArray($data);
        $this->assertArrayHasKey('resolvePayload', $data);
        $this->assertArrayHasKey('elementOptions', $data);

        // Verify the contents of the resolvePayload
        $resolvePayload = $data['resolvePayload'];
        $this->assertArrayHasKey('allowedShippingCountries', $resolvePayload);
        $this->assertArrayHasKey('billingAddressRequired', $resolvePayload);
        $this->assertArrayHasKey('emailRequired', $resolvePayload);
        $this->assertArrayHasKey('phoneNumberRequired', $resolvePayload);
        $this->assertArrayHasKey('shippingAddressRequired', $resolvePayload);

        // Verify that the location parameter was passed correctly
        $this->assertTrue($resolvePayload['billingAddressRequired'], 'Billing address should be required for product page');
        $this->assertTrue($resolvePayload['shippingAddressRequired'], 'Shipping address should be required for product page');

        // Verify the elementOptions contains necessary data
        $elementOptions = $data['elementOptions'];
        $this->assertIsArray($elementOptions);

        // Test adding the product to the cart
        $request = [
            "product" => $product->getId(),
            "related_product" => "",
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

        $stripe = $this->stripeConfig->getStripeClient();
        $confirmationToken = $stripe->testHelpers->confirmationTokens->create([
            'payment_method' => 'pm_card_visa'
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

        // Load the payment intent
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($paymentIntentId);
        $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']]);

        // Stripe checks
        $this->assertEquals($order->getGrandTotal() * 100, $paymentIntent->amount);
        $this->assertEquals($order->getGrandTotal() * 100, $paymentIntent->latest_charge->amount);
        $this->assertEquals("succeeded", $paymentIntent->latest_charge->status);
        $this->assertEquals("Order #$orderIncrementId by Flint Jerry", $paymentIntent->description);
        $this->assertEquals($orderIncrementId, $paymentIntent->metadata->{"Order #"});

        // Trigger webhook events
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent, $this);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('card', $paymentMethod->getPaymentMethodType());

        // Stripe checks
        // $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

    }
}
