<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ChangePaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $stripeConfig;
    private $request;
    private $stripeCustomer;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
        $this->stripeCustomer = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class)->getCustomerModel();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     */
    public function testChangePaymentMethod()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("SubscriptionSingle")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Confirm the subscription
        $this->tests->confirmSubscription($order);
        $subscriptionId = $order->getPayment()->getAdditionalInformation('subscription_id');

        // Fetch the customer's active subscriptions
        $subscriptions = $this->stripeCustomer->getSubscriptions();

        // Verify subscription is active
        $this->assertEquals(1, count($subscriptions));
        $subscription = array_pop($subscriptions);
        $this->assertNotEmpty($subscription);
        $this->assertEquals("active", $subscription->status);

        // Get the current payment method ID
        $currentPaymentMethodId = $subscription->default_payment_method;
        $this->assertNotEmpty($currentPaymentMethodId, "Default payment method should be set");

        // Create a new payment method using pm_card_visa test token
        $stripeClient = $this->stripeConfig->getStripeClient();
        $customerId = $this->stripeCustomer->getStripeId();

        // Create a payment method using the Visa test card token
        $paymentMethod = $stripeClient->paymentMethods->retrieve('pm_card_visa');

        $this->assertNotEmpty($paymentMethod->id, "Payment method creation failed");

        // Attach payment method to customer
        $stripeClient->paymentMethods->attach(
            $paymentMethod->id,
            ['customer' => $customerId]
        );

        // Create an instance of the change payment method controller
        $changePaymentMethodController = $this->objectManager->create(
            \StripeIntegration\Payments\Controller\Subscriptions\ChangePaymentMethod::class
        );

        // Configure the request
        $this->request->setParam('subscription_id', $subscriptionId);
        $this->request->setParam('payment_method_id', $paymentMethod->id);
        $this->request->setParam('form_key', 'testFormKey123');

        // Test CSRF validation
        $result = $changePaymentMethodController->createCsrfValidationException($this->request);
        $this->assertNull($result);

        $result = $changePaymentMethodController->validateForCsrf($this->request);
        $this->assertFalse($result);

        // Execute the controller
        $response = $changePaymentMethodController->execute();

        // Verify the customer is redirected to the subscriptions page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // Validate that the subscription now has the new payment method
        $updatedSubscription = $this->stripeConfig->getStripeClient()->subscriptions->retrieve(
            $subscriptionId,
            []
        );

        $this->assertEquals($paymentMethod->id, $updatedSubscription->default_payment_method, "Subscription should have the new Visa payment method");

        // Verify the redirect URL
        $reflectionClass = new \ReflectionClass($response);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($response);
        $this->assertStringContainsString('stripe/customer/subscriptions', $redirectUrl);

        // Test error handling: Try to update with an invalid payment method ID
        $this->request->setParam('payment_method_id', 'invalid_payment_method_id');
        $response = $changePaymentMethodController->execute();

        // Should still redirect to subscriptions page but with error message
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $response);

        // The original valid payment method should still be attached
        $finalSubscription = $this->stripeConfig->getStripeClient()->subscriptions->retrieve(
            $subscriptionId,
            []
        );
        $this->assertEquals($paymentMethod->id, $finalSubscription->default_payment_method);
    }
}