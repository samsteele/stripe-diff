<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use StripeIntegration\Payments\Exception\AmountMismatchException;
use StripeIntegration\Payments\Exception\GenericException;

class Invoice extends \Magento\Payment\Model\Method\Adapter
{
    public const METHOD_CODE = 'stripe_payments_invoice';

    private $customer;
    private $stripeInvoiceItemModelFactory;
    private $stripeInvoiceModelFactory;
    private $orderInvoiceFactory;
    private $cache;
    private $config;
    private $helper;
    private $tokenHelper;
    private $convert;
    private $stripePaymentIntentFactory;
    private $orderHelper;
    private $areaCodeHelper;
    private $warningsLogger;
    private $stripeInvoiceHelper;

    public function __construct(
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Payment\Gateway\Config\ValueHandlerPoolInterface $valueHandlerPool,
        \Magento\Payment\Gateway\Data\PaymentDataObjectFactory $paymentDataObjectFactory,
        $code,
        $formBlockType,
        $infoBlockType,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Stripe\Invoice $stripeInvoiceHelper,
        \StripeIntegration\Payments\Logger\Warnings\Logger $warningsLogger,
        \StripeIntegration\Payments\Model\Stripe\InvoiceItemFactory $stripeInvoiceItemModelFactory,
        \StripeIntegration\Payments\Model\Stripe\InvoiceFactory $stripeInvoiceModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentFactory,
        \StripeIntegration\Payments\Model\InvoiceFactory $orderInvoiceFactory,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Payment\Gateway\Command\CommandPoolInterface $commandPool = null,
        \Magento\Payment\Gateway\Validator\ValidatorPoolInterface $validatorPool = null
    ) {
        $this->config = $config;
        $this->helper = $helper;
        $this->convert = $convert;
        $this->orderHelper = $orderHelper;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->stripeInvoiceHelper = $stripeInvoiceHelper;
        $this->warningsLogger = $warningsLogger;
        $this->customer = $helper->getCustomerModel();
        $this->stripeInvoiceItemModelFactory = $stripeInvoiceItemModelFactory;
        $this->stripeInvoiceModelFactory = $stripeInvoiceModelFactory;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
        $this->orderInvoiceFactory = $orderInvoiceFactory;
        $this->cache = $cache;
        $this->tokenHelper = $tokenHelper;

        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType = 'StripeIntegration\Payments\Block\Method\Invoice',
            $infoBlockType = 'StripeIntegration\Payments\Block\PaymentInfo\Invoice',
            $commandPool,
            $validatorPool
        );
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$this->config->isEnabled())
            return false;

        return parent::isAvailable($quote);
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        $daysDue = $data->getAdditionalData('days_due');
        $daysDue = max(0, $daysDue);
        $daysDue = min(999, $daysDue);
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('days_due', $daysDue);

        if ($this->config->getIsStripeAPIKeyError())
            $this->helper->throwError("Invalid API key provided");

        $info->setAdditionalInformation("payment_location", "Invoice from admin area");

        return $this;
    }

    public function order(InfoInterface $payment, $amount)
    {
        if ($amount > 0)
        {
            if ($payment->getAdditionalInformation('invoice_id'))
                throw new LocalizedException(__("This order cannot be captured from Magento. The invoice will be automatically updated once the customer has paid through a Stripe hosted invoice page."));

            $info = $this->getInfoInstance();
            $order = $info->getOrder();
            $this->customer->updateFromOrder($order);
            $customerId = $this->customer->getStripeId();
            $invoice = $this->createInvoice($order, $customerId)->finalize();
            $payment->setAdditionalInformation('invoice_id', $invoice->getId());
            $payment->setLastTransId($invoice->getStripeObject()->payment_intent);
            $payment->setTransactionId($invoice->getStripeObject()->payment_intent);
            $payment->setIsTransactionPending(true);
            $order->setCanSendNewEmailFlag(true);
            $this->config->getStripeClient()->invoices->sendInvoice($invoice->getId(), []);
        }

        return $this;
    }

    public function createInvoice($order, $customerId)
    {
        $items = $order->getAllItems();

        if (empty($items))
        {
            return $this->helper->throwError(__("Could not create Stripe invoice because the order contains no items."));
        }

        $magentoInvoice = $this->orderHelper->createInvoice($order);
        $invoiceParams = $this->stripeInvoiceHelper->getStripeInvoiceParams($magentoInvoice);
        $stripeInvoiceModel = $this->stripeInvoiceModelFactory->create()->fromOrder($order, $customerId, $invoiceParams);
        if (!$stripeInvoiceModel->getId())
        {
            return $this->helper->throwError(__("Could not create Stripe invoice for order #%1", $order->getIncrementId()));
        }

        try
        {
            $stripeInvoiceModel->buildFromOrderBreakdown($order);
        }
        catch (\Exception $e)
        {
            $this->warningsLogger->warning("Stripe Billing invoice breakdown failed: " . $e->getMessage());
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

        $this->orderInvoiceFactory->create()
            ->setInvoiceId($stripeInvoiceModel->getId())
            ->setOrderIncrementId($order->getIncrementId())
            ->save();

        return $stripeInvoiceModel;
    }

    public function refund(InfoInterface $payment, $amount)
    {
        $creditmemo = $payment->getCreditmemo();
        if (!empty($creditmemo))
        {
            $rate = $creditmemo->getBaseToOrderRate();
            if (!empty($rate) && is_numeric($rate) && $rate > 0)
            {
                $amount = round(floatval($amount * $rate), 2);
                $diff = $amount - $payment->getAmountPaid();
                if ($diff > 0 && $diff <= 1) // Solves a currency conversion rounding issue (Magento rounds .5 down)
                    $amount = $payment->getAmountPaid();
            }
        }

        $currency = $payment->getOrder()->getOrderCurrencyCode();

        $paymentIntentId = $this->tokenHelper->cleanToken($payment->getLastTransId());

        if (empty($paymentIntentId))
        {
            return $this->helper->throwError('Could not refund payment: PaymentIntent ID is missing');
        }

        try
        {
            $params = [];

            if ($amount > 0)
                $params["amount"] = $this->convert->magentoAmountToStripeAmount($amount, $currency);

            $stripePaymentIntentModel = $this->stripePaymentIntentFactory->create()->fromPaymentIntentId($paymentIntentId);

            $params["charge"] = $stripePaymentIntentModel->getStripeObject()->latest_charge;

            // This is true when an authorization has expired or when there was a refund through the Stripe account
            $this->cache->save($value = "1", $key = "admin_refunded_" . $params["charge"], ["stripe_payments"], $lifetime = 60 * 60);
            $refund = $this->config->getStripeClient()->refunds->create($params);
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->throwError('Could not refund payment: '.$e->getMessage());
            throw new GenericException(__($e->getMessage()));
        }

        return $this;
    }

    public function getTitle()
    {
        return __("Send invoice by email (Stripe Billing)");
    }

    // Disables the Capture button on the invoice page
    public function canCapture()
    {
        $info = $this->getInfoInstance();
        if ($info->getAdditionalInformation('invoice_id'))
            return false;

        return true;
    }
}
