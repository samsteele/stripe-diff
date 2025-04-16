<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RedirectFailedTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $checkoutSession;
    private $request;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\RequestInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testRedirectFailed()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("RedirectBasedMethod");

        $order = $this->quote->placeOrder();

        $paymentIntentId = $order->getPayment()->getLastTransId();

        // Test that the quote is inactive
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(0, $quote->getIsActive());

        // Return to the cart after a payment failure
        $this->request->setParams([
            'payment_intent' => $paymentIntentId,
            'redirect_status' => 'failed'
        ]);
        $indexController = $this->objectManager->create(\StripeIntegration\Payments\Controller\Payment\Index::class);
        $result = $indexController->execute();

        // Ensure the redirect URL is the checkout page
        $reflectionClass = new \ReflectionClass($result);
        $urlProperty = $reflectionClass->getProperty('url');
        $urlProperty->setAccessible(true);
        $redirectUrl = $urlProperty->getValue($result);
        $this->assertStringContainsString('checkout/cart', $redirectUrl);

        // Test that the quote has been restored
        $quote = $this->checkoutSession->getQuote();
        $this->assertEquals(2, $quote->getItemsCount());
        $this->assertEquals(1, $quote->getIsActive());
    }
}
