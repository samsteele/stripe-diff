<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\TrialSimple;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CheckoutTotalsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testTrialCartCheckoutTotals()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $uiConfigProvider = $this->objectManager->get(\StripeIntegration\Payments\Model\Ui\ConfigProvider::class);
        $uiConfig = $uiConfigProvider->getConfig();
        $this->assertNotEmpty($uiConfig["payment"]["stripe_payments"]["futureSubscriptions"]);
        $futureSubscriptionsConfig = $uiConfig["payment"]["stripe_payments"]["futureSubscriptions"];

        $this->assertStringContainsString("15.83", $futureSubscriptionsConfig["formatted_amount"], "Amount");
    }
}
