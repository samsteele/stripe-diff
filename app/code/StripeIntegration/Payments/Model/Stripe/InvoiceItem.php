<?php

namespace StripeIntegration\Payments\Model\Stripe;

use StripeIntegration\Payments\Exception\Exception;

class InvoiceItem
{
    use StripeObjectTrait;

    private $objectSpace = 'invoiceItems';
    private $config;
    private $helper;
    private $stripeProductModelFactory;
    private $stripePriceModelFactory;
    private $stripeTaxRateModelFactory;
    private $taxHelper;
    private $stripeCouponModelFactory;
    private $invoiceItemHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoiceItem $invoiceItemHelper,
        \StripeIntegration\Payments\Model\Stripe\ProductFactory $stripeProductModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PriceFactory $stripePriceModelFactory,
        \StripeIntegration\Payments\Model\Stripe\TaxRateFactory $stripeTaxRateModelFactory,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $stripeCouponModelFactory
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->config = $config;
        $this->helper = $helper;
        $this->taxHelper = $taxHelper;
        $this->invoiceItemHelper = $invoiceItemHelper;
        $this->stripeProductModelFactory = $stripeProductModelFactory;
        $this->stripePriceModelFactory = $stripePriceModelFactory;
        $this->stripeTaxRateModelFactory = $stripeTaxRateModelFactory;
        $this->stripeCouponModelFactory = $stripeCouponModelFactory;
    }

    public function fromOrderGrandTotal($order, $customerId, $invoiceId)
    {
        $data = [
            'customer' => $customerId,
            'unit_amount' => $this->helper->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $order->getOrderCurrencyCode()),
            'currency' => $order->getOrderCurrencyCode(),
            'description' => __("Order #%1", $order->getIncrementId()),
            'quantity' => 1,
            'invoice' => $invoiceId
        ];

        try
        {
            $this->createObject($data);
        }
        catch (\Exception $e)
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("The invoice item could not be created in Stripe: %1", $e->getMessage()));
        }

        return $this;
    }

    public function fromOrderItem($item, $order, $customerId, $invoiceId, $appliedRules)
    {
        $stripeProductModel = $this->stripeProductModelFactory->create()->fromOrderItem($item);
        $stripePriceModel = $this->stripePriceModelFactory->create()->fromOrderItem($item, $order, $stripeProductModel->getStripeObject());

        $description = $item->getName();
        if ($item->getParentItem() && $item->getParentItem()->getProductType() == "bundle")
        {
            $description = $item->getParentItem()->getName() . " - " . $description;
        }
        else if ($item->getProductType() == "configurable")
        {
            $selections = [];
            $attributes = $item->getProductOptionByCode('attributes_info');
            foreach ($attributes as $attribute)
            {
                if (!isset($attribute['value']))
                    continue;

                $selections[] = $attribute['value'];
            }

            if (count($selections) > 0)
            {
                $description = $description . " - " . implode(", ", $selections);
            }
        }

        $data = [
            'customer' => $customerId,
            'price' => $stripePriceModel->getId(),
            'currency' => $order->getOrderCurrencyCode(),
            'description' => $description,
            'quantity' => $item->getQtyOrdered(),
            'invoice' => $invoiceId,
            'tax_rates' => $this->getOrderItemTaxRateIds($item, $order),
            'discountable' => true,
            'discounts' => $this->getDiscounts($item, $order, $appliedRules)
        ];

        $this->createObject($data);

        return $this;
    }

    private function getDiscounts($orderItem, $order, $appliedRules)
    {
        $discounts = [];

        $ruleIds = $this->invoiceItemHelper->getOrderItemAppliedRuleIds($orderItem);

        if (empty($ruleIds))
        {
            return $discounts;
        }

        foreach (explode(",", $ruleIds) as $ruleId)
        {
            if (!isset($appliedRules[$ruleId]))
                continue;

            $stripeCouponModel = $this->stripeCouponModelFactory->create()->fromOrderItem($orderItem, $order, $ruleId);
            if ($stripeCouponModel->getId())
            {
                $discounts[] = [
                    'coupon' => $stripeCouponModel->getId()
                ];
            }
        }

        return $discounts;
    }

    private function getOrderItemTaxRateIds($orderItem, $order)
    {
        $taxRateIds = [];
        $priceIncludesTax = $this->config->priceIncludesTax($order->getStoreId());

        $displayName = __("Tax");
        $percentage = $this->taxHelper->getOrderItemTaxPercent($orderItem);
        $countryCode = $this->taxHelper->getTaxCountryCode($order);

        $stripeTaxRateModel = $this->stripeTaxRateModelFactory->create()->fromData(
            $displayName,
            $priceIncludesTax,
            $percentage,
            $countryCode
        );

        $taxRateIds[] = $stripeTaxRateModel->getId();

        return $taxRateIds;
    }
}
