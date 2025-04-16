<?php

namespace StripeIntegration\Payments\Gateway\Command\BankTransfers;

use Magento\Payment\Gateway\CommandInterface;
use StripeIntegration\Payments\Exception\AmountMismatchException;

class OrderCommand implements CommandInterface
{
    private $config;
    private $addressHelper;
    private $bankTransfersHelper;
    private $customer;
    private $orderHelper;
    private $convert;
    private $areaCodeHelper;
    private $stripeInvoiceModelFactory;
    private $stripeInvoiceItemModelFactory;
    private $helper;
    private $warningsLogger;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\InvoiceFactory $stripeInvoiceModelFactory,
        \StripeIntegration\Payments\Model\Stripe\InvoiceItemFactory $stripeInvoiceItemModelFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\BankTransfers $bankTransfersHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Logger\Warnings\Logger $warningsLogger
    ) {
        $this->config = $config;
        $this->stripeInvoiceModelFactory = $stripeInvoiceModelFactory;
        $this->stripeInvoiceItemModelFactory = $stripeInvoiceItemModelFactory;
        $this->bankTransfersHelper = $bankTransfersHelper;
        $this->addressHelper = $addressHelper;
        $this->orderHelper = $orderHelper;
        $this->convert = $convert;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->helper = $helper;
        $this->customer = $helper->getCustomerModel();
        $this->warningsLogger = $warningsLogger;
    }

    public function execute(array $commandSubject): void
    {
        $payment = $commandSubject['payment']->getPayment();
        $amount = $commandSubject['amount'];
        $order = $payment->getOrder();

        $this->customer->createStripeCustomerIfNotExists(false, $order);
        $this->customer->updateFromOrder($order);
        $customerId = $this->customer->getStripeId();

        if ($this->areaCodeHelper->isAdmin())
        {
            $stripeInvoiceModel = $this->createStripeInvoiceModel($payment);
            $stripeInvoiceModel->send();
            $paymentIntentId = $stripeInvoiceModel->getStripeObject()->payment_intent;
            $payment->setAdditionalInformation("invoice_id", $stripeInvoiceModel->getStripeObject()->id);
        }
        else
        {
            $paymentIntent = $this->createPaymentIntent($payment, $amount);
            $paymentIntentId = $paymentIntent->id;
        }

        $payment->setTransactionId($paymentIntentId);
        $payment->setLastTransId($paymentIntentId);
        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true);
        $payment->setAdditionalInformation("customer_stripe_id", $customerId);
        $order->setCanSendNewEmailFlag(true);
    }

    private function createPaymentIntent($payment, $amount)
    {
        $stripe = $this->config->getStripeClient();
        $order = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        $amount = $order->getGrandTotal();
        $paymentMethodId = $payment->getAdditionalInformation("token");

        $params = [
            "amount" => $this->convert->magentoAmountToStripeAmount($amount, $currency),
            "currency" => strtolower($currency),
            "customer" => $this->customer->getStripeId(),
            "description" => $this->orderHelper->getOrderDescription($order),
            "metadata" => $this->config->getMetadata($order),
            "confirm" => true,
            "payment_method_types" => ["customer_balance"],
            "payment_method_options" => $this->bankTransfersHelper->getPaymentMethodOptions(),
            "payment_method" => $paymentMethodId
        ];

        if (!$order->getIsVirtual())
        {
            $address = $order->getShippingAddress();

            if (!empty($address))
            {
                $params['shipping'] = $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
            }
        }

        if ($this->config->isReceiptEmailsEnabled())
        {
            $customerEmail = $order->getCustomerEmail();

            if ($customerEmail)
            {
                $params["receipt_email"] = $customerEmail;
            }
        }

        $paymentIntent = $stripe->paymentIntents->create($params);

        return $paymentIntent;
    }

    private function createStripeInvoiceModel($payment)
    {
        $order = $payment->getOrder();
        $customerId = $this->customer->getStripeId();

        $magentoInvoice = $this->orderHelper->createInvoice($order);
        $stripeInvoiceNumber = $this->bankTransfersHelper->getStripeInvoiceNumber($magentoInvoice);
        $invoiceParams = [
            "payment_settings" => [
                "payment_method_options" => $this->bankTransfersHelper->getPaymentMethodOptions(),
                "payment_method_types" => ["customer_balance"]
            ]
        ];

        if ($stripeInvoiceNumber)
        {
            $invoiceParams["number"] = $stripeInvoiceNumber;
        }

        $stripeInvoiceModel = $this->stripeInvoiceModelFactory->create()->fromOrder($order, $customerId, $invoiceParams);
        try
        {
            $stripeInvoiceModel->buildFromOrderBreakdown($order);
        }
        catch (\Exception $e)
        {
            $this->warningsLogger->warning("Bank Transfer invoice breakdown failed: " . $e->getMessage());
            if ($e instanceof AmountMismatchException)
            {
                $stripeInvoiceModel->archive();
            }
            else
            {
                $stripeInvoiceModel->destroy();
            }
            $stripeInvoiceModel = $this->stripeInvoiceModelFactory->create()->fromOrder($order, $customerId, $invoiceParams);
            $this->stripeInvoiceItemModelFactory->create()->fromOrderGrandTotal($order, $customerId, $stripeInvoiceModel->getId());
            if ($this->areaCodeHelper->isAdmin())
            {
                $this->helper->addWarning("The created invoice was not broken down into invoice items: " . $e->getMessage());
            }
        }

        return $stripeInvoiceModel->finalize();
    }
}
