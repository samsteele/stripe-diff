<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Multishipping\Model\Checkout\Type\Multishipping\State;
use StripeIntegration\Payments\Exception\SCANeededException;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\SkipCaptureException;
use StripeIntegration\Payments\Exception\GenericException;

class Multishipping
{
    private $checkout = null;
    private $multishippingCheckoutFactory;
    private $paymentIntentHelper;
    private $paymentIntent;
    private $multishippingQuote;
    private $multishippingOrderFactory;
    private $multishippingOrderCollection;
    private $state;
    private $checkoutSession;
    private $eventManager;
    private $session;
    private $config;
    private $helper;
    private $paymentIntentCollection;
    private $quoteHelper;
    private $orderHelper;
    private $currencyHelper;
    private $convert;

    public function __construct(
        \Magento\Multishipping\Model\Checkout\Type\Multishipping\State $state,
        \Magento\Framework\Session\SessionManagerInterface $session,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Model\Checkout\Type\MultishippingFactory $multishippingCheckoutFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\Multishipping\Quote $multishippingQuote,
        \StripeIntegration\Payments\Model\Multishipping\OrderFactory $multishippingOrderFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Multishipping\Order\Collection $multishippingOrderCollection,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection
    )
    {
        $this->state = $state;
        $this->session = $session;
        $this->checkoutSession = $checkoutSession;
        $this->eventManager = $eventManager;
        $this->multishippingCheckoutFactory = $multishippingCheckoutFactory;
        $this->helper = $helper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->multishippingQuote = $multishippingQuote;
        $this->multishippingOrderFactory = $multishippingOrderFactory;
        $this->multishippingOrderCollection = $multishippingOrderCollection;
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->currencyHelper = $currencyHelper;
        $this->convert = $convert;
    }

    protected function getCheckout()
    {
        if ($this->checkout)
            return $this->checkout;

        return $this->checkout = $this->multishippingCheckoutFactory->create();
    }

    protected function resetCheckout($quoteId)
    {
        // Clear the payment method. In cases where the payment failed on a new PM,
        // we cannot reuse the token for order placements. Ask the user to specify a new one.
        $multishippingQuoteModel = $this->multishippingQuote;
        $multishippingQuoteModel->load($quoteId, 'quote_id');
        $multishippingQuoteModel->delete();

        // If the payment was unsuccessful, cancel the PaymentIntent so that the associated orders are also canceled
        $paymentIntentModel = $this->paymentIntentCollection->findByQuoteId($quoteId);
        if ($paymentIntentModel->getPiId())
        {
            try
            {
                $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentModel->getPiId());
                if ($this->paymentIntentHelper->canCancel($paymentIntent))
                {
                    $paymentIntent->cancel();
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not cancel payment intent: " . $e->getMessage());
            }
        }
    }

    public function getFinalRedirectUrl($quoteId)
    {
        $checkout = $this->getCheckout();

        if ($this->session->getAddressErrors())
        {
            $this->state->setCompleteStep(State::STEP_OVERVIEW);
            $this->state->setActiveStep(State::STEP_RESULTS);
            $this->resetCheckout($quoteId);
            return $this->helper->getUrl('multishipping/checkout/results');
        }
        else if ($this->session->getOrderIds())
        {
            // It is possible that the orders were placed and a crash happened after,
            // resulting in an empty quote and a re-submission at the Overview page.
            // Strategy: Redirect to the success page
            $this->state->setCompleteStep(State::STEP_OVERVIEW);
            $this->state->setActiveStep(State::STEP_SUCCESS);
            $this->checkoutSession->clearQuote();
            $this->checkoutSession->setDisplaySuccess(true);
            $this->checkoutSession->setLastQuoteId($checkout->getQuote()->getId());
            $this->quoteHelper->deactivateQuote($checkout->getQuote());
            return $this->helper->getUrl('multishipping/checkout/success');
        }
        else
        {
            $this->helper->addError(__("Could not place order: Your checkout session has expired."));
            return $this->helper->getUrl('checkout/cart');
        }
    }

    public function placeOrder($quoteId)
    {
        $checkout = $this->getCheckout();

        if (empty($quoteId))
            return $this->getFinalRedirectUrl($quoteId);

        $multishippingQuoteModel = $this->multishippingQuote->load($quoteId, 'quote_id');

        if (!$multishippingQuoteModel->getPaymentMethodId())
        {
            $this->helper->addError(__("Please specify a payment method."));
            return $this->helper->getUrl('multishipping/checkout/billing');
        }

        if (!$checkout->validateMinimumAmount())
        {
            $error = $checkout->getMinimumAmountError();
            return $this->helper->getUrl('multishipping/checkout/overview');
        }

        $quote = $this->quoteHelper->loadQuoteById($quoteId);

        $results = $checkout->createOrders();
        $orders = $results['orders'];
        $errors = $results['exceptionList'];
        $successful = $failed = [];

        foreach ($orders as $order)
        {
            $model = $this->multishippingOrderFactory->create();
            $model->load($order->getId(), 'order_id');
            $model->setQuoteId($quoteId);
            $model->setOrderId($order->getId());

            if (isset($errors[$order->getIncrementId()]))
            {
                $error = $errors[$order->getIncrementId()]->getMessage();
                $model->setLastError($error);
                $failed[] = $order;
            }
            else
            {
                $model->setLastError(null);
                $successful[] = $order;
            }

            $model->save();
        }

        $checkout->setResultsPageData($quote, $successful, $failed, $errors);

        $addressErrors = $checkout->getAddressErrors($quote, $successful, $failed, $errors);
        if (count($addressErrors) > 0 && count($successful) == 0)
            return $this->getFinalRedirectUrl($quoteId);


        if ($this->config->getPaymentAction() === 'order')
        {
            return $this->getFinalRedirectUrl($quoteId);
        }

        try
        {
            $paymentIntentModel = $this->paymentIntentCollection->findByQuoteId($quoteId);
            $params = $paymentIntentModel->getMultishippingParamsFrom($quote, $successful, $multishippingQuoteModel->getPaymentMethodId());
            $paymentIntent = $paymentIntentModel->createPaymentIntentFrom($params, $quote);

            $isManualCapture = ($paymentIntent->capture_method == "manual");
            $multishippingQuoteModel->setPaymentIntentId($paymentIntent->id);
            $multishippingQuoteModel->setManualCapture($isManualCapture);
            $multishippingQuoteModel->save();

            foreach ($successful as $order)
            {
                $paymentIntentModel->setTransactionDetails($order->getPayment(), $paymentIntent);
                $this->orderHelper->saveOrder($order);
            }

            if ($this->paymentIntentHelper->canConfirm($paymentIntent))
            {
                $paymentMethodId = $multishippingQuoteModel->getPaymentMethodId();
                $confirmParams = $this->paymentIntentHelper->getMultishippingConfirmParams($paymentMethodId, $paymentIntent);
                $paymentIntent = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);

                if ($paymentIntentModel->requiresAction($paymentIntent))
                    throw new SCANeededException($paymentIntent->client_secret);
            }
        }
        catch (SCANeededException $e)
        {
            throw $e;
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $this->helper->logError($e->getMessage());
            $this->setAddressErrorForRemainingOrders($quote, $e->getMessage());
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }
        catch (LocalizedException $e)
        {
            $this->helper->logError($e->getMessage());
            $this->setAddressErrorForRemainingOrders($quote, $e->getMessage());
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            $this->setAddressErrorForRemainingOrders($quote, __("A server side error has occurred. Please contact us for assistance."));
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }

        return $this->getFinalRedirectUrl($quoteId);
    }

    public function finalizeOrder($quoteId, $error = null)
    {
        $quote = $this->quoteHelper->loadQuoteById($quoteId);
        $successfulOrders = $this->getSuccessfulOrdersForQuoteId($quoteId);

        if ($error)
        {
            $this->onPaymentFailed($quote, $error);
        }
        else
        {
            // onPaymentConfirmed should only be called from the charge.succeeded observer.
            // Can't have two places mutating and saving the orders.
            // Needed because of redirect based PMs like ACH Direct Debit
            $quote = $this->quoteHelper->loadQuoteById($quoteId);
            $checkout = $this->getCheckout();
            $checkout->removeSuccessfulOrdersFromQuote($quote, $successfulOrders);
        }

        $this->eventManager->dispatch(
            'checkout_submit_all_after',
            ['orders' => $successfulOrders, 'quote' => $quote]
        );

        return $this->getFinalRedirectUrl($quoteId);
    }

    public function onPaymentFailed($quote, $error)
    {
        $this->setAddressErrorForRemainingOrders($quote, $error);

        $msg = __("Payment failed: %1", $error);

        $orderModels = $this->multishippingOrderCollection->getByQuoteId($quote->getId());
        foreach ($orderModels as $orderModel)
        {
            if ($orderModel->getOrderId())
            {
                $order = $this->orderHelper->loadOrderById($orderModel->getOrderId());
                if ($order && $order->getId())
                {
                    $this->orderHelper->addOrderComment($msg, $order);
                    $this->helper->cancelOrCloseOrder($order, true, true);
                    $this->orderHelper->saveOrder($order);
                }

                $orderModel->setLastError($error);
                $orderModel->save();
            }
        }
    }

    public function onPaymentConfirmed($quoteId, $successfulOrders, $paymentIntent = null)
    {
        $multishippingQuoteModel = $this->multishippingQuote->load($quoteId, 'quote_id');
        if (!$multishippingQuoteModel->getManualCapture())
        {
            $multishippingQuoteModel->setCaptured(true)->save();
        }

        if (!$paymentIntent)
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve(
                $multishippingQuoteModel->getPaymentIntentId(),
                ['expand' => ['latest_charge']]
            );
        }

        $riskScore = '';
        $riskLevel = 'NA';
        if ($paymentIntent && isset($paymentIntent->latest_charge))
        {
            if (isset($paymentIntent->latest_charge->outcome->risk_score) && $paymentIntent->latest_charge->outcome->risk_score >= 0) {
                $riskScore = $paymentIntent->latest_charge->outcome->risk_score;
            }
            if (isset($paymentIntent->latest_charge->outcome->risk_level)) {
                $riskLevel = $paymentIntent->latest_charge->outcome->risk_level;
            }
        }

        foreach ($successfulOrders as $order)
        {
            $this->paymentIntent->setTransactionDetails($order->getPayment(), $paymentIntent);

            if ($this->config->isAuthorizeOnly())
            {
                if ($this->config->isAutomaticInvoicingEnabled())
                {
                    $this->helper->invoiceOrder($order, $paymentIntent->id, \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE, true);
                }
            }
            else
            {
                $invoice = $this->helper->invoiceOrder($order, $paymentIntent->id, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            }

            if ($multishippingQuoteModel->getManualCapture())
            {
                $transactionType = "authorization";
                $this->helper->setProcessingState($order, __("Payment authorization succeeded."));
            }
            else
            {
                $transactionType = "capture";
                $this->helper->setProcessingState($order, __("Payment succeeded."));
            }

            $charge = $paymentIntent->latest_charge;

            if ($this->config->isStripeRadarEnabled() && !empty($charge->outcome->type) && $charge->outcome->type == "manual_review")
            {
                $this->orderHelper->holdOrder($order);
            }

            //Risk Data to sales_order table
            if ($riskScore >= 0)
            {
                $order->setStripeRadarRiskScore($riskScore);
            }
            $order->setStripeRadarRiskLevel($riskLevel);

            $this->orderHelper->saveOrder($order);
            $transaction = $this->helper->addTransaction($order, $paymentIntent->id, $transactionType);
            $this->helper->saveTransaction($transaction);
        }
    }

    public function setAddressErrorForRemainingOrders($quote, $error)
    {
        $addressErrors = $this->session->getAddressErrors();
        $successfulOrderIds = $this->session->getOrderIds();

        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        if ($error)
        {
            foreach ($shippingAddresses as $shippingAddress)
            {
                $id = $shippingAddress->getId();

                if (!isset($addressErrors[$id]))
                    $addressErrors[$id] = (string)$error;
            }

            $this->session->setAddressErrors($addressErrors);
            $this->session->setOrderIds([]);
        }
    }

    public function getSuccessfulOrdersForQuoteId($quoteId)
    {
        $orders = [];
        $orderModels = $this->multishippingOrderCollection->getByQuoteId($quoteId);
        foreach ($orderModels as $orderModel)
        {
            if (!$orderModel->getLastError() && $orderModel->getOrderId())
            {
                $order = $this->orderHelper->loadOrderById($orderModel->getOrderId());
                if ($order && $order->getId())
                    $orders[] = $order;
            }
        }

        return $orders;
    }

    public function cancelOrdersForQuoteId($quoteId, $errorMessage)
    {
        $orderModels = $this->multishippingOrderCollection->getByQuoteId($quoteId);
        foreach ($orderModels as $orderModel)
        {
            if ($orderModel->getOrderId())
            {
                $order = $this->orderHelper->loadOrderById($orderModel->getOrderId());
                if ($order && $order->getId())
                {
                    $this->orderHelper->addOrderComment($errorMessage, $order, true);
                    $this->helper->cancelOrCloseOrder($order, true, true);
                    $this->orderHelper->saveOrder($order);
                }
            }
        }

        // Also delete the order references from the multishipping table
        $this->multishippingOrderCollection->deleteByQuoteId($quoteId);
    }

    public function captureOrdersFromAdminArea($orders, $paymentIntentId, $payment, $baseAmount, $retryAuthorization)
    {
        try
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);
        }
        catch (\Exception $e)
        {
            return $this->helper->throwError("Could not retrieve Payment Intent: " . $e->getMessage());
        }

        if (in_array($paymentIntent->status, ["requires_payment_method", "requires_confirmation", "requires_action", "processing"]))
            $this->helper->throwError(__("The payment for this order has not been authorized yet."));

        if (in_array($paymentIntent->status, ["canceled", "succeeded"]))
        {
            // If the charge was captured or canceled externally, fallback to the error handling of normal captures.
            return $this->helper->capture($paymentIntentId, $payment, $baseAmount, $retryAuthorization);
        }

        if ($paymentIntent->status != "requires_capture")
            return $this->helper->throwError("The payment intent has a status of " . $paymentIntent->status . " and cannot be captured. Please contact magento@stripe.com for assistance.");

        $ordersTotal = 0;
        $incrementIds = [];
        foreach ($orders as $relatedOrder)
        {
            $incrementIds[] = "#" . $relatedOrder->getIncrementId();
            $ordersTotal += $relatedOrder->getGrandTotal();
        }

        $order = $payment->getOrder();

        $humanReadableOrdersTotal = $this->currencyHelper->addCurrencySymbol($ordersTotal, $paymentIntent->currency);

        if ($this->areOrdersFullyProcessed($orders, $order, $baseAmount))
        {
            $authorizedAmount = $this->currencyHelper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $order);
            $magentoAmount = $this->getFinalAmountWithCapture($orders, $order, $baseAmount, $paymentIntent->currency);
            $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $paymentIntent->currency);
            $finalAmount = $stripeAmount;

            if ($stripeAmount > $paymentIntent->amount)
            {
                $finalAmount = $paymentIntent->amount;
                $msg = __("The amount to be captured (%1) is larger than the authorized amount of %2. We will capture %2 instead.", $magentoAmount, $authorizedAmount);
                $this->helper->addWarning($msg);
                $this->orderHelper->addOrderComment($msg, $order);
            }

            $this->helper->getCache()->save($value = "1", $key = "admin_captured_" . $paymentIntent->id, ["stripe_payments"], $lifetime = 60 * 60);

            try
            {
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $finalAmount]);
                $charge = $this->config->getStripeClient()->charges->retrieve($paymentIntent->latest_charge, []);
            }
            catch (\Exception $e)
            {
                return $this->helper->throwError($e->getMessage());
            }

            $humanReadableAmount = $this->currencyHelper->getFormattedStripeAmount($finalAmount, $paymentIntent->currency, $order);
            if ($magentoAmount < $ordersTotal)
            {
                $msg = __("Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);
                $this->helper->overrideInvoiceActionComment($payment, $msg);
            }
            else
            {
                $msg = __("Captured %1 online. This is a joint amount for %2 multishipping orders.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);
                $this->helper->overrideInvoiceActionComment($payment, $msg);
            }

            $riskScore = '';
            $riskLevel = 'NA';
            if (isset($charge->outcome->risk_score) && $charge->outcome->risk_score >= 0) {
                $riskScore = $charge->outcome->risk_score;
            }
            if (isset($charge->outcome->risk_level)) {
                $riskLevel = $charge->outcome->risk_level;
            }

            // Process all other related orders
            foreach ($orders as $relatedOrder)
            {
                if ($relatedOrder->getId() == $order->getId())
                    continue;

                $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType = "capture", $parentTransactionId = $paymentIntent->id);
                $this->helper->saveTransaction($transaction);

                if ($relatedOrder->getState() == "pending")
                    $this->helper->setProcessingState($relatedOrder, $msg);
                else
                    $this->orderHelper->addOrderComment($msg, $relatedOrder);

                //Risk Data to sales_order table
                if ($riskScore >= 0) {
                    $order->setStripeRadarRiskScore($riskScore);
                }
                $order->setStripeRadarRiskLevel($riskLevel);

                $this->orderHelper->saveOrder($relatedOrder);
            }
        }
        else
        {
            $finalAmount = $this->convert->baseAmountToCurrencyAmount($baseAmount, $paymentIntent->currency, $order);
            $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($finalAmount, $paymentIntent->currency);
            $humanReadableDate = $this->getFormattedCaptureDate($order);

            $msg = __("Scheduled %1 to be captured via cron on %5. This amount is part of %2 multishipping orders totaling %3. To capture now instead, invoice or cancel all multishipping orders (%4). ", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, implode(", ", $incrementIds), $humanReadableDate);
            $this->helper->addWarning($msg);
            $this->helper->overrideInvoiceActionComment($payment, $msg);
            $this->orderHelper->saveOrder($order);
        }
    }

    public function getFormattedCaptureDate($order)
    {
        $captureTime = strtotime($order->getCreatedAt());
        $captureTime += (6 * 24 * 60 * 60 + 1 * 60 * 60); // 6 days and 1 hour after order placement
        $humanReadableDate = date('l jS \of F', $captureTime);
        return $humanReadableDate;
    }

    public function captureOrdersFromCronJob($orders, $paymentIntentId)
    {
        if (empty($orders))
            throw new GenericException("No orders specified.");

        if ($this->areOrdersUnprocessed($orders))
            throw new SkipCaptureException("Action needed", SkipCaptureException::ORDERS_NOT_PROCESSED);

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);

        if (in_array($paymentIntent->status, ["requires_payment_method", "requires_confirmation", "requires_action", "processing"]))
            throw new SkipCaptureException(__("The payment for this order has not been authorized yet."));

        if (in_array($paymentIntent->status, ["canceled", "succeeded"]))
            throw new SkipCaptureException("Cannot capture $paymentIntentId because it has a status of {$paymentIntent->status}", SkipCaptureException::INVALID_STATUS);

        if ($paymentIntent->status != "requires_capture")
            throw new GenericException("The payment intent has a status of " . $paymentIntent->status . " and cannot be captured. Please contact magento@stripe.com for assistance.");

        $exampleOrder = reset($orders);

        $ordersTotal = 0;
        foreach ($orders as $relatedOrder)
            $ordersTotal += $relatedOrder->getGrandTotal();

        $humanReadableOrdersTotal = $this->currencyHelper->addCurrencySymbol($ordersTotal, $paymentIntent->currency);

        $authorizedAmount = $this->currencyHelper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $exampleOrder);
        $magentoAmount = $this->getFinalAmountWithCapture($orders, null, null, $paymentIntent->currency);
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $paymentIntent->currency);
        $finalAmount = $stripeAmount;

        if ($stripeAmount > $paymentIntent->amount)
        {
            $finalAmount = $paymentIntent->amount;
            $msg = __("Cron: The amount to be captured (%1) is larger than the authorized amount of %2. We will capture %2 instead. Transaction ID: %3", $magentoAmount, $authorizedAmount, $paymentIntentId);
            $this->helper->logError($msg);
        }

        if ($finalAmount == 0)
            throw new SkipCaptureException("The total capture amount is 0.", SkipCaptureException::ZERO_AMOUNT);

        $this->helper->getCache()->save($value = "1", $key = "admin_captured_" . $paymentIntent->id, ["stripe_payments"], $lifetime = 60 * 60);

        $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $finalAmount]);

        $humanReadableAmount = $this->currencyHelper->getFormattedStripeAmount($finalAmount, $paymentIntent->currency, $exampleOrder);
        if ($magentoAmount < $ordersTotal)
        {
            $msg = __("Cron: Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds. Transaction ID: %4", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, $paymentIntentId);
        }
        else
        {
            $msg = __("Cron: Captured %1 online. This amount is part of %2 multishipping orders totaling %3. Transaction ID: %4", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, $paymentIntentId);
        }

        $this->helper->logInfo($msg);

        // Process all other related orders
        foreach ($orders as $relatedOrder)
        {
            $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType = "capture", $parentTransactionId = $paymentIntent->id);
            $this->helper->saveTransaction($transaction);

            if ($relatedOrder->getState() == "pending")
                $this->helper->setProcessingState($relatedOrder, $msg);
            else
                $this->orderHelper->addOrderComment($msg, $relatedOrder);

            $this->orderHelper->saveOrder($relatedOrder);
        }
    }

    public function areOrdersUnprocessed($orders)
    {
        foreach ($orders as $order)
        {
            $remaining = $baseRemaining = 0;

            if ($order->getTotalPaid() > 0 || $order->getBaseTotalPaid() > 0)
                return false;
        }

        return true;
    }

    public function areOrdersFullyProcessed($orders, $currentOrder = null, $baseCaptureAmount = null)
    {
        $total = 0;
        $processed = 0;

        foreach ($orders as $order)
        {
            $total += $order->getGrandTotal();
            $processed += ($order->getTotalPaid() + $order->getTotalCanceled());

            if ($currentOrder && $order->getId() == $currentOrder->getId())
                $processed += $this->convert->baseAmountToCurrencyAmount($baseCaptureAmount, $order->getOrderCurrencyCode(), $order);
        }

        return ($processed >= $total);
    }

    public function getFinalAmountWithCapture($orders, $currentOrder, $baseCaptureAmount, $currency)
    {
        $total = 0;

        foreach ($orders as $order)
        {
            $currencyPrecision = $this->convert->getCurrencyPrecision($order->getOrderCurrencyCode());
            $total += round(floatval($order->getGrandTotal()), $currencyPrecision);
            $total -= round(floatval($order->getTotalRefunded()), $currencyPrecision);
            $total -= round(floatval($order->getTotalCanceled()), $currencyPrecision);

            if ($currentOrder && $order->getId() == $currentOrder->getId())
                $total += $this->convert->baseAmountToCurrencyAmount($baseCaptureAmount, $currency, $currentOrder);
        }

        return $total;
    }

    public function getFinalAmountWithRefund($orders, $currentOrder, $baseRefundAmount, $currency)
    {
        return $this->getFinalAmountWithCapture($orders, $currentOrder, -$baseRefundAmount, $currency);
    }


    public function isMultishippingPayment($paymentIntent)
    {
        $orders = $this->helper->getOrdersByTransactionId($paymentIntent->id);

        if (count($orders) <= 1)
            return false;

        if (empty($paymentIntent->metadata->Multishipping))
            return false;

        return true;
    }

    public function isMultishippingQuote($quoteId, $quote = null)
    {
        if (!$quote && is_numeric($quoteId))
            $quote = $this->quoteHelper->loadQuoteById($quoteId);

        if (!$quote || !$quote->getId())
            return false;

        return (bool)$quote->getIsMultiShipping();
    }
}
