<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Service;

class OrderService
{
    private $helper;
    private $config;
    private $helperFactory;
    private $quoteHelper;
    private $webhookEventCollectionFactory;
    private $paymentMethodHelper;
    private $loggerHelper;
    private $orderHelper;
    private $updateCouponUsages;
    private $paymentState;
    private $checkoutCrashHelper;

    public function __construct(
        \Magento\SalesRule\Model\Coupon\Quote\UpdateCouponUsages $updateCouponUsages,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Logger $loggerHelper,
        \StripeIntegration\Payments\Helper\CheckoutCrash $checkoutCrashHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Order\PaymentState $paymentState,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory

    ) {
        $this->updateCouponUsages = $updateCouponUsages;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->helperFactory = $helperFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->loggerHelper = $loggerHelper;
        $this->checkoutCrashHelper = $checkoutCrashHelper;
        $this->config = $config;
        $this->paymentState = $paymentState;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
    }

    public function aroundPlace($subject, \Closure $proceed, $order)
    {
        try
        {
            if (!empty($order) && !empty($order->getQuoteId()))
            {
                $this->quoteHelper->quoteId = $order->getQuoteId();
                $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());
            }
            else
            {
                $quote = $this->quoteHelper->getQuote();
            }

            $savedOrder = $proceed($order);

            $this->incrementCouponUsages($quote);
            return $this->postProcess($savedOrder);
        }
        catch (\Exception $e)
        {
            $helper = $this->getHelper();
            $msg = $e->getMessage();

            if ($this->loggerHelper->isAuthenticationRequiredMessage($msg))
            {
                throw $e;
            }
            else
            {
                if ($this->paymentState->isPaid() && empty($savedOrder))
                {
                    $this->checkoutCrashHelper->log($this->paymentState, $e)
                        ->notifyAdmin($this->paymentState, $e)
                        ->deactivateCart();

                    $helper->logError($e->getMessage(), $e->getTraceAsString());

                    throw $e;
                }

                // Payment failed errors
                return $helper->throwError($e->getMessage(), $e);
            }
        }
    }

    public function incrementCouponUsages($quote)
    {
        if (!$quote->getCouponCode())
        {
            return;
        }

        if ($this->config->incrementCouponUsageAfterOrderPlacement($quote) && !$quote->getCouponUsageIncremented())
        {
            $this->updateCouponUsages->execute($quote, true);
            $quote->setCouponUsageIncremented(true);
        }
    }

    public function postProcess($order)
    {
        $helper = $this->getHelper();
        switch ($order->getPayment()->getMethod())
        {
            case "stripe_payments_bank_transfers":
                $this->paymentMethodHelper->savePaymentMethod($order, "customer_balance", null);
                $this->orderHelper->saveOrder($order);
                break;
            case "stripe_payments_invoice":
                $comment = __("A payment is pending for this order.");
                $helper->setOrderState($order, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment);
                $this->orderHelper->saveOrder($order);
                break;
            case "stripe_payments":
            case "stripe_payments_express":

                if ($transactionId = $order->getPayment()->getAdditionalInformation("server_side_transaction_id"))
                {
                    // Process webhook events which have arrived before the order was saved
                    $events = $this->webhookEventCollectionFactory->create()->getEarlyEventsForPaymentIntentId($transactionId, [
                        'charge.succeeded', // Regular orders
                        'invoice.payment_succeeded', // Subscriptions
                        'setup_intent.succeeded' // Trial subscriptions
                    ]);

                    foreach ($events as $eventModel)
                    {
                        try
                        {
                            $eventModel->process($this->config->getStripeClient());
                        }
                        catch (\Exception $e)
                        {
                            $eventModel->refresh()->setLastErrorFromException($e);
                        }
                    }
                }

                break;
            default:
                break;
        }

        return $order;
    }

    protected function getHelper()
    {
        if (!isset($this->helper))
        {
            $this->helper = $this->helperFactory->create();
        }

        return $this->helper;
    }
}
