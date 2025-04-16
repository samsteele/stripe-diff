<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\NoSuchEntityException;
use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Exception\GenericException;

class RecurringOrder
{
    public $quoteManagement = null;

    private $quoteFactory;
    private $storeManager;
    private $checkoutFlow;
    private $shipmentEstimation;
    private $dataObjectFactory;
    private $subscriptions;
    private $config;
    private $paymentsHelper;
    private $recurringOrderData;
    private $quoteHelper;
    private $orderHelper;
    private $subscriptionProductFactory;
    private $productHelper;
    private $subscriptionCart;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Helper\RecurringOrderData $recurringOrderData,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\Subscription\Cart $subscriptionCart,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Store\Model\Store $storeManager,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Api\ShipmentEstimationInterface $shipmentEstimation,
        \Magento\Framework\DataObject\Factory $dataObjectFactory
    ) {
        $this->paymentsHelper = $paymentsHelper;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->config = $config;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
        $this->quoteManagement = $quoteManagement;
        $this->checkoutFlow = $checkoutFlow;
        $this->subscriptionCart = $subscriptionCart;
        $this->shipmentEstimation = $shipmentEstimation;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->subscriptions = $subscriptions;
        $this->recurringOrderData = $recurringOrderData;
        $this->productHelper = $productHelper;
    }

    public function createFromSubscriptionItems($invoiceId)
    {
        $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, [
            'expand' => [
                'lines.data.price.product',
                'subscription'
            ]
        ]);

        $orderIncrementId = $invoice->subscription->metadata["Order #"];
        if (empty($orderIncrementId))
            throw new WebhookException("Error: This subscription does not match a Magento Order #", 202);

        $originalOrder = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$originalOrder->getId())
            throw new WebhookException("Error: Could not load original order #$orderIncrementId", 202);

        $newOrder = $this->reOrder($originalOrder, $invoice);

        return $newOrder;
    }

    public function createFromInvoiceId($invoiceId)
    {
        $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, ['expand' => ['subscription']]);

        if (empty($invoice->subscription->metadata["Order #"]))
            throw new WebhookException("The subscription on invoice $invoiceId is not associated with a Magento order", 202);

        $orderIncrementId = $invoice->subscription->metadata["Order #"];

        if (empty($invoice->subscription->metadata["Product ID"]))
            return $this->createFromSubscriptionItems($invoiceId);

        $originalOrder = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);

        if (!$originalOrder->getId())
            throw new WebhookException("Error: Could not load original order #$orderIncrementId", 202);

        $newOrder = $this->reOrder($originalOrder, $invoice);

        return $newOrder;
    }

    public function createFromQuoteId($quoteId, $invoiceId)
    {
        $newOrder = $this->reOrderFromQuoteId($quoteId, $invoiceId);

        return $newOrder;
    }

    private function getSubscriptionProductIds($invoice)
    {
        $subscriptionProductIds = [];

        /** @var \Stripe\InvoiceLineItem @invoiceLineItem */
        foreach ($invoice->lines->data as $invoiceLineItem)
        {
            $type = null;
            if (!empty($invoiceLineItem->price->product->metadata->{"Type"}))
                $type = $invoiceLineItem->price->product->metadata->{"Type"};

            if ($type == "Product")
            {
                $subscriptionProductIds[] = $invoiceLineItem->price->product->metadata->{"Product ID"};
            }
            else if (!$type && isset($invoiceLineItem->metadata["Product ID"]))
            {
                $subscriptionProductIds[] = $invoiceLineItem->metadata["Product ID"];
            }
            else if (!$type && isset($invoiceLineItem->metadata["SubscriptionProductIDs"]))
            {
                // Subscription created via PaymentElement in v3+
                $subscriptionProductIds = explode(",", $invoiceLineItem->metadata->{"SubscriptionProductIDs"});
            }
            else if ($type == "SubscriptionsTotal")
            {
                $subscriptionProductIds = explode(",", $invoiceLineItem->price->product->metadata->{"SubscriptionProductIDs"});
            }
            else
            {
                // As of v2.7.1, it is possible for an invoice to include an "Amount due" line item when a trial subscription activates
                // $this->webhooksHelper->log("Invoice {$invoice->id} includes an item which cannot be recognized as a subscription: " . $invoiceLineItem->description);
            }
        }

        return $subscriptionProductIds;
    }

    private function validateSubscriptionItems($originalOrder, $invoice)
    {
        $subscriptionProductIds = $this->getSubscriptionProductIds($invoice);

        if (empty($subscriptionProductIds))
            throw new WebhookException("This invoice does not have any product IDs associated with it", 202);

        $orderItems = $originalOrder->getAllItems();
        foreach ($orderItems as $orderItem)
        {
            if (in_array($orderItem->getProductId(), $subscriptionProductIds))
            {
                try
                {
                    $product = $this->productHelper->getProduct($orderItem->getProductId());
                }
                catch (NoSuchEntityException $e)
                {
                    throw new WebhookException("Product with ID " . $orderItem->getProductId() . " has been deleted.");
                }

                $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromProductId($orderItem->getProductId());
                if (!$subscriptionProductModel->isSubscriptionProduct())
                {
                    throw new WebhookException("Product with ID " . $orderItem->getProductId() . " is not a subscription product.");
                }
            }
        }
    }

    public function reOrder($originalOrder, $invoice)
    {
        $this->validateSubscriptionItems($originalOrder, $invoice);

        $quote = $this->createQuoteFrom($originalOrder);
        $this->setQuoteCustomerFrom($originalOrder, $quote);
        $this->setQuoteAddressesFrom($originalOrder, $quote);
        $this->setQuoteItemsFrom($originalOrder, $quote);
        $this->setQuoteDiscountFrom($originalOrder, $quote, $invoice->discount ?? null);
        $this->setQuoteShippingMethodFrom($originalOrder, $quote);
        $this->setQuotePaymentMethodFrom($originalOrder, $quote);

        // Collect Totals & Save Quote
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteHelper->saveQuote($quote);

        // Create Order From Quote
        $this->checkoutFlow->isRecurringSubscriptionOrderBeingPlaced = true;
        $order = $this->quoteManagement->submit($quote);
        $this->addOrderCommentsTo($order, $originalOrder->getIncrementId(), $invoice->subscription->id);
        $this->setTransactionDetailsFor($order, $invoice->payment_intent);
        $this->updatePaymentDetails($order, $invoice->charge, $invoice->payment_intent);

        return $order;
    }

    public function reOrderFromQuoteId($quoteId, $invoiceId)
    {
        $stripe = $this->config->getStripeClient();
        /** @var \Stripe\Invoice $invoice */
        $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['subscription']]);

        $quote = $this->quoteHelper->loadQuoteById($quoteId);
        $quote->setIsActive(1);

        // Set the payment method details
        $quote->setPaymentMethod("stripe_payments");
        $data = [
            'method' => 'stripe_payments',
            'additional_data' => [
                'is_recurring_subscription' => true
            ]
        ];

        $quote->getPayment()->importData($data);

        // Create Order From Quote
        $order = $this->quoteManagement->submit($quote);

        // Set the order transaction details
        $transactionId = $invoice->payment_intent;

        if ($transactionId)
        {
            $order->getPayment()
                ->setLastTransId($transactionId)
                ->setIsTransactionClosed(0);

            $this->paymentsHelper->addTransaction($order, $transactionId);
        }

        // Even if there is no transaction ID, i.e. in the case of a credit balance being applied, we still invoice the order and create a credit memo
        // for the difference.
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $order->setState($state)->setStatus($status);
        $this->orderHelper->saveOrder($order);
        $this->paymentsHelper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);

        // Add order comments
        try
        {
            $updateDate = new \DateTime($quote->getCreatedAt());

            if (!empty($invoice->subscription->metadata->{"Original Order #"}))
            {
                $originalOrderNumber = $invoice->subscription->metadata->{"Original Order #"};
                $comment = __("The customer has updated their subscription on %1. The initial subscription order was #%2. Recurring order generated from updated subscription with ID %3.", $updateDate->format("jS M Y"), $originalOrderNumber, $invoice->subscription->id);
            }
            else
            {
                $comment = __("The customer has updated their subscription on %1. Recurring order generated from updated subscription with ID %2.", $updateDate->format("jS M Y"), $invoice->subscription->id);
            }

            $order->setEmailSent(0);
            $order->addStatusToHistory(false, $comment, false)->save();
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
        }

        try
        {
            // Update the objects in Stripe
            $params = [
                'description' => "Recurring " . lcfirst($this->orderHelper->getOrderDescription($order)),
                'metadata' => [
                    'Order #' => $order->getIncrementId(),
                    'Original Order #' => null
                ]
            ];

            if ($invoice->charge)
            {
                $stripe->charges->update($invoice->charge, $params);
            }

            if ($invoice->payment_intent)
            {
                $stripe->paymentIntents->update($invoice->payment_intent, $params);
            }

            $stripe->subscriptions->update($invoice->subscription->id, $params);

            // Disassociate the subscription from the quote. We will use the order from now on.
            $subscriptionModel = $this->subscriptions->loadSubscriptionModelBySubscriptionId($invoice->subscription->id);
            if ($subscriptionModel && $subscriptionModel->getReorderFromQuoteId())
            {
                 $subscriptionModel->setReorderFromQuoteId(null);
                 $subscriptionModel->save();
            }

            // Release the quote to be deleted by cron jobs
            $quote->setIsActive(false);
            $quote->setIsUsedForRecurringOrders(false);
            $this->quoteHelper->saveQuote($quote);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
        }

        return $order;
    }

    public function updatePaymentDetails($order, $chargeId, $paymentIntentId)
    {
        $stripe = $this->config->getStripeClient();
        $params = [
            'description' => "Recurring " . lcfirst($this->orderHelper->getOrderDescription($order)),
            'metadata' => [
                'Order #' => $order->getIncrementId()
            ]
        ];

        if ($chargeId)
        {
            $stripe->charges->update($chargeId, $params);
        }

        if ($paymentIntentId)
        {
            $stripe->paymentIntents->update($paymentIntentId, $params);
        }
    }

    public function addOrderCommentsTo($order, $originalOrderIncrementId, $subscriptionId)
    {
        $comment = "Recurring order generated from subscription with ID $subscriptionId. ";
        $comment .= "Customer originally subscribed with order #$originalOrderIncrementId. ";
        $order->setEmailSent(0);
        $order->addStatusToHistory(false, $comment, false)->save();
    }

    public function setTransactionDetailsFor($order, $paymentIntentId)
    {
        $transactionId = $paymentIntentId;

        $order->getPayment()
            ->setLastTransId($transactionId)
            ->setIsTransactionClosed(0);

        $this->paymentsHelper->addTransaction($order, $transactionId);
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $order->setState($state)->setStatus($status);
        $this->orderHelper->saveOrder($order);

        // There should be one invoice
        foreach($order->getInvoiceCollection() as $invoice)
            $invoice->setTransactionId($transactionId)->save();
    }

    public function setQuoteDiscountFrom($originalOrder, &$quote, $stripeDiscountObject)
    {
        $couponCode = $originalOrder->getCouponCode();
        $couponModel = $this->orderHelper->getExpiringCoupon($originalOrder);

        if (!empty($couponCode))
        {
            if ($couponModel && $couponModel->expires())
            {
                if (!$stripeDiscountObject)
                {
                    // The coupon has expired
                }
                else
                {
                    // It has not yet expired
                    $quote->setCouponCode($couponCode);
                }
            }
            else
            {
                // The coupon or rule does not expire
                $quote->setCouponCode($couponCode);
            }
        }

        if (empty($stripeDiscountObject))
        {
            $stripeDiscountObject = 'none';
        }

        $this->recurringOrderData->discountObject = $stripeDiscountObject;
    }

    public function setQuotePaymentMethodFrom($originalOrder, &$quote, $data = [])
    {
        $quote->setPaymentMethod($originalOrder->getPayment()->getMethod());
        $quote->setInventoryProcessed(false);
        $quote->save(); // Needed before setting payment data
        $data = array_merge($data, ['method' => 'stripe_payments']); // We can only migrate subscriptions using the stripe_payments method

        if (empty($data['additional_data']))
            $data['additional_data'] = [];

        $data['additional_data']['is_recurring_subscription'] = true;

        $quote->setIsRecurringOrder(true);
        $quote->getPayment()->importData($data);
    }

    public function setQuoteShippingMethodFrom($originalOrder, &$quote)
    {
        if (!$originalOrder->getIsVirtual() && !$quote->getIsVirtual())
        {
            $availableMethods = $this->getAvaliableShippingMethodsFromQuote($quote);

            if (!in_array($originalOrder->getShippingMethod(), $availableMethods))
            {
                if (count($availableMethods) > 0)
                {
                    $msg = __("A Stripe subscription has been paid, but the shipping method '%1' from order #%2 is no longer available. We will use new shipping method '%3' to create a recurring subscription order.", $originalOrder->getShippingMethod(), $originalOrder->getIncrementId(), $availableMethods[0]);
                    $this->paymentsHelper->sendPaymentFailedEmail($quote, $msg);
                    $this->setQuoteShippingMethodByCode($quote, $availableMethods[0]);
                }
                else
                {
                    $msg = __("Could not create recurring subscription order. The shipping method '%1' from order #%2 is no longer available, and there are no alternative shipping methods to use.", $originalOrder->getShippingMethod(), $originalOrder->getIncrementId());
                    $this->paymentsHelper->sendPaymentFailedEmail($quote, $msg);
                    throw new WebhookException($msg);
                }
            }
            else
            {
                $this->setQuoteShippingMethodByCode($quote, $originalOrder->getShippingMethod());
            }
        }
    }

    public function setQuoteShippingMethodByCode($quote, $code)
    {
        $quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($code);

        $quote->setTotalsCollectedFlag(false)->collectTotals();
    }

    public function getAvaliableShippingMethodsFromQuote($quote)
    {
        $rates = [];
        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true);
        $address->collectShippingRates();
        $shippingRates = $address->getGroupedAllShippingRates();

        foreach ($shippingRates as $carrierRates)
        {
            foreach ($carrierRates as $rate)
            {
                $rates[] = $rate->getCode();
            }
        }

        return $rates;
    }

    protected function addBundleProduct($quote, $parentOrderItem)
    {
        try
        {
            $productModel = $this->productHelper->getProduct($parentOrderItem->getProductId());
        }
        catch (NoSuchEntityException $e)
        {
            throw new GenericException("Cannot add product " . $parentOrderItem->getName() . " to the order because it has been deleted.");
        }

        $productOptions = $parentOrderItem->getProductOptions();
        if (empty($productOptions['info_buyRequest']))
        {
            throw new GenericException("Cannot add product " . $parentOrderItem->getName() . " to the order because it is missing the original product options.");
        }

        $buyRequest = $productOptions['info_buyRequest'];
        if (isset($buyRequest['uenc']))
        {
            unset($buyRequest['uenc']);
        }
        $buyRequestDataObject = $this->dataObjectFactory->create($buyRequest);
        return $quote->addProduct($productModel, $buyRequestDataObject);
    }

    public function setQuoteItemsFrom($originalOrder, &$quote)
    {
        foreach ($originalOrder->getAllItems() as $orderItem)
        {
            $subscriptionProduct = $this->subscriptionProductFactory->create()->fromOrderItem($orderItem);
            if (!$subscriptionProduct->isSubscriptionProduct())
                continue;

            $quoteItem = $this->subscriptionCart->addItem($quote, $orderItem, true);
        }

        // Magento 2.3 backwards compatibility
        if (class_exists('Magento\Quote\Model\Quote\QuantityCollector'))
        {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quantityCollector = $objectManager->get(\Magento\Quote\Model\Quote\QuantityCollector::class);

            // Needed when Deferred Total Calculation is enabled
            $quantityCollector->collectItemsQtys($quote);
            $quote->setOrigData();
        }
    }

    public function setQuoteAddressesFrom($originalOrder, &$quote)
    {
        if ($originalOrder->getIsVirtual())
        {
            $data = $this->filterAddressData($originalOrder->getBillingAddress()->getData());
            $quote->getBillingAddress()->addData($data);
            $quote->setIsVirtual(true);
        }
        else
        {
            $data = $this->filterAddressData($originalOrder->getBillingAddress()->getData());
            $quote->getBillingAddress()->addData($data);

            $data = $this->filterAddressData($originalOrder->getShippingAddress()->getData());
            $quote->getShippingAddress()->addData($data);
        }
    }

    public function filterAddressData($data)
    {
        $allowed = ['prefix', 'firstname', 'middlename', 'lastname', 'email', 'suffix', 'company', 'street', 'city', 'country_id', 'region', 'region_id', 'postcode', 'telephone', 'fax', 'vat_id'];
        $remove = [];

        foreach ($data as $key => $value)
            if (!in_array($key, $allowed))
                $remove[] = $key;

        foreach ($remove as $key)
            unset($data[$key]);

        return $data;
    }

    public function createQuoteFrom($originalOrder)
    {
        $store = $this->storeManager->load($originalOrder->getStoreId());
        $store->setCurrentCurrencyCode($originalOrder->getOrderCurrencyCode());

        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setQuoteCurrencyCode($originalOrder->getOrderCurrencyCode());
        $quote->setCustomerEmail($originalOrder->getCustomerEmail());
        $quote->setIsRecurringOrder(true);

        return $quote;
    }

    public function setQuoteCustomerFrom($originalOrder, &$quote)
    {

        if ($originalOrder->getCustomerIsGuest())
        {
            $quote->setCustomerIsGuest(true);
        }
        else
        {
            $customer = $this->paymentsHelper->loadCustomerById($originalOrder->getCustomerId());
            $quote->assignCustomer($customer);
        }
    }

    public function getAddressDataFrom($address)
    {
        $data = [
            'prefix' => $address->getPrefix(),
            'firstname' => $address->getFirstname(),
            'middlename' => $address->getMiddlename(),
            'lastname' => $address->getLastname(),
            'email' => $address->getEmail(),
            'suffix' => $address->getSuffix(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'country_id' => $address->getCountryId(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'telephone' => $address->getTelephone(),
            'fax' => $address->getFax(),
            'vat_id' => $address->getVatId()
        ];

        return $data;
    }

    public function isShippingLineItem($lineItem)
    {
        return isset($lineItem->price->product->metadata->{"Type"}) && $lineItem->price->product->metadata->{"Type"} == "Shipping";
    }

    protected function getSubscriptionAmountFrom($invoice)
    {
        foreach ($invoice->lines->data as $lineItem)
        {
            if ($lineItem->type == "subscription" && !$this->isShippingLineItem($lineItem))
            {
                return $lineItem->amount / $lineItem->quantity;
            }
        }

        if (!empty($invoice->subscription->plan->amount))
        {
            return $invoice->subscription->plan->amount;
        }

        return null;
    }
}
