<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class TaxInclusivePricesTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $subscriptions;
    private $subscriptionProductFactory;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->subscriptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->subscriptionProductFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\SubscriptionProductFactory::class);
    }

    /**
     * @ticket MAGENTO-73
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     *
     * @magentoConfigFixture current_store customer/create_account/default_group 1
     * @magentoConfigFixture current_store customer/create_account/auto_group_assign 1
     * @magentoConfigFixture current_store tax/classes/shipping_tax_class 2
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/shipping_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/discount_tax 1
     */
    public function testSubscriptionDetails()
    {
        $calculation = $this->objectManager->get(\Magento\Tax\Model\Calculation::class);
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $orderItem = null;
        foreach ($order->getAllItems() as $item)
        {
            if ($item->getProductType() == "simple")
                $orderItem = $item;
        }
        $this->assertNotEmpty($orderItem);

        $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromProductId($orderItem->getProductId());
        $subscriptionProfile = $this->subscriptions->getSubscriptionDetails($subscriptionProductModel, $order, $orderItem);

        $expectedProfile = [
            "name" => "Configurable Subscription",
            "qty" => 1,
            "interval" => "month",
            "interval_count" => 1,
            "amount_magento" => 10,
            "amount_stripe" => 1000,
            "initial_fee_stripe" => 0,
            "initial_fee_magento" => 0,
            "discount_amount_magento" => 0,
            "discount_amount_stripe" => 0,
            "shipping_magento" => 5,
            "shipping_stripe" => 500,
            "currency" => "usd",
            "tax_percent" => 8.25,
            "tax_amount_item" => 0.76,
            "tax_amount_shipping" => 0.38,
            "tax_amount_initial_fee" => 0,
            "trial_end" => null,
            "trial_days" => 0,
            "expiring_coupon" => null
        ];

        foreach ($expectedProfile as $key => $value)
        {
            $this->assertEquals($value, $subscriptionProfile[$key], $key);
        }
    }
}
