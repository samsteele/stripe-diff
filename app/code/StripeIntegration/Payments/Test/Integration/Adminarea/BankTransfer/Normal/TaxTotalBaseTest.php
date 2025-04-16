<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\BankTransfer\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class TaxTotalBaseTest extends \PHPUnit\Framework\TestCase
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

        // Pay the invoice
        $paymentMethod = $this->tests->stripe()->paymentMethods->attach("pm_card_visa", [
            'customer' => $stripeInvoice->customer
        ]);
        $stripeInvoice = $this->tests->stripe()->invoices->pay($invoiceId, [
            'payment_method' => $paymentMethod->id
        ]);

        // Check the Stripe invoice
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);
        $this->assertEquals("paid", $stripeInvoice->status);
        $this->assertEquals($order->getGrandTotal() * 100, $stripeInvoice->amount_paid);
        $this->assertEquals(0, $stripeInvoice->amount_remaining);

        // Check the order
        $this->tests->event()->trigger("charge.succeeded", $stripeInvoice->charge);
        $this->tests->event()->trigger("invoice.paid", $stripeInvoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $stripeInvoice);
        $order = $this->tests->refreshOrder($order);

        $this->tests->compare($order->debug(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal()
        ]);

        // Refund the order invoice online
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->tests->refundOnline($invoice, ['virtual-product' => 2, 'simple-product' => 2], $baseShipping = 10);

        $charge = $this->tests->stripe()->charges->retrieve($stripeInvoice->charge, []);
        $this->assertEquals(4250, $charge->amount_refunded);
        $this->tests->event()->trigger("charge.refunded", $charge);

        // Check the order
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "closed",
            "status" => "closed",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => 42.50
        ]);

        // Create the payment info block for $order
        $this->assertNotEmpty($this->tests->renderPaymentInfoBlock(\StripeIntegration\Payments\Block\PaymentInfo\BankTransfers::class, $order));
    }
}
