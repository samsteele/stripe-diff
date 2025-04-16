<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\Order\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DeletePaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    private $quote;
    private $tests;
    private $service;
    private $objectManager;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action order
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testNormalCart()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->tests->log($order);

        $stripeCustomer = $this->tests->helper()->getCustomerModel();
        $this->assertNotEmpty($stripeCustomer->getStripeId());
        $this->assertEquals(1, $stripeCustomer->getCustomerId());

        $paymentMethodId = $order->getPayment()->getAdditionalInformation('token');
        $this->assertStringStartsWith("pm_", $paymentMethodId);

        try
        {
            $this->service->delete_payment_method($paymentMethodId);
            $this->fail("The payment method should not be deletable");
        }
        catch (\Exception $e)
        {
            $expectedMessage = "Sorry, it is not possible to delete this payment method because order #" . $order->getIncrementId() . " which was placed using it is still being processed.";
            $this->assertEquals($expectedMessage, $e->getMessage());
        }

        // The API should return the same payment method
        $methodsJson = $this->service->list_payment_methods($stripeCustomer->getStripeId());
        $this->assertNotEmpty($methodsJson);
        $methods = json_decode($methodsJson, true);
        $this->assertCount(1, $methods);
        $this->assertNotEmpty($methods);
        $method = array_pop($methods);
        $this->assertEquals($paymentMethodId, $method['id']);
        $this->assertEquals("card", $method['type']);

        // Add a new payment method
        $this->service->add_payment_method("pm_card_mastercard");
        $methodsJson = $this->service->list_payment_methods($stripeCustomer->getStripeId());
        $this->assertNotEmpty($methodsJson);
        $methods = json_decode($methodsJson, true);
        $this->tests->log($methods);
        $this->assertCount(2, $methods);
    }
}
