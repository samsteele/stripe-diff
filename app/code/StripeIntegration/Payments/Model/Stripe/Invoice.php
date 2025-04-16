<?php

namespace StripeIntegration\Payments\Model\Stripe;

use StripeIntegration\Payments\Exception\Exception;
use StripeIntegration\Payments\Exception\AmountMismatchException;

class Invoice
{
    use StripeObjectTrait;

    private $objectSpace = 'invoices';
    private $helper;
    private $config;
    private $addressHelper;
    private $stripeInvoiceItemModelFactory;
    private $stripeCustomerId;
    private $stripeShippingRateModelFactory;
    private $currencyHelper;
    private $invoiceItemHelper;
    private $orderDiscountsFactory;
    private $applyDiscountsOnLineItems;
    private $stripeCouponModelFactory;
    private $order;
    private $orderDiscounts;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoiceItem $invoiceItemHelper,
        \StripeIntegration\Payments\Model\Stripe\InvoiceItemFactory $stripeInvoiceItemModelFactory,
        \StripeIntegration\Payments\Model\Stripe\ShippingRateFactory $stripeShippingRateModelFactory,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $stripeCouponModelFactory,
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Order\DiscountsFactory $orderDiscountsFactory
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->helper = $helper;
        $this->addressHelper = $addressHelper;
        $this->currencyHelper = $currencyHelper;
        $this->invoiceItemHelper = $invoiceItemHelper;
        $this->stripeInvoiceItemModelFactory = $stripeInvoiceItemModelFactory;
        $this->stripeShippingRateModelFactory = $stripeShippingRateModelFactory;
        $this->stripeCouponModelFactory = $stripeCouponModelFactory;
        $this->config = $config;
        $this->orderDiscountsFactory = $orderDiscountsFactory;
    }

    public function fromOrder($order, $stripeCustomerId, $additionalData = [])
    {
        $daysDue = $order->getPayment()->getAdditionalInformation('days_due');

        if (!is_numeric($daysDue))
            $this->helper->throwError("You have specified an invalid value for the invoice due days field.");

        if ($daysDue < 1)
            $this->helper->throwError("The invoice due days must be greater or equal to 1.");

        $this->stripeCustomerId = $stripeCustomerId;

        $data = [
            'customer' => $stripeCustomerId,
            'currency' => $order->getOrderCurrencyCode(),
            'collection_method' => 'send_invoice',
            'description' => __("Order #%1 by %2", $order->getRealOrderId(), $order->getCustomerName()),
            'days_until_due' => $daysDue,
            'metadata' => $this->config->getMetadata($order),
            'pending_invoice_items_behavior' => 'exclude'
        ];

        $this->order = $order;
        $this->orderDiscounts = $this->orderDiscountsFactory->create()->fromOrder($order);

        if (!$order->getIsVirtual())
        {
            $address = $order->getShippingAddress();
            $shippingAddress = $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
            $data['shipping_details'] = [
                "address" => $shippingAddress["address"],
                "name" => $shippingAddress["name"],
                "phone" => $shippingAddress["phone"]
            ];
        }

        if (!empty($additionalData))
        {
            $data = array_merge($data, $additionalData);
        }

        try
        {
            $this->createObject($data);
        }
        catch (\Exception $e)
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("The invoice for order #%1 could not be created in Stripe: %2", $order->getIncrementId(), $e->getMessage()));
        }

        return $this;
    }

    public function buildFromOrderBreakdown()
    {
        if (!$this->order)
        {
            throw new Exception("The order object is not set.");
        }

        $this->validateCanBreakdownOrder();
        $this->addLineItems();
        $this->applyDiscounts();
        $this->setShippingCost();
        $this->validateAmount();

        return $this;
    }

    private function applyDiscounts()
    {
        $order = $this->order;
        $allItemsRules = $this->orderDiscounts->getAllItemsRules();
        $discounts = [];

        if (!empty($allItemsRules))
        {
            $discounts = $this->createDiscountsFromRules($allItemsRules, $order);
        }

        if (!$order->getIsVirtual() && $order->getShippingDiscountAmount() > 0)
        {
            $stripeCouponModel = $this->stripeCouponModelFactory->create()->fromFixedAmount(
                $order->getShippingDiscountAmount(),
                $order->getOrderCurrencyCode(),
                __("Shipping Discount"),
                "shipping"
            );

            if ($stripeCouponModel->getId())
            {
                $discounts[] = [
                    'coupon' => $stripeCouponModel->getId()
                ];
            }
        }

        if (!empty($discounts))
        {
            $this->update([
                'discounts' => $discounts
            ]);
        }

        return $this;
    }

    private function addLineItems()
    {
        $order = $this->order;
        $addedItems = false;
        $specificItemsRules = $this->orderDiscounts->getSpecificItemsRules();

        foreach ($order->getAllItems() as $orderItem)
        {
            if (!$this->invoiceItemHelper->shouldIncludeOnInvoice($orderItem))
            {
                continue;
            }

            $appliedRules = $specificItemsRules[$orderItem->getId()] ?? [];
            $this->stripeInvoiceItemModelFactory->create()->fromOrderItem($orderItem, $order, $this->stripeCustomerId, $this->getId(), $appliedRules);
            $addedItems = true;
        }

        if ($addedItems)
        {
            $this->reload();
        }

        return $this;
    }

    private function setShippingCost()
    {
        $order = $this->order;
        if ($order->getIsVirtual())
        {
            return $this;
        }

        $stripeShippingRateModel = $this->stripeShippingRateModelFactory->create()->fromOrder($order);

        if ($stripeShippingRateModel->getId()) // It may not have an ID if there is no shipping cost
        {
            $this->update([
                'shipping_cost' => [
                    'shipping_rate' => $stripeShippingRateModel->getId()
                ]
            ]);
        }

        return $this;
    }

    private function validateCanBreakdownOrder()
    {
        $order = $this->order;
        $store = $order->getStore();
        $isStripeTaxEnabled = $this->config->isStripeTaxEnabled($store);
        $taxCalculationAlgorithm = $this->config->getTaxCalculationAlgorithm($store);
        $catalogPriceIncludesTax = $this->config->priceIncludesTax($store->getId());
        $crossBorderTradeEnabled = $this->config->isCrossBorderTradeEnabled($store);
        $areDiscountsAppliedOnPriceIncludingTax = $this->config->areDiscountsAppliedOnPriceIncludingTax($store);

        if ($taxCalculationAlgorithm == \Magento\Tax\Model\Calculation::CALC_UNIT_BASE)
        {
            throw new Exception(__("Stripe does not support unit price based tax calculation."));
        }

        if ($taxCalculationAlgorithm == \Magento\Tax\Model\Calculation::CALC_TOTAL_BASE)
        {
            throw new Exception(__("Stripe does not support total price based tax calculation."));
        }

        if ($catalogPriceIncludesTax && !$crossBorderTradeEnabled && !$isStripeTaxEnabled)
        {
            throw new Exception(__("When catalog prices include tax, cross-border trade must be enabled."));
        }

        if (!$order->getIsVirtual() && $order->getShippingDiscountAmount() > 0 && $order->getTaxAmount() > 0)
        {
            throw new Exception(__("Stripe does not support shipping discounts when the order has tax."));
        }

        // Shipping taxes are not supported
        $shippingTaxAmount = $order->getShippingTaxAmount();
        if (is_numeric($shippingTaxAmount) && $shippingTaxAmount > 0)
        {
            throw new Exception("Shipping tax is not supported.");
        }

        // When the order has discounts
        if (!empty($order->getDiscountAmount()))
        {
            if ($order->getTaxAmount() > 0 && $this->config->isTaxCalculationAppliedBeforeDiscount())
            {
                throw new Exception(__("Stripe does not support discounts applied after the tax calculation."));
            }

            if ($catalogPriceIncludesTax && !$areDiscountsAppliedOnPriceIncludingTax)
            {
                throw new Exception(__("When catalog prices include tax, discounts must be applied on the tax inclusive price."));
            }

            if (!$catalogPriceIncludesTax && $areDiscountsAppliedOnPriceIncludingTax)
            {
                throw new Exception(__("When catalog prices do not include tax, discounts must be applied on the tax exclusive price."));
            }
        }

        return $this;
    }

    private function validateAmount()
    {
        $magentoAmount = $this->order->getGrandTotal();
        $currency = $this->order->getOrderCurrencyCode();

        $invoice = $this->getStripeObject();
        if (!$invoice)
        {
            throw new Exception("The invoice object is not set.");
        }

        $amount = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

        if ($amount != $invoice->amount_due)
        {
            $formattedMagentoAmount = $this->currencyHelper->formatStripePrice($amount, $currency);
            $formattedInvoiceAmount = $this->currencyHelper->formatStripePrice($invoice->amount_due, $currency);
            throw new AmountMismatchException("The invoice amount is $formattedInvoiceAmount, but the order amount is $formattedMagentoAmount.");
        }

        return $this;
    }

    public function finalize()
    {
        $invoice = $this->config->getStripeClient()->invoices->finalizeInvoice($this->getStripeObject()->id, []);
        $this->setObject($invoice);

        return $this;
    }

    public function send()
    {
        $invoice = $this->config->getStripeClient()->invoices->sendInvoice($this->getStripeObject()->id, []);
        $this->setObject($invoice);

        return $this;
    }

    private function createDiscountsFromRules($rules, $order)
    {
        $discounts = [];

        foreach ($rules as $rule)
        {
            $stripeCouponModel = $this->stripeCouponModelFactory->create()->fromRule($rule, $order);
            if ($stripeCouponModel->getId())
            {
                $discounts[] = [
                    'coupon' => $stripeCouponModel->getId()
                ];
            }
        }

        return $discounts;
    }

    public function archive()
    {
        $invoice = $this->getStripeObject();
        $hour = date('H');
        $minute = date('i');
        $second = date('s');
        $newInvoiceNumber = $invoice->number . "-archived-$hour$minute$second";
        $this->update([
            'number' => $newInvoiceNumber
        ]);

        return $this;
        }
    }
