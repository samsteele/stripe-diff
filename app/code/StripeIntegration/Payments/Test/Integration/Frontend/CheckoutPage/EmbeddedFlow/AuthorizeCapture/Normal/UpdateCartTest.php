<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class UpdateCartTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $service;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->service = $this->objectManager->create(\StripeIntegration\Payments\Api\ServiceInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testUpdateCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("RedirectBasedMethod");

        $order = $this->quote->placeOrder();

        // Restore the quote
        $this->service->restore_quote();

        // Test that the quote has been restored
        $quote = $this->quote->reloadQuote()->getQuote();
        $this->assertEquals(2, $quote->getItemsCount());
        $this->assertEquals(1, $quote->getIsActive());

        // Case 1: No changes to the cart - should not require a new order
        $result = $this->service->update_cart();
        $resultArray = json_decode($result, true);
        $this->assertFalse($resultArray['placeNewOrder'], "No changes were made to cart, shouldn't need new order");

        // Case 2: Change the shipping method and verify update_cart detects the change
        $this->quote->setShippingMethod("Best");
        $result = $this->service->update_cart();
        $resultArray = json_decode($result, true);
        $this->assertTrue($resultArray['placeNewOrder'], "Shipping method changed, should need new order");
        $this->assertStringContainsString("The order details have changed (base_grand_total).", $resultArray['reason'], "Reason should mention shipping method change");

        // Case 3: Modify the cart contents by adding another product
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->addProduct('simple-product', 1)
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin");

        $result = $this->service->update_cart();
        $resultArray = json_decode($result, true);
        $this->assertTrue($resultArray['placeNewOrder'], "Cart items changed, should need new order");

        // Case 4: Change the billing address
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("NewYork");

        $result = $this->service->update_cart();
        $resultArray = json_decode($result, true);
        $this->assertTrue($resultArray['placeNewOrder'], "Billing address changed, should need new order");
        $this->assertStringContainsString("The order details have changed (customer_email).", $resultArray['reason'], "Reason should mention billing address change");
    }
}
