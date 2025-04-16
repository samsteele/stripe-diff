<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelReactivateSubscriptionTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $stripeConfig;
    private $subscriptionFactory;
    private $request;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->subscriptionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\SubscriptionFactory::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testCancelAndReactivateSubscription()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("SubscriptionSingle")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $this->tests->confirmSubscription($order);
        $subscriptionId = $order->getPayment()->getAdditionalInformation('subscription_id');

        // Fetch the customer's active subscriptions
        $stripeCustomer = $this->tests->helper()->getCustomerModel();
        $subscriptions = $stripeCustomer->getSubscriptions();

        // Subscription should be active
        $this->assertEquals(1, count($subscriptions));
        /** @var \Stripe\Subscription $subscription */
        $subscription = array_pop($subscriptions);

        $this->assertNotEmpty($subscription);
        $this->assertEquals("active", $subscription->status);

        // Test the cancel subscription functionality
        $subscriptionId = $subscription->id;

        // Associate the subscription with our customer
        $subscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
        $this->assertNotEmpty($subscriptionModel);

        // Create an instance of the cancel controller
        $cancelController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Subscriptions\Cancel::class);

        // Configure the request
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('form_key', 'testFormKey123');

        $result = $cancelController->createCsrfValidationException($this->request);
        $this->assertNull($result);

        $result = $cancelController->validateForCsrf($this->request);
        $this->assertFalse($result);

        // Execute the cancel controller
        $response = $cancelController->execute();

        // Validate that the subscription was canceled
        $canceledSubscription = $this->stripeConfig->getStripeClient()->subscriptions->retrieve(
            $subscriptionId,
            []
        );

        $this->assertEquals('canceled', $canceledSubscription->status);

        // Verify the customer is redirected to the subscriptions page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // Test the reactivate subscription functionality

        // Create an instance of the reactivate controller
        $reactivateController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Subscriptions\Reactivate::class);

        // Configure the request for reactivation
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('form_key', 'testFormKey123');

        // Test CSRF validation
        $result = $reactivateController->createCsrfValidationException($this->request);
        $this->assertNull($result);

        $result = $reactivateController->validateForCsrf($this->request);
        $this->assertFalse($result);

        // Execute the reactivate controller
        $response = $reactivateController->execute();

        // Fetch the customer's active subscriptions
        $subscriptions = $stripeCustomer->getSubscriptions();
        $this->assertEquals(1, count($subscriptions));
        $reactivatedSubscription = array_pop($subscriptions);

        // Subscription should now be active again
        $this->assertEquals('active', $reactivatedSubscription->status);

        // Verify the customer is redirected to the subscriptions page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // The redirect URL should be the subscriptions page
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('stripe/customer/subscriptions', $redirectUrl);

        // Test canceling the subscription again
        $response = $cancelController->execute();

        // Validate that the subscription was canceled again
        $canceledSubscription = $this->stripeConfig->getStripeClient()->subscriptions->retrieve(
            $subscriptionId,
            []
        );
        $this->assertEquals('canceled', $canceledSubscription->status);

        // Delete all saved payment methods for the customer
        $stripeClient = $this->stripeConfig->getStripeClient();
        $customerId = $stripeCustomer->getStripeId();

        // Get all payment methods
        $paymentMethods = $stripeClient->paymentMethods->all([
            'customer' => $customerId,
            'type' => 'card'
        ]);

        // Delete each payment method
        foreach ($paymentMethods->data as $paymentMethod) {
            $stripeClient->paymentMethods->detach($paymentMethod->id);
        }

        // Verify all payment methods were deleted
        $paymentMethods = $stripeClient->paymentMethods->all([
            'customer' => $customerId,
            'type' => 'card'
        ]);
        $this->assertEquals(0, count($paymentMethods->data), 'All payment methods should be deleted');

        // Completely clear/reset the quote
        $this->quote->reset();

        // Try to reactivate the subscription (should redirect to checkout since payment method is missing)
        $response = $reactivateController->execute();

        // The reactivate method in the Model should return 'checkout' when a payment method is needed
        // This should redirect to a page where customers can add a new payment method
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // The redirect URL should be the checkout page
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('checkout', $redirectUrl);

        // Check if we have session data for subscription reactivation
        $checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $subscriptionReactivateDetails = $checkoutSession->getSubscriptionReactivateDetails();
        $this->assertNotEmpty($subscriptionReactivateDetails, 'Subscription reactivate details should be set in session');
        $this->assertEquals($subscriptionId, $subscriptionReactivateDetails['update_subscription_id']);
        $this->assertArrayHasKey('subscription_data', $subscriptionReactivateDetails);
    }
}
