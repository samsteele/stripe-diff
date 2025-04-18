<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\RefundOfflineException;
use StripeIntegration\Payments\Exception\GenericException;

class Refunds
{
    private $cache;
    private $multishippingHelper;
    private $config;
    private $helper;
    private $tokenHelper;
    private $orderHelper;
    private $convert;
    private $currencyHelper;

    public function __construct(
        \Magento\Framework\App\CacheInterface $cache,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Model\Config $config
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->multishippingHelper = $multishippingHelper;
        $this->tokenHelper = $tokenHelper;
        $this->convert = $convert;
        $this->currencyHelper = $currencyHelper;
    }

    public function checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency)
    {
        $refundedAndCanceledAmount = $refundedAmount + $canceledAmount;

        if ($remainingAmount <= 0)
        {
            if ($refundedAndCanceledAmount < $requestedAmount)
            {
                $humanReadable1 = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToMagentoAmount($requestedAmount - $refundedAndCanceledAmount, $currency), $currency);
                $humanReadable2 = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToMagentoAmount($requestedAmount, $currency), $currency);
                $msg = __('%1 out of %2 could not be refunded online. Creating an offline refund instead.', $humanReadable1, $humanReadable2);
                $this->helper->addWarning($msg);
                $this->orderHelper->addOrderComment($msg, $order);
            }

            return false;
        }

        if ($refundedAndCanceledAmount >= $requestedAmount)
        {
            return false;
        }

        return true;
    }

    public function setRefundedAmount($amount, $requestedAmount, $currency, $order)
    {
        $currency = strtolower($currency);
        $currencyPrecision = $this->currencyHelper->getCurrencyPrecision($currency);
        $orderCurrency = strtolower($order->getOrderCurrencyCode());
        $baseCurrency = strtolower($order->getBaseCurrencyCode());

        // If this is a partial refund (2nd or 3rd), there will be an amount set already which we need to adjust instead of overwrite
        if ($order->getTotalRefunded() > 0)
        {
            $diff = $amount - $requestedAmount;
            if ($diff == 0)
                return $this; // Let Magento set the refund amount

            $refunded = $this->convert->stripeAmountToMagentoAmount($diff, $currency);
        }
        else
        {
            $refunded = $this->convert->stripeAmountToMagentoAmount($amount, $currency);
        }

        if ($currency == $orderCurrency)
        {
            $order->setTotalRefunded($order->getTotalRefunded() + $refunded);
            $baseRefunded = $this->convert->orderAmountToBaseAmount($refunded, $currency, $order);
            $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $baseRefunded);
        }
        else if ($currency == $baseCurrency)
        {
            $rate = ($order->getBaseToOrderRate() ? $order->getBaseToOrderRate() : 1);
            $order->setTotalRefunded($order->getTotalRefunded() + round(floatval($refunded * $rate), $currencyPrecision));
            $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $refunded);
        }
        else
        {
            $this->helper->addWarning(__("Could not set order refunded amount because the currency %1 matches neither the order currency, nor the base currency.", $currency));
        }

        return $this;
    }

    public function getTransactionId(\Magento\Payment\Model\InfoInterface $payment)
    {
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getInvoice())
            $invoice = $payment->getCreditmemo()->getInvoice();
        else
            $invoice = null;

        if ($payment->getRefundTransactionId())
        {
            $transactionId = $payment->getRefundTransactionId();
        }
        else if ($invoice && $invoice->getTransactionId())
        {
            $transactionId = $invoice->getTransactionId();
        }
        else
        {
            $transactionId = $payment->getLastTransId();
        }

        if (empty($transactionId) || strpos($transactionId, "pi_") === false)
        {
            if ($this->helper->isAdmin())
            {
                throw new LocalizedException(__("The payment can only be refunded via the Stripe Dashboard. You can retry in offline mode instead."));
            }
            else
            {
                if ($this->isCancelation($payment))
                {
                    throw new RefundOfflineException(__("Canceling order offline."));
                }
                else
                {
                    throw new RefundOfflineException(__("Refunding order offline."));
                }
            }
        }

        return $this->tokenHelper->cleanToken($transactionId);
    }

    public function getBaseRefundAmount(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        if (empty($amount))
        {
            // Order cancelations
            $total = ($payment->getBaseAmountOrdered() - $payment->getBaseAmountPaid());
        }
        else
        {
            // Credit memos
            $total = $amount;
        }

        if (is_numeric($total))
            return $total;

        return 0;
    }

    public function getRefundAmount(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        $order = $payment->getOrder();

        if (empty($amount))
        {
            // Order cancelations
            $total = ($payment->getAmountOrdered() - $payment->getAmountPaid());
        }
        else
        {
            // Credit memos
            $creditmemo = $payment->getCreditmemo();

            if ($amount == $creditmemo->getBaseGrandTotal())
                $total = $creditmemo->getGrandTotal();
            else
                $total = $this->convert->baseAmountToCurrencyAmount($amount, $order->getOrderCurrencyCode(), $order);
        }

        if (is_numeric($total))
        {
            $currencyPrecision = $this->currencyHelper->getCurrencyPrecision($order->getOrderCurrencyCode());
            return round($total, $currencyPrecision);
        }

        return 0;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        $order = $payment->getOrder();
        $currency = $payment->getOrder()->getOrderCurrencyCode();
        $transactionId = $this->getTransactionId($payment);
        $amount = $this->getRefundAmount($payment, $amount);
        $requestedAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        $paymentIntents = $this->getOrderPaymentIntents($order);
        $refundableAmount = $this->getAmountRefundable($paymentIntents);
        $capturableAmount = $this->getAmountCapturable($paymentIntents);

        if ($this->isCancelation($payment) && $capturableAmount == 0)
        {
            $msg = __("Canceling order offline.");
            $this->orderHelper->addOrderComment($msg, $order);
            return $this;
        }

        if ($refundableAmount < $requestedAmount)
        {
            $humanReadable1 = $this->currencyHelper->getFormattedStripeAmount($requestedAmount, $currency, $order);
            $humanReadable2 = $this->currencyHelper->getFormattedStripeAmount($refundableAmount, $currency, $order);
            if ($refundableAmount == 0)
            {
                if ($this->helper->isAdmin())
                {
                    throw new LocalizedException(__("Requested a refund of %1, but the most amount that can be refunded online is %2. You can retry refunding offline instead.", $humanReadable1, $humanReadable2));
                }
                else
                {
                    // We may get here in cases of abandoned carts. The cron job will attempt to cancel the order.
                    if ($this->isCancelation($payment))
                    {
                        $msg = __("Canceling order offline.");
                    }
                    else
                    {
                        $msg = __("Requested an online refund of %1, but the most amount that can be refunded online is %2. Refunding offline instead.", $humanReadable1, $humanReadable2);
                    }
                    $this->orderHelper->addOrderComment($msg, $order);
                    return $this;
                }
            }
            else
                throw new LocalizedException(__("Requested a refund of %1, but the most amount that can be refunded online is %2.", $humanReadable1, $humanReadable2));
        }

        // Refund strategy with $refundableAmount and $capturableAmount:
        // - Fully cancel authorizations; it is not possible to partially refund the order if there are authorizations, because you must first capture them. You can only cancel the whole order.
        // - Refund the current invoice next; there should be only one.
        // - Refund paid amounts from subscription PIs; there can be one or more depending on how many subscriptions were in the cart.

        $refundedAmount = 0;
        $canceledAmount = 0;
        $remainingAmount = $requestedAmount;

        // 1. Fully cancel authorizations. It is not possible to partially refund the order if there are authorizations,
        // because you must first capture them. You can only cancel the whole order.
        /** @var \Stripe\PaymentIntent $paymentIntent */
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            $charges = $this->config->getStripeClient()->charges->all(['payment_intent' => $paymentIntentId]);
            if (empty($charges->data))
                continue;

            if ($paymentIntent->status != \StripeIntegration\Payments\Model\PaymentIntent::AUTHORIZED
                || $paymentIntent->amount > $remainingAmount)
                continue;

            foreach ($charges->data as $charge)
            {
                // If it is an uncaptured authorization
                if (!$charge->captured)
                {
                    $humanReadable = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToMagentoAmount($charge->amount, $currency), $currency);

                    // which has not expired yet
                    if (!$charge->refunded)
                    {
                        $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                        $msg = __('We refunded online/released the uncaptured amount of %1 via Stripe. Charge ID: %2', $humanReadable, $charge->id);
                        // We intentionally do not cancel the $charge in this block, there is a $paymentIntent->cancel() further down
                    }
                    // which has expired
                    else
                    {
                        $msg = __('We refunded offline the expired authorization of %1. Charge ID: %2', $humanReadable, $charge->id);
                    }

                    if ($this->isCancelation($payment))
                    {
                        $this->helper->overrideCancelActionComment($payment, $msg);
                    }
                    else
                    {
                        $this->orderHelper->addOrderComment($msg, $order);
                    }

                    $remainingAmount -= $charge->amount;
                    $canceledAmount += $charge->amount;
                }
            }

            // Fully cancel the payment intent
            $this->config->getStripeClient()->paymentIntents->cancel($paymentIntent->id, [
                "cancellation_reason" => "requested_by_customer"
            ]);
        }

        if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
        {
            $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
            return $this;
        }

        // 2. Refund the current invoice next; there should be only one match.
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            $charges = $this->config->getStripeClient()->charges->all(['payment_intent' => $paymentIntentId]);
            if (empty($charges->data))
                continue;

            if ($paymentIntentId != $transactionId)
                continue;

            foreach ($charges->data as $charge)
            {
                if ($charge->captured && !$charge->invoice)
                {
                    $amountToRefund = min($remainingAmount, $charge->amount - $charge->amount_refunded);
                    if ($amountToRefund <= 0)
                        continue;

                    $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                    $refund = $this->config->getStripeClient()->refunds->create([
                        'charge' => $charge->id,
                        'amount' => $amountToRefund,
                        'reason' => "requested_by_customer"
                    ]);

                    $humanReadable = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToMagentoAmount($amountToRefund, $currency), $currency);
                    $msg = __('We refunded online %1 via Stripe. Charge ID: %2', $humanReadable, $charge->id);
                    $this->orderHelper->addOrderComment($msg, $order);

                    $remainingAmount -= $amountToRefund;
                    $refundedAmount += $amountToRefund;
                }

                if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
                {
                    $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
                    return $this;
                }
            }
        }

        if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
        {
            $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
            return $this;
        }

        // 3. Refund amounts from subscription payments; there can be one or more depending on how many subscriptions were in the cart.
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            $charges = $this->config->getStripeClient()->charges->all(['payment_intent' => $paymentIntentId]);
            if (empty($charges->data))
                continue;

            foreach ($charges->data as $charge)
            {
                if ($charge->captured && $charge->invoice)
                {
                    $amountToRefund = min($remainingAmount, $charge->amount - $charge->amount_refunded);
                    if ($amountToRefund <= 0)
                        continue;

                    $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                    $refund = $this->config->getStripeClient()->refunds->create([
                        'charge' => $charge->id,
                        'amount' => $amountToRefund,
                        'reason' => "requested_by_customer"
                    ]);

                    $humanReadable = $this->currencyHelper->addCurrencySymbol($this->convert->stripeAmountToMagentoAmount($amountToRefund, $currency), $currency);
                    $msg = __('We refunded online %1 via Stripe. Charge ID: %2. Invoice ID: %3', $humanReadable, $charge->id, $charge->invoice);
                    $this->orderHelper->addOrderComment($msg, $order);

                    $remainingAmount -= $amountToRefund;
                    $refundedAmount += $amountToRefund;
                }

                if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
                {
                    $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
                    return $this;
                }
            }
        }

        // We are calling checkIfWeCanRefundMore one last time in case an order comment/warning needs to be added
        $this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency);
        $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
    }


    public function getAmountRefundable($paymentIntents)
    {
        $amount = 0;

        foreach ($paymentIntents as $pi)
        {
            $charges = $this->config->getStripeClient()->charges->all(['payment_intent' => $pi->id]);
            if (empty($charges->data))
                continue;

            foreach ($charges->data as $charge)
            {
                $amount += ($charge->amount - $charge->amount_refunded);
            }
        }

        return $amount;
    }

    public function getAmountCapturable($paymentIntents)
    {
        $capturable = 0;

        foreach ($paymentIntents as $pi)
        {
            $capturable += $pi->amount_capturable;
        }

        return $capturable;
    }

    public function getOrderPaymentIntents($order)
    {
        $orderPaymentIntents = [];
        $paymentIntentIds = [];
        $transactions = $this->helper->getOrderTransactions($order);
        foreach ($transactions as $transaction)
        {
            $id = $this->tokenHelper->cleanToken($transaction->getTxnId());
            if ($id)
                $paymentIntentIds[$id] = $id;
        }

        $lastTransId = $this->tokenHelper->cleanToken($order->getPayment()->getLastTransId());
        if ($lastTransId)
            $paymentIntentIds[$lastTransId] = $lastTransId;

        foreach ($paymentIntentIds as $id)
        {
            $pi = $this->config->getStripeClient()->paymentIntents->retrieve($id, ['expand' => ['charges', 'latest_charge']]);
            $orderPaymentIntents[$id] = $pi;
        }

        return $orderPaymentIntents;
    }

    public function isCancelation($payment)
    {
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getInvoice())
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public function refundMultishipping(\Stripe\PaymentIntent $paymentIntent, $payment, $baseAmount)
    {
        if (empty($paymentIntent->status) || $paymentIntent->status != "requires_capture")
            throw new GenericException("Cannot refund multishipping payment."); // We should never get this case

        $orders = $this->helper->getOrdersByTransactionId($paymentIntent->id);

        $totalAmount = 0;
        $processedAmount = 0;
        $incrementIds = [];
        foreach ($orders as $order)
        {
            $totalAmount += $order->getGrandTotal();
            $processedAmount += $order->getTotalPaid() + $order->getTotalCanceled();
            $incrementIds[] = "#" . $order->getIncrementId();
        }

        $order = $payment->getOrder();
        $currency = $payment->getOrder()->getOrderCurrencyCode();
        $amountToRefund = $this->getRefundAmount($payment, $baseAmount);
        $baseAmountToRefund = $this->getBaseRefundAmount($payment, $baseAmount);
        $humanReadableOrdersTotal = $this->currencyHelper->addCurrencySymbol($totalAmount, $currency);

        if ($this->isCancelation($payment))
        {
            $performOnlineCapture = (($amountToRefund + $processedAmount) >= $totalAmount);
        }
        else
        {
            // We do not add the refund because that is included as a processed amount inside $order->getTotalPaid()
            $performOnlineCapture = ($processedAmount >= $totalAmount);
        }

        if ($performOnlineCapture)
        {
            // Online capture which does not include refunded and canceled amounts
            $magentoAmount = $this->multishippingHelper->getFinalAmountWithRefund($orders, $order, $baseAmountToRefund, $currency);
            $stripeAmountToCapture = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

            $transactionType = "capture";

            if ($stripeAmountToCapture < 0)
            {
                $humanReadable = $this->currencyHelper->addCurrencySymbol($magentoAmount, $currency);
                throw new LocalizedException(__("Cannot refund %1.", $humanReadable));
            }
            else if ($stripeAmountToCapture == 0)
            {
                $this->config->getStripeClient()->paymentIntents->cancel($paymentIntent->id, []);
                $humanReadableAmount = $this->currencyHelper->getFormattedStripeAmount($paymentIntent->amount, $currency, $order);
                $msg = __("Canceled the authorization of %1 online. This amount includes %2 multishipping orders.", $humanReadableAmount, count($orders));
                $transactionType = "void";
            }
            else if ($stripeAmountToCapture < $paymentIntent->amount)
            {
                $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($magentoAmount, $currency);
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $stripeAmountToCapture]);
                $msg = __("Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);

            }
            else if ($stripeAmountToCapture == $paymentIntent->amount)
            {
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $stripeAmountToCapture]);
                $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($magentoAmount, $currency);
                $msg = __("Captured %1 online. This amount includes %2 multishipping orders.", $humanReadableAmount, count($orders));
            }
            else // $stripeAmountToCapture > $paymentIntent->amount
            {
                $humanReadable = $this->currencyHelper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $order);
                throw new LocalizedException(__("The most amount that can be captured online is %1.", $humanReadable));
            }

            // Process all other related orders
            foreach ($orders as $relatedOrder)
            {
                if ($relatedOrder->getId() == $order->getId())
                    continue;

                $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType, $parentTransactionId = $paymentIntent->id);
                $this->helper->saveTransaction($transaction);

                if ($relatedOrder->getState() == "pending")
                    $this->helper->setProcessingState($relatedOrder, $msg);
                else
                    $this->orderHelper->addOrderComment($msg, $relatedOrder);

                $this->orderHelper->saveOrder($relatedOrder);
            }

            $this->helper->addWarning($msg);
            $this->helper->overrideCancelActionComment($payment, $msg);
        }
        else
        {
            $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($amountToRefund, $currency);
            $humanReadableDate = $this->multishippingHelper->getFormattedCaptureDate($order);
            $msg = __("Scheduled %1 to be refunded via cron on %2. This amount is part of %3 multishipping orders totaling %4. To refund now instead, invoice or cancel all multishipping orders (%5). ", $humanReadableAmount, $humanReadableDate, count($orders), $humanReadableOrdersTotal, implode(", ", $incrementIds));
            throw new RefundOfflineException($msg);
        }
    }
}
