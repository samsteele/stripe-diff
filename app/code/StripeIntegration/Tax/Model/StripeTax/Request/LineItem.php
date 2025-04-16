<?php

namespace StripeIntegration\Tax\Model\StripeTax\Request;

use StripeIntegration\Tax\Helper\GiftOptions;
use StripeIntegration\Tax\Helper\Tax;

class LineItem
{
    public const AMOUNT_KEY = 'amount';
    public const PRODUCT_KEY = 'product';
    public const QUANTITY_KEY = 'quantity';
    public const REFERENCE_KEY = 'reference';
    public const TAX_BEHAVIOR_KEY = 'tax_behavior';
    public const TAX_CODE_KEY = 'tax_code';

    private $amount;
    private $product;
    private $quantity;
    private $reference;
    private $taxBehavior;
    private $taxCode;

    private $lineItemsHelper;
    private $taxHelper;
    private $giftOptionsHelper;

    public function __construct(
        \StripeIntegration\Tax\Helper\LineItems $lineItemsHelper,
        Tax $taxHelper,
        GiftOptions $giftOptionsHelper
    )
    {
        $this->lineItemsHelper = $lineItemsHelper;
        $this->taxHelper = $taxHelper;
        $this->giftOptionsHelper = $giftOptionsHelper;
    }

    public function formData($item, $currency, $parentQty = 1)
    {
        $this->taxCode = $this->lineItemsHelper->getTaxCode($item);
        $this->amount = $this->lineItemsHelper->getAmount($item, $currency);
        $this->reference = $this->lineItemsHelper->getReference($item);
        $this->quantity = (int)$item->getQty() * $parentQty;
        $this->taxBehavior = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $item
     * @param $currency
     * @return $this
     */
    public function formItemGiftOptionsData($item, $currency)
    {
        $this->setGiftOptionsCommonFields();
        $this->quantity = (int)$item->getQty();
        $this->amount = $this->giftOptionsHelper->getItemGiftOptionsAmount($item, $currency) * $this->quantity;
        $this->reference = $this->giftOptionsHelper->getItemGiftOptionsReference($item);

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @param $currency
     * @return $this
     */
    public function formSalesObjectGiftOptionsData($object, $currency)
    {
        $this->setGiftOptionsCommonFields();
        $this->amount = $this->giftOptionsHelper->getSalseObjectGiftOptionsAmount($object, $currency);
        $this->reference = $this->giftOptionsHelper->getSalesObjectGiftOptionsReference($object);

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @param $object
     * @param $currency
     * @return $this
     */
    public function formSalesObjectPrintedCardData($object, $currency)
    {
        $this->setGiftOptionsCommonFields();
        $this->amount = $this->giftOptionsHelper->getSalesObjectPrintedCardAmount($object, $currency);
        $this->reference = $this->giftOptionsHelper->getsalesObjectPrintedCardReference($object);

        return $this;
    }

    /**
     * @codeCoverageIgnore Used in Magento Enterprise installations
     * @return $this
     */
    private function setGiftOptionsCommonFields()
    {
        $this->taxCode = $this->giftOptionsHelper->getGiftOptionsTaxCode();
        $this->quantity = 1;
        $this->reference = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    public function formDataForInvoiceTax($item, $order)
    {
        $this->taxCode = $this->lineItemsHelper->getTaxCodeForInvoiceTax($item);
        $this->amount = $this->lineItemsHelper->getAmount($item, $order->getOrderCurrencyCode());
        $this->reference = $this->lineItemsHelper->getReferenceForInvoiceTax($item, $order);
        $this->quantity = (int)$item->getQty();
        $this->taxBehavior = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    public function formItemGwDataForInvoiceTax($item, $order)
    {
        $this->setGiftOptionsCommonFields();
        $this->quantity = (int)$item->getQty();
        $this->amount = $this->giftOptionsHelper->getItemGiftOptionsAmount($item->getOrderItem(), $order->getOrderCurrencyCode()) * $this->quantity;
        $this->reference = $this->giftOptionsHelper->getItemGwReferenceForInvoiceTax($item, $order);

        return $this;
    }

    public function formAdditionalFeeItemData($item, $currency, $additionalFeeData, $parentQty = 1)
    {
        $this->quantity = (int)$item->getQty() * $parentQty;
        $this->taxCode = $this->lineItemsHelper->getTaxCodeByTaxClassId($additionalFeeData['tax_class_id']);
        $this->amount = $this->lineItemsHelper->getStripeFormattedAmount($additionalFeeData['amount'], $currency);
        $this->reference = $this->lineItemsHelper->getItemAdditionalFeeReference($item, $additionalFeeData['code']);
        $this->taxBehavior = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    public function formAdditionalFeeSalesEntityData($entity, $currency, $additionalFeeData)
    {
        $this->quantity = 1;
        $this->taxCode = $this->lineItemsHelper->getTaxCodeByTaxClassId($additionalFeeData['tax_class_id']);
        $this->amount = $this->lineItemsHelper->getStripeFormattedAmount($additionalFeeData['amount'], $currency);
        $this->reference = $this->lineItemsHelper->getSalesEntityAdditionalFeeReference($entity, $additionalFeeData['code']);
        $this->taxBehavior = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    public function formAdditionalFeeInvoiceItemData($item, $order, $additionalFeeData)
    {
        $this->taxCode = $this->lineItemsHelper->getTaxCodeByTaxClassId($additionalFeeData['tax_class_id']);
        $this->amount = $this->lineItemsHelper->getStripeFormattedAmount($additionalFeeData['amount'], $order->getOrderCurrencyCode());
        $this->reference = $this->lineItemsHelper->getReferenceForInvoiceAdditionalFee($item, $order, $additionalFeeData['code']);
        $this->quantity = (int)$item->getQty();
        $this->taxBehavior = $this->taxHelper->getProductAndPromotionTaxBehavior();

        return $this;
    }

    public function toArray()
    {
        $lineItems = [];
        $lineItems[self::AMOUNT_KEY] = $this->amount;
        if ($this->taxCode) {
            $lineItems[self::TAX_CODE_KEY] = $this->taxCode;
        }
        $lineItems[self::TAX_BEHAVIOR_KEY] = $this->taxBehavior;
        $lineItems[self::QUANTITY_KEY] = $this->quantity;
        $lineItems[self::REFERENCE_KEY] = $this->reference;

        return $lineItems;
    }
}