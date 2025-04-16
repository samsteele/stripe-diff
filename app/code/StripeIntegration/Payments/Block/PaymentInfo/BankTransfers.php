<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

class BankTransfers extends \Magento\Payment\Block\ConfigurableInfo
{
    private $paymentsConfig;
    private $paymentMethodHelper;
    private $stripePaymentMethodObject;
    private $stripePaymentMethodModelFactory;
    private $stripePaymentIntentObject;
    private $stripePaymentIntentModelFactory;
    private $country;
    private $tokenHelper;
    private $currencyHelper;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentModelFactory,
        \Magento\Directory\Model\Country $country,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->paymentsConfig = $paymentsConfig;
        $this->country = $country;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->stripePaymentMethodModelFactory = $stripePaymentMethodModelFactory;
        $this->stripePaymentIntentModelFactory = $stripePaymentIntentModelFactory;
        $this->tokenHelper = $tokenHelper;
        $this->currencyHelper = $currencyHelper;
    }

    public function getPaymentMethod()
    {
        if (!empty($this->stripePaymentMethodObject))
            return $this->stripePaymentMethodObject;

        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->payment_method))
        {
            return null;
        }
        else if (is_string($paymentIntent->payment_method))
        {
            $stripePaymentMethodModel = $this->stripePaymentMethodModelFactory->create()
                ->fromPaymentMethodId($paymentIntent->payment_method);

            return $this->stripePaymentMethodObject = $stripePaymentMethodModel->getStripeObject();
        }
        else
        {
            return $this->stripePaymentMethodObject = $paymentIntent->payment_method;
        }

        return null;
    }


    public function getPaymentIntent()
    {
        if (!empty($this->stripePaymentIntentObject))
            return $this->stripePaymentIntentObject;

        $transactionId = $this->getTransactionId();
        if ($transactionId && strpos($transactionId, "pi_") === 0)
        {
            $stripePaymentIntentModel = $this->stripePaymentIntentModelFactory->create()
                ->setExpandParams(['payment_method', 'invoice'])
                ->fromPaymentIntentId($transactionId);

            return $this->stripePaymentIntentObject = $stripePaymentIntentModel->getStripeObject();
        }

        return null;
    }

    public function getPaymentMethodIconUrl($format = null)
    {
        return $this->paymentMethodHelper->getIcon([
            "type" => "customer_balance"
        ], $format);
    }


    public function getPaymentMethodName($hideLast4 = false)
    {
        return $this->paymentMethodHelper->getPaymentMethodName("customer_balance");
    }

    public function getFormattedAmountRemaining()
    {
        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->getPaymentIntent();

        if (!$paymentIntent)
            return null;

        $amountRemaining = 0;
        $currency = $paymentIntent->currency;

        if (!empty($paymentIntent->next_action->display_bank_transfer_instructions->amount_remaining))
        {
            // For orders placed from the frontend
            /** @var \Stripe\StripeObject $instructions */
            $instructions = $paymentIntent->next_action->display_bank_transfer_instructions;
            $amountRemaining = $instructions->amount_remaining;
            $currency = $instructions->currency;
        }
        else if (!empty($paymentIntent->invoice->amount_remaining))
        {
            // For orders placed from the admin area
            $amountRemaining = $paymentIntent->invoice->amount_remaining;
            $currency = $paymentIntent->invoice->currency;
        }

        return $this->currencyHelper->formatStripePrice($amountRemaining, $currency);
    }

    public function getFormattedAmountRefunded()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!$paymentIntent)
            return null;

        $amountRefunded = 0;
        $currency = $paymentIntent->currency;
        $charges = $this->paymentsConfig->getStripeClient()->charges->all(['payment_intent' => $paymentIntent->id]);
        if (empty($charges->data))
        {
            return null;
        }

        foreach ($charges->data as $charge)
        {
            if ($charge->refunded)
            {
                $amountRefunded += $charge->amount_refunded;
                $currency = $charge->currency;
            }
        }

        return $this->currencyHelper->formatStripePrice($amountRefunded, $currency);
    }

    public function getTransactionId()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        return $this->tokenHelper->cleanToken($transactionId);
    }

    public function getIbanDetails()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban))
            return null;

        $details = $paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban;

        $countryName = null;
        if ($details->country)
        {
            $country = $this->country->loadByCode($details->country);
            $countryName = $country->getName();
        }

        return [
            'account_holder_name' => $details->account_holder_name ?? null,
            'bic' => $details->bic ?? null,
            'country' => $countryName,
            'iban' => $details->iban ?? null,
        ];
    }

    public function getReference()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->reference))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->reference;
    }

    public function getHostedInstructionsUrl()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url;
    }

    public function getCustomerId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->customer) && !empty($paymentIntent->customer))
            return $paymentIntent->customer;

        return null;
    }

    public function getPaymentId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->id))
            return $paymentIntent->id;

        return null;
    }

    public function getMode()
    {
        $paymentIntent = $this->getPaymentIntent();

        if ($paymentIntent->livemode)
            return "";

        return "test/";
    }

    public function getTemplate()
    {
        if (!$this->paymentsConfig->getStripeClient())
            return null;

        return 'paymentInfo/bank_transfers.phtml';
    }

    public function getInvoiceURL()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->invoice->hosted_invoice_url))
            return null;

        return $paymentIntent->invoice->hosted_invoice_url;
    }

    public function getInvoicePDF()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->invoice->invoice_pdf))
            return null;

        return $paymentIntent->invoice->invoice_pdf;
    }

    public function getStripeInvoiceURL()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->invoice->id))
            return null;

        return "https://dashboard.stripe.com/{$this->getMode()}invoices/" . $paymentIntent->invoice->id;
    }

    public function getDateDue()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->invoice->due_date))
            return null;

        $date = $paymentIntent->invoice->due_date;

        return date('j M Y', $date);
    }

    public function getStatus()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->invoice->status))
            return null;

        return ucfirst($paymentIntent->invoice->status);
    }
}
