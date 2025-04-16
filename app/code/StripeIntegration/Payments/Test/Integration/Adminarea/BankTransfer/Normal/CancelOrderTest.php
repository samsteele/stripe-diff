<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\BankTransfer\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $paymentMethodBlock;

    public function setUp(): void
    {
        /** @var \Magento\TestFramework\ObjectManager $objectManager */
        $objectManager = $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $bankTransfersHelperMock = $this->getMockBuilder(\StripeIntegration\Payments\Helper\BankTransfers::class)
            ->setConstructorArgs([
                $objectManager->get(\StripeIntegration\Payments\Helper\Quote::class),
                $objectManager->get(\StripeIntegration\Payments\Helper\Config::class),
                $objectManager->get(\StripeIntegration\Payments\Helper\Store::class)
            ])
            ->onlyMethods(['getStripeInvoiceNumber'])
            ->getMock();

        $bankTransfersHelperMock->method('getStripeInvoiceNumber')
            ->willReturn(null);

        $objectManager->addSharedInstance(
            $bankTransfersHelperMock,
            \StripeIntegration\Payments\Helper\BankTransfers::class
        );

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentMethodBlock = $this->objectManager->get(\StripeIntegration\Payments\Block\Adminhtml\SelectPaymentMethod::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_bank_transfers/minimum_amount 0
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testNormalCart()
    {
        $this->quote->createAdmin()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("BankTransferAdmin");

        $this->paymentMethodBlock->getSavedPaymentMethods(); // Creates the customer object
        $order = $this->quote->placeOrder();

        // Check the order
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "pending_payment",
            "status" => "pending_payment",
            "grand_total" => 42.50,
            "total_due" => 0,
            "total_invoiced" => $order->getGrandTotal()
        ]);

        // Check the Magento invoice
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertNotEmpty($invoice);
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Check the Stripe payment intent
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($paymentIntentId);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->assertEquals($order->getGrandTotal() * 100, $paymentIntent->amount);

        // Check the Stripe invoice
        $invoiceId = $paymentIntent->invoice;
        $this->assertNotEmpty($invoiceId);
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);

        $this->tests->compare($stripeInvoice, [
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => 0,
            "customer_address" => [
                "city" => $order->getBillingAddress()->getCity(),
                "country" => $order->getBillingAddress()->getCountryId(),
                "line1" => $order->getBillingAddress()->getStreet()[0],
                "postal_code" => $order->getBillingAddress()->getPostcode(),
                "state" => $order->getBillingAddress()->getRegion()
            ],
            "customer_email" => $order->getCustomerEmail(),
            "customer_name" => $order->getBillingAddress()->getFirstname() . " " . $order->getBillingAddress()->getLastname(),
            "customer_phone" => $order->getBillingAddress()->getTelephone()
        ]);

        // Cancel the order invoice
        $invoice->cancel();
        $invoice->save();

        // Check that the Stripe invoice is voided
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);
        $this->assertEquals("void", $stripeInvoice->status);
        $this->assertEquals($order->getGrandTotal() * 100, $stripeInvoice->amount_due);
        $this->assertEquals(0, $stripeInvoice->amount_paid);
        $this->assertEquals($order->getGrandTotal() * 100, $stripeInvoice->amount_remaining);
    }
}
