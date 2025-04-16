<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RedirectSuccessTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $checkoutSession;
    private $request;
    private $tests;
    private $indexController;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->indexController = $this->objectManager->get(\StripeIntegration\Payments\Controller\Payment\Index::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysLegacy.php
     */
    public function testRedirectSuccess()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // If the customer returns without attempting a payment, they should be redirected to the checkout
        $this->request->setParams([
            'payment_method' => "stripe_checkout",
        ]);
        $result = $this->indexController->execute();
        $reflectionClass = new \ReflectionClass($result);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($result);
        $this->assertStringContainsString('checkout', $redirectUrl);
        $this->assertStringNotContainsString('cart', $redirectUrl);

        // Their cart should also be active
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(1, $quote->getIsActive());

        // Fetch the checkout session
        $session = $this->tests->checkout()->retrieveSession($order, "Normal");
        $this->assertEmpty($session->payment_intent); // As of API 2022-08-01, the payment intent is not created for redirect flow

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "Normal", "card", "California");

        // Return to the site after the payment
        $result = $this->indexController->execute();

        // Ensure the redirect URL is the success page
        $reflectionClass = new \ReflectionClass($result);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($result);
        $this->assertStringContainsString('success', $redirectUrl);

        // Test that the quote is inactive
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(0, $quote->getIsActive());
    }
}
