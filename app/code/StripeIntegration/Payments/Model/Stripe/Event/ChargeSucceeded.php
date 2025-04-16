<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class ChargeSucceeded
{
    use StripeObjectTrait;

    private $paymentIntentFactory;
    private $paymentMethodHelper;
    private $creditmemoHelper;
    private $webhooksHelper;
    private $subscriptionsHelper;
    private $dataHelper;
    private $config;
    private $helper;
    private $orderHelper;
    private $quoteHelper;
    private $multishippingHelper;
    private $json;
    private $currencyHelper;
    private $convert;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \Magento\Framework\Serialize\Serializer\Json $json
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->webhooksHelper = $webhooksHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->dataHelper = $dataHelper;
        $this->config = $config;
        $this->helper = $helper;
        $this->orderHelper = $orderHelper;
        $this->quoteHelper = $quoteHelper;
        $this->multishippingHelper = $multishippingHelper;
        $this->json = $json;
        $this->currencyHelper = $currencyHelper;
        $this->convert = $convert;
    }

    public function process($arrEvent, $object)
    {
        if (!empty($object['metadata']['Multishipping']))
        {
            $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);
            $paymentIntentModel = $this->paymentIntentFactory->create();

            foreach ($orders as $order)
            {
                $successfulOrders = $this->multishippingHelper->getSuccessfulOrdersForQuoteId($order->getQuoteId());
                $this->onMultishippingChargeSucceeded($successfulOrders, $order->getQuoteId());
                break;
            }

            return;
        }

        if ($this->webhooksHelper->wasCapturedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        $hasSubscriptions = $this->orderHelper->hasSubscriptionsIn($order->getAllItems());

        // Set the risk score and level
        if (isset($object['outcome']['risk_score']) && $object['outcome']['risk_score'] >= 0)
        {
            $order->setStripeRadarRiskScore($object['outcome']['risk_score']);
        }
        if (isset($object['outcome']['risk_level']))
        {
            $order->setStripeRadarRiskLevel($object['outcome']['risk_level']);
        }

        // Set the Stripe payment method
        $this->insertPaymentMethods($object, $order);

        $stripeInvoice = null;
        if (!empty($object['invoice']))
        {
            $stripeInvoice = $this->config->getStripeClient()->invoices->retrieve($object['invoice'], []);
            if ($stripeInvoice->billing_reason == "subscription_cycle" // A subscription has renewed
                || $stripeInvoice->billing_reason == "subscription_update" // A trial subscription was manually ended
                || $stripeInvoice->billing_reason == "subscription_threshold" // A billing threshold was reached
            )
            {
                // We may receive a charge.succeeded event from a recurring subscription payment. In that case we want to create
                // a new order for the new payment, rather than registering the charge against the original order.
                return;
            }
        }

        $wasTransactionPending = $order->getPayment()->getAdditionalInformation("is_transaction_pending");

        if (empty($object['payment_intent']))
            throw new WebhookException("This charge was not created by a payment intent.");

        $transactionId = $object['payment_intent'];

        $payment = $order->getPayment();
        $payment->setTransactionId($transactionId)
            ->setLastTransId($transactionId)
            ->setIsTransactionPending(false)
            ->setAdditionalInformation("is_transaction_pending", false) // this is persisted
            ->setIsTransactionClosed(0)
            ->setIsFraudDetected(false)
            ->save();

        if (!$order->getEmailSent() && $wasTransactionPending)
        {
            $this->orderHelper->sendNewOrderEmailFor($order);
        }

        $amountCaptured = ($object["captured"] ? $object['amount_captured'] : 0);

        $this->onTransaction($order, $object, $transactionId);

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($transactionId, []);
        if (empty($paymentIntent->metadata->{"Order #"}))
        {
            $this->config->getStripeClient()->paymentIntents->update($object['payment_intent'], [
                'metadata' => $this->config->getMetadata($order),
                'description' => $this->orderHelper->getOrderDescription($order)
            ]);
        }

        if ($amountCaptured > 0)
        {
            $this->helper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, true);
        }
        else if ($amountCaptured == 0) // Authorize Only mode
        {
            if ($hasSubscriptions)
            {
                // If it has trial subscriptions, we want a Paid invoice which will partially refund
                $this->helper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, true);
            }
        }

        if ($this->config->isStripeRadarEnabled() && !empty($object['outcome']['type']) && $object['outcome']['type'] == "manual_review")
            $this->orderHelper->holdOrder($order);

        $order = $this->orderHelper->saveOrder($order);

        // Update the payment intents table, because the payment method was created after the order was placed
        $paymentIntentModel = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
        $quoteId = $paymentIntentModel->getQuoteId();
        if ($quoteId == $order->getQuoteId())
        {
            $paymentIntentModel->setPmId($object['payment_method']);
            $paymentIntentModel->setOrderId($order->getId());
            if (is_numeric($order->getCustomerId()) && $order->getCustomerId() > 0)
                $paymentIntentModel->setCustomerId($order->getCustomerId());
            $paymentIntentModel->save();
        }

    }

    public function onMultishippingChargeSucceeded($successfulOrders, $quoteId)
    {
        $this->multishippingHelper->onPaymentConfirmed($quoteId, $successfulOrders);

        foreach ($successfulOrders as $order)
        {
            $this->orderHelper->sendNewOrderEmailFor($order);
        }
    }

    public function onTransaction($order, $object, $transactionId)
    {
        $action = __("Collected");
        if ($object["captured"] == false)
        {
            if ($order->getState() != "pending" && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Authorized");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
            $transactionAmount = $this->convert->stripeAmountToOrderAmount($object['amount'], $object['currency'], $order);
        }
        else
        {
            if ($order->getTotalPaid() >= $order->getGrandTotal() && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Captured");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
            $transactionAmount = $this->convert->stripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
        }

        $transaction = $order->getPayment()->addTransaction($transactionType, null, false);
        $transaction->setAdditionalInformation("amount", (string)$transactionAmount);
        $transaction->setAdditionalInformation("currency", $object['currency']);
        $transaction->save();

        if ($order->getState() == "canceled")
        {
            $this->orderHelper->addOrderComment(__("The order was unexpectedly in a canceled state when a payment was collected. Attempting to re-open the order."), $order);
            $this->resetItemQuantities($order);
        }

        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $humanReadableAmount = $this->currencyHelper->addCurrencySymbol($transactionAmount, $object['currency']);
        $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", $action, $humanReadableAmount, $transactionId);
        $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = false);
    }

    public function resetItemQuantities($order)
    {
        foreach ($order->getAllItems() as $item)
        {
            // Check if the item is cancelable
            if ($item->getQtyCanceled() > 0) {
                $item->setQtyCanceled(0);
            }

            // Set quantity to invoice
            $item->setQtyToInvoice($item->getQtyOrdered() - $item->getQtyInvoiced());
        }
    }

    private function insertPaymentMethods($paymentIntentResponse, $order)
    {
        $paymentMethodType = '';
        $cardData = [];
        if (isset($paymentIntentResponse['payment_method_details']['type'])
            && $paymentIntentResponse['payment_method_details']['type']) {
            $paymentMethod = $paymentIntentResponse['payment_method_details'];

            if ($paymentMethod['type'] === 'card') {
                $cardData = ['card_type' => $paymentMethod['card']['brand'], 'card_data' => $paymentMethod['card']['last4']];

                if (isset($paymentMethod['card']['wallet']['type']) && $paymentMethod['card']['wallet']['type']) {
                    $cardData['wallet'] = $paymentMethod['card']['wallet']['type'];
                }
            }
            $paymentMethodType = $paymentMethod['type'];
        }

        if ($paymentMethodType) {
            $this->paymentMethodHelper->savePaymentMethod($order, $paymentMethodType, $cardData);
        }
    }
}