<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class CheckoutCrash
{
    private $logger;
    private $emailHelper;
    private $currencyHelper;
    private $quoteHelper;
    private $checkoutCrashLogger;

    public function __construct(
        \StripeIntegration\Payments\Helper\Logger $logger,
        \StripeIntegration\Payments\Helper\Email $emailHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Logger\CheckoutCrash\Logger $checkoutCrashLogger
    ) {
        $this->logger = $logger;
        $this->emailHelper = $emailHelper;
        $this->currencyHelper = $currencyHelper;
        $this->quoteHelper = $quoteHelper;
        $this->checkoutCrashLogger = $checkoutCrashLogger;
    }

    public function log(\StripeIntegration\Payments\Model\Order\PaymentState $paymentState, $e)
    {
        if ($paymentState->isPaid())
        {
            $paymentUrl = $paymentState->getPaymentUrl();
            $message = "A payment has been successfully collected, but a checkout crash prevented the order from being saved. You can review the payment at $paymentUrl. The checkout error was: ";
            $message = $message . "\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
            $this->checkoutCrashLogger->critical($message);
        }

        return $this;
    }

    public function notifyAdmin(\StripeIntegration\Payments\Model\Order\PaymentState $paymentState, $e)
    {
        if ($paymentState->isPaid())
        {
            try
            {
                $paymentIntent = $paymentState->getPaymentIntent();
                $paymentUrl = $paymentState->getPaymentUrl();
                $errorMessage = $e->getMessage();
                $amount = $paymentIntent->amount;
                $currency = $paymentIntent->currency;
                $formattedAmount = $this->currencyHelper->formatStripePrice($amount, $currency);

                $generalName = $this->emailHelper->getName('general');
                $generalEmail = $this->emailHelper->getEmail('general');

                $templateVars = [
                    'paymentIntentId' => $paymentIntent->id,
                    'paymentLink' => $paymentUrl,
                    'formattedAmount' => $formattedAmount,
                    'errorMessage' => $errorMessage,
                    'errorStackTrace' => $e->getTraceAsString()
                ];

                $sent = $this->emailHelper->send('stripe_checkout_crash', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);
            }
            catch (\Exception $e)
            {
                $this->logger->logError($e->getMessage(), $e->getTraceAsString());
            }
        }

        return $this;
    }

    public function deactivateCart()
    {
        $quote = $this->quoteHelper->getQuote();
        $this->quoteHelper->deactivateQuote($quote);

        return $this;
    }
}