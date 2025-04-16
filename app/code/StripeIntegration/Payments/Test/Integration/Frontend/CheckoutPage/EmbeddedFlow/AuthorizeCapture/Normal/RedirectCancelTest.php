<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RedirectCancelTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $checkoutSession;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testRedirectCancel()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("RedirectBasedMethod");

        $order = $this->quote->placeOrder();

        // Test that the quote is inactive
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(0, $quote->getIsActive());

        // Cancel the payment and return to the checkout page
        $cancelController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Payment\Cancel::class);
        $result = $cancelController->execute();

        // Ensure the redirect URL is the checkout page
        $reflectionClass = new \ReflectionClass($result);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($result);
        $this->assertStringContainsString('checkout', $redirectUrl);
        $this->assertStringNotContainsString('cart', $redirectUrl);

        // Test that the quote has been restored
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(2, $quote->getItemsCount());
        $this->assertEquals(1, $quote->getIsActive());
    }
}
