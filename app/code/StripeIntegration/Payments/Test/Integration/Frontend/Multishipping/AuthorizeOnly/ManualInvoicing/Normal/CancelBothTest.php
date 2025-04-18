<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Multishipping\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelBothTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $service;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\MultishippingQuote();
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testNormalCart()
    {
        $this->quote->create()
            ->setCart("Normal")
            ->setPaymentMethod("SuccessCard");

        $ordersCount = $this->tests->getOrdersCount();

        $result = json_decode($this->service->place_multishipping_order());

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 2, $newOrdersCount);

        $order1 = $this->tests->getOrderBySortPosition(1);
        $order2 = $this->tests->getOrderBySortPosition(2);
        $this->assertNotEmpty($order1);
        $this->assertNotEmpty($order2);
        $paymentIntent = $this->tests->confirmMultishipping($order1); // This will trigger all webhook events for the payment intent
        $this->assertTrue($order1->canCancel(), "Cannot cancel authorize only order 1");
        $this->assertTrue($order2->canCancel(), "Cannot cancel authorize only order 2");
        $order1->cancel();
        $this->tests->orderHelper->saveOrder($order1);
        $order2->cancel();
        $this->tests->orderHelper->saveOrder($order2);

        // Payment intent checks

        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id, [
            "expand" => ["latest_charge"]
        ]);
        $ordersTotal = ($order1->getGrandTotal() * 100 + $order2->getGrandTotal() * 100);

        $this->tests->compare($paymentIntent, [
            "amount" => $ordersTotal,
            "amount_capturable" => 0,
            "capture_method" => "manual",
            "latest_charge" => [
                "amount" => $ordersTotal,
                "amount_captured" => 0,
                "amount_refunded" => $ordersTotal
            ],
            "status" => "canceled"
        ]);
    }
}
