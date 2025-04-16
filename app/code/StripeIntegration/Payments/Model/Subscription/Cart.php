<?php

namespace StripeIntegration\Payments\Model\Subscription;

use Magento\Quote\Model\QuoteFactory;
use StripeIntegration\Payments\Exception\Exception;
use Magento\Framework\Stdlib\DateTime;
use StripeIntegration\Payments\Helper\Convert;

// A quote that includes a single subscription item, for the purposes of calculating the shipping amount and shipping tax
class Cart
{
    private $quote = null;

    private $quoteFactory;
    private $initialFeeHelper;
    private $config;
    private $checkoutFlow;
    private $dataObjectFactory;
    private $productRepository;
    private $quoteHelper;
    private $convertHelper;
    public static $isCollectingTotals = false;

    public function __construct(
        QuoteFactory $quoteFactory,
        \Magento\Framework\DataObject\Factory $dataObjectFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\InitialFee $initialFeeHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        Convert $convertHelper
    ) {
        $this->quoteFactory = $quoteFactory;
        $this->initialFeeHelper = $initialFeeHelper;
        $this->config = $config;
        $this->checkoutFlow = $checkoutFlow;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productRepository = $productRepository;
        $this->quoteHelper = $quoteHelper;
        $this->convertHelper = $convertHelper;
    }

    public function fromOrderItem($orderItem, $order)
    {
        $this->quote = null;

        // The order item can only be simple or virtual
        if (!in_array($orderItem->getProductType(), ['simple', 'virtual']))
        {
            throw new Exception("Order item must be of type simple or virtual");
        }

        $this->checkoutFlow->disableZeroInitialPrices();

        // Create new quote
        $this->quote = $this->createNewQuoteFrom($order, $orderItem, true);
        $this->collectTotals();

        $this->checkoutFlow->enableZeroInitialPrices();

        $this->addAdjustmentsToQuote($order);

        return $this;
    }

    public function fromQuoteItem($quoteItem, $quote)
    {
        $this->quote = null;

        // The quote item can only be simple or virtual
        if (!in_array($quoteItem->getProductType(), ['simple', 'virtual']))
        {
            throw new Exception("Quote item must be of type simple or virtual");
        }

        $this->checkoutFlow->disableZeroInitialPrices();

        // Create new quote
        $this->quote = $this->createNewQuoteFrom($quote, $quoteItem);
        $this->collectTotals();

        $this->checkoutFlow->enableZeroInitialPrices();

        $this->addAdjustmentsToQuote($quote);

        return $this;
    }

    private function getShippingItemQtyFromQuoteItem($item, $quote)
    {
        if ($item->getParentItem())
        {
            $parentItem = $item->getParentItem();
            $parentQty = $parentItem->getQty();
            $qty = 0;

            foreach ($quote->getAllItems() as $quoteItem)
            {
                if ($quoteItem->getParentItemId() == $item->getParentItemId())
                {
                    if (!$quoteItem->getIsVirtual())
                    {
                        $childQty = $quoteItem->getQty();
                        $qty += $parentQty * $childQty;
                    }
                }
            }

            return $qty;
        }
        else if ($item->getIsVirtual())
        {
            return 0;
        }
        else
        {
            return $item->getQty();
        }
    }


    private function getShippingItemQtyFromOrderItem($item, $order)
    {
        $parentItem = $item->getParentItem();

        $qty = 0;

        if ($parentItem)
        {
            foreach ($order->getAllItems() as $orderItem)
            {
                if (!in_array($orderItem->getProductType(), ['simple', 'virtual']))
                {
                    // Not a subscription
                    continue;
                }

                if ($orderItem->getParentItemId() == $parentItem->getId() && !$orderItem->getIsVirtual())
                {
                    $childQty = $orderItem->getQtyOrdered();
                    $qty += $childQty;
                }
            }
        }
        else if (!$item->getIsVirtual())
        {
            $qty = $item->getQtyOrdered();
        }

        return $qty;
    }

    private function createNewQuoteFrom($subject, $item, $requireSameShippingMethod = false)
    {
        $isOrder = !!$subject->getIncrementId();

        // Create new quote
        $quote = $this->quoteFactory->create();

        // Set customer data
        $quote->setCustomerId($subject->getCustomerId())
            ->setStoreId($subject->getStoreId())
            ->setQuoteCurrencyCode($isOrder ? $subject->getOrderCurrencyCode() : $subject->getQuoteCurrencyCode());

        // Set the currency
        $quote->setBaseCurrencyCode($isOrder ? $subject->getBaseCurrencyCode() : $subject->getBaseCurrencyCode());
        $quote->setStoreCurrencyCode($isOrder ? $subject->getStoreCurrencyCode() : $subject->getStoreCurrencyCode());
        $quote->setQuoteCurrencyCode($isOrder ? $subject->getOrderCurrencyCode() : $subject->getQuoteCurrencyCode());

        // Set billing and shipping addresses
        $billingAddress = $subject->getBillingAddress()->getData();
        $quote->getBillingAddress()->addData($billingAddress);

        // Add a replica of the order item to the quote
        $addedItem = $this->addItem($quote, $item, $isOrder);
        if (is_string($addedItem))
        {
            throw new Exception($addedItem);
        }

        $isVirtual = $quote->getIsVirtual();
        if (!$isVirtual)
        {
            $shippingAddress = $subject->getShippingAddress()->getData();
            $quote->getShippingAddress()->addData($shippingAddress);

            // Request shipping rates
            if ($this->_areProductChildrenShippedTogether($addedItem))
            {
                $qty = $addedItem->getQty();
            }
            else if ($isOrder)
            {
                $qty = $this->getShippingItemQtyFromOrderItem($item, $subject);
            }
            else
            {
                $qty = $this->getShippingItemQtyFromQuoteItem($item, $subject);
            }
            $quote->getShippingAddress()->setItemQty($qty);

            $isSameShippingMethodAvailable = false;
            $quote->collectTotals();
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $quote->getShippingAddress()->collectShippingRates();

            // Select the same rate as on the order
            $shippingRates = $quote->getShippingAddress()->getGroupedAllShippingRates();
            $shippingMethod = $subject->getShippingMethod();
            if (!empty($shippingRates) && !empty($shippingMethod[1]))
            {
                $carrierCode = explode("_", $shippingMethod)[0];
                $carrierMethod = explode("_", $shippingMethod)[1];
                foreach ($shippingRates as $rateCode => $rates)
                {
                    foreach ($rates as $rate)
                    {
                        if ($rate->getCarrier() == $carrierCode && $rate->getMethod() == $carrierMethod)
                        {
                            $isSameShippingMethodAvailable = true;
                            $quote->getShippingAddress()->setShippingMethod($shippingMethod);
                            break 2;
                        }
                    }
                }
            }

            if ($requireSameShippingMethod && !$isSameShippingMethodAvailable)
            {
                throw new Exception(__("The selected shipping method is not available for this subscription"));
            }
        }

        // Apply discounts and coupons
        if ($subject->getCouponCode()) {
            $quote->setCouponCode($subject->getCouponCode());
        }

        return $quote;
    }

    public function collectTotals()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this::$isCollectingTotals)
        {
            throw new Exception("Infinite loop detected");
        }
        $this::$isCollectingTotals = true;
        $this->quoteHelper->reCollectTotals($this->quote); // Clears cached items from the old address
        $this::$isCollectingTotals = false;

        return $this;
    }

    public function addItem($quote, $item, $isOrderItem)
    {
        if ($item->getParentItem()) // Bundle and configurable subscriptions
        {
            $item = $item->getParentItem();
        }

        if ($isOrderItem)
        {
            $productOptions = $item->getProductOptions();
        }
        else
        {
            $productOptions = $item->getProduct()->getTypeInstance(true)->getOrderOptions($item->getProduct());
        }

        $product = $this->productRepository->getById($item->getProductId());

        // The following code block is only needed by the test suite, it works correctly in manual testing
        {
            $productType = $product->getTypeId();
            if (isset($productOptions['info_buyRequest']['qty']) && in_array($productType, ['simple', 'virtual']))
            {
                $qty = $item->getQty() ?? $item->getQtyOrdered() ?? $item->getQtyToAdd();
                if (is_numeric($qty) && $qty > 0)
                {
                    $productOptions['info_buyRequest']['qty'] = $qty;
                }
            }
        }

        $buyRequest = $productOptions['info_buyRequest'] ?? [];
        $buyRequestDataObject = $this->dataObjectFactory->create($buyRequest);
        $buyRequestDataObject = $this->convertDateOptionsToString($product, $buyRequestDataObject);

        $quoteItem = $quote->addProduct($product, $buyRequestDataObject);

        if (is_string($quoteItem))
            throw new Exception($quoteItem);

        if (!$this->checkoutFlow->isNewOrderBeingPlaced)
        {
            if ($this->config->priceIncludesTax())
            {
                $price = $item->getStripeOriginalSubscriptionPrice() ?? $item->getOriginalCustomPrice() ?? $item->getPriceInclTax();
            }
            else
            {
                $price = $item->getStripeOriginalSubscriptionPrice() ?? $item->getOriginalCustomPrice() ?? $item->getConvertedPrice() ?? $item->getPrice();
            }

            $quoteItem->setCustomPrice($price);
            $quoteItem->setOriginalCustomPrice($price);
            $quoteItem->getProduct()->setIsSuperMode(true);
        }

        return $quoteItem;
    }

    /**
     * This method only exists due to a Magento bug where array dates fail validation. It only happens when
     * creating the cart programmatically, i.e. the same buyRequest works fine when adding the product to the cart.
     * Tested in Magento 2.4.6 but may also affect older and newer versions.
     */
    private function convertDateOptionsToString($product, $buyRequestDataObject)
    {
        $data = $buyRequestDataObject->getData();
        $options = $product->getOptions();
        foreach ($options as $option)
        {
            if ($option->getType() == 'date')
            {
                $optionId = $option->getId();
                $optionValue = $buyRequestDataObject->getData('options/' . $optionId);
                if ($optionValue && is_array($optionValue))
                {
                    if (empty($optionValue['year']) || empty($optionValue['month']) || empty($optionValue['day']))
                    {
                        // Remove empty date options
                        unset($data['options'][$optionId]);
                        $buyRequestDataObject->setData($data);
                    }
                    else
                    {
                        $date = new \DateTime($optionValue['year'] . '-' . $optionValue['month'] . '-' . $optionValue['day']);
                        $data['options'][$optionId] = $date->format(DateTime::DATETIME_PHP_FORMAT);
                        $buyRequestDataObject->setData($data);
                    }
                }
            }
        }

        return $buyRequestDataObject;
    }

    public function getShippingAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        if ($this->config->shippingIncludesTax())
        {
            return $this->quote->getShippingAddress()->getShippingInclTax();
        }
        else
        {
            return $this->quote->getShippingAddress()->getShippingAmount();
        }
    }

    public function getShippingDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getShippingDiscountAmount();
    }

    public function getShippingDiscountTaxCompensationAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getShippingDiscountTaxCompensationAmount();
    }

    public function getBaseShippingDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getBaseShippingDiscountAmount();
    }

    public function getBaseShippingDiscountTaxCompensationAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getBaseShippingDiscountTaxCompensationAmount();
    }

    private function getShippingExcludingTaxAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getShippingAmount();
    }

    public function getShippingTaxAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getShippingTaxAmount();
    }

    public function getBaseShippingAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        if ($this->config->shippingIncludesTax())
        {
            return $this->quote->getShippingAddress()->getBaseShippingInclTax();
        }
        else
        {
            return $this->quote->getShippingAddress()->getBaseShippingAmount();
        }
    }

    public function getBaseShippingTaxAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->quote->getIsVirtual())
        {
            return 0;
        }

        return $this->quote->getShippingAddress()->getBaseShippingTaxAmount();
    }

    public function getShippingTaxPercent()
    {
        $shippingAmount = $this->getShippingExcludingTaxAmount();
        $shippingTaxAmount = $this->getShippingTaxAmount();

        if ($shippingAmount == 0 || $shippingTaxAmount == 0)
            return 0;

        return round($shippingTaxAmount / $shippingAmount, 4);
    }

    public function getBaseGrandTotal()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getBaseGrandTotal();
    }

    public function getGrandTotal()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getGrandTotal();
    }

    public function getSubtotal()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->config->priceIncludesTax())
        {
            return $this->quote->getShippingAddress()->getSubtotalInclTax();
        }
        else
        {
            return $this->quote->getSubtotal();
        }
    }

    public function getBaseSubtotal()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        if ($this->config->priceIncludesTax())
        {
            return $this->quote->getShippingAddress()->getBaseSubtotalInclTax();
        }
        else
        {
            return $this->quote->getBaseSubtotal();
        }
    }

    public function getBaseDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->getBaseSubscriptionDiscountAmount() + $this->getBaseShippingDiscountAmount();
    }

    public function getDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->getSubscriptionDiscountAmount() + $this->getShippingDiscountAmount();
    }

    public function getSubscriptionPrice()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        foreach ($this->quote->getAllVisibleItems() as $item)
        {
            if ($this->config->priceIncludesTax())
            {
                return $item->getPriceInclTax();
            }
            else
            {
                return $item->getConvertedPrice();
            }
        }

        throw new Exception("Subscription item not found in cart");
    }

    public function getBaseSubscriptionPrice()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        foreach ($this->quote->getAllVisibleItems() as $item)
        {
            if ($this->config->priceIncludesTax())
            {
                return $item->getBasePriceInclTax();
            }
            else
            {
                return $item->getBasePrice();
            }
        }

        throw new Exception("Subscription item not found in cart");
    }

    public function getSubscriptionDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        $discountAmount = 0;

        foreach ($this->quote->getAllItems() as $item)
        {
            $discountAmount += $item->getDiscountAmount();
        }

        return abs($discountAmount);
    }

    public function getBaseSubscriptionDiscountAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        $baseDiscountAmount = 0;

        foreach ($this->quote->getAllItems() as $item)
        {
            $baseDiscountAmount += $item->getBaseDiscountAmount();
        }

        return abs($baseDiscountAmount);
    }

    public function getBaseTaxAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        foreach ($this->quote->getAllVisibleItems() as $item)
        {
            return round(floatval($item->getBaseTaxAmount()), 4);
        }

        throw new Exception("Subscription item not found in cart");
    }

    public function getTaxAmount()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        foreach ($this->quote->getAllVisibleItems() as $item)
        {
            return round(floatval($item->getTaxAmount()), 4);
        }

        throw new Exception("Subscription item not found in cart");
    }

    public function getTotalInitialFee()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->initialFeeHelper->getTotalInitialFeeFor($this->quote->getAllItems(), $this->quote, $this->quote->getBaseToQuoteRate());
    }

    public function getTotalInitialFeeInclTax()
    {
        $initialFee = $this->getTotalInitialFee();

        if (!$this->config->priceIncludesTax()) {
            $initialFee += $this->quote->getInitialFeeTax();
        }

        return $initialFee;
    }

    public function getBaseTotalInitialFee()
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->initialFeeHelper->getTotalInitialFeeFor($this->quote->getAllItems(), $this->quote);
    }

    public function getBaseTotalInitialFeeInclTax()
    {
        $baseInitialFee = $this->getBaseTotalInitialFee();

        if (!$this->config->priceIncludesTax()) {
            $baseInitialFee += $this->quote->getBaseInitialFeeTax();
        }

        return $baseInitialFee;
    }

    private function _areProductChildrenShippedTogether($item)
    {
        if (!$item || !$item->getProduct())
        {
            throw new Exception("Subscription item not found in cart");
        }

        if (!is_numeric($item->getProduct()->getShipmentType()))
        {
            return false;
        }

        $shipmentType = (int)$item->getProduct()->getShipmentType();

        return $shipmentType === \Magento\Bundle\Model\Product\Type::SHIPMENT_TOGETHER;
    }

    public function setOriginalSubscriptionPrice($subject)
    {
        if (!$this->quote)
        {
            throw new Exception("Subscription cart not initialized");
        }

        foreach ($this->quote->getAllItems() as $quoteItem)
        {
            foreach ($subject->getAllItems() as $subjectItem)
            {
                if ($quoteItem->getProductId() == $subjectItem->getProductId())
                {
                    $subjectItem->setStripeOriginalSubscriptionPrice($this->getSubscriptionPrice());
                    $subjectItem->setStripeBaseOriginalSubscriptionPrice($this->getBaseSubscriptionPrice());
                }
            }
        }
    }


    /**
     * Takes the gift card amount from the initial subject (quote or order) and applies it to the newly created quote.
     * After this method is run, you can access the values with the getters. The getters can also be intersected using
     * plugins so that if there are custom reward points or other adjustments.
     *
     * @param $subject
     * @return void
     */
    private function addAdjustmentsToQuote($subject)
    {
        if (!!$subject->getIncrementId()) {
            $this->quote->setGiftCardsAmount($subject->getGiftCardsAmount());
            $this->quote->setCustomerBalanceAmount($subject->getCustomerBalanceAmount());
            $this->quote->setRewardCurrencyAmount($subject->getRewardCurrencyAmount());

            $this->quote->setBaseGiftCardsAmount($subject->getBaseGiftCardsAmount());
            $this->quote->setBaseCustomerBalanceAmount($subject->getBaseCustomerBalanceAmount());
            $this->quote->setBaseRewardCurrencyAmount($subject->getBaseRewardCurrencyAmount());
        } else {
            $this->quote->setGiftCardsAmount($subject->getGiftCardsAmountUsed());
            $this->quote->setCustomerBalanceAmount($subject->getCustomerBalanceAmountUsed());
            $this->quote->setRewardCurrencyAmount($subject->getRewardCurrencyAmount());

            $this->quote->setBaseGiftCardsAmount($subject->getBaseGiftCardsAmountUsed());
            $this->quote->setBaseCustomerBalanceAmount($subject->getBaseCustomerBalAmountUsed());
            $this->quote->setBaseRewardCurrencyAmount($subject->getBaseRewardCurrencyAmount());
        }
    }

    public function getGiftCardsAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getGiftCardsAmount();
    }

    public function getBaseGiftCardsAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getBaseGiftCardsAmount();
    }

    public function getStoreCreditAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getCustomerBalanceAmount();
    }

    public function getBaseStoreCreditAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getBaseCustomerBalanceAmount();
    }

    public function getRewardPointsAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getRewardCurrencyAmount();
    }

    public function getBaseRewardPointsAmount()
    {
        if (!$this->quote) {
            throw new Exception("Subscription cart not initialized");
        }

        return $this->quote->getBaseRewardCurrencyAmount();
    }

    /**
     * Determining the total adjustment for the order:
     * 1. Get the total adjustments for the order (gift cards + store credit + reward points)
     * 2. Get the grand total of the order without adjustment
     * 3. Determine the one time payment (subtract the subscription order price from the order grand total without the
     * adjustment). This should be the initial fees of the order (if they exist) plus the other products in the order
     * 4. If total adjustments is less or equal to the one time payment, we don't need the balance adjustment, as
     * it was already handled by Magento out of the box
     * 5. If total adjustments is greater than the one time payment and the subscription is non-billable at the time of
     * buying (trial subscriptions or subscriptions that start on a certain date in the future), the adjustment is set to 0
     * as there is nothing to deduct from the subscription total. In this case Magento only deducts the one time payment for the
     * order and if there is anything left from a gift card, that value is kept on the gift card for future use.
     * 6. If the subscription is billable, the adjustment is set as the total adjustments subtracted from the one time
     * payment (it will be a negative value) which will be transformed into a Stripe value and set to be used after.
     *
     * @param $subject
     * @param $subscriptionOrderTotal
     * @param $useBaseValues
     * @return int
     * @throws Exception
     */
    public function getCustomerBalanceAdjustment($subject, $subscriptionOrderTotal, $useBaseValues = true)
    {
        if ($useBaseValues) {
            $totalAdjustments = $this->getBaseGiftCardsAmount() +
                $this->getBaseStoreCreditAmount() +
                $this->getBaseRewardPointsAmount();
            // In order to get the one time payment for the order, we need to have the grand total of the order
            // before the deductions have been applied
            $orderGrandTotalBeforeAdjustment = $subject->getBaseGrandTotal() + $totalAdjustments;

            $currency = $this->quote->getBaseCurrencyCode();
        } else {
            $totalAdjustments = $this->getGiftCardsAmount() +
                $this->getStoreCreditAmount() +
                $this->getRewardPointsAmount();
            // In order to get the one time payment for the order, we need to have the grand total of the order
            // before the deductions have been applied
            $orderGrandTotalBeforeAdjustment = $subject->getGrandTotal() + $totalAdjustments;

            $currency = $this->quote->getQuoteCurrencyCode();
        }
        $oneTimePayment = $orderGrandTotalBeforeAdjustment - $subscriptionOrderTotal;

        if ((float)$totalAdjustments <= (float)$oneTimePayment) {
            return 0;
        } else {
            $nonBillableItems = $this->quoteHelper->getNonBillableSubscriptionItems($this->quote->getAllItems());
            if (!empty($nonBillableItems)) {
                return 0;
            }

            $adjustment = $oneTimePayment - $totalAdjustments;

            return $this->convertHelper->magentoAmountToStripeAmount($adjustment, $currency);
        }
    }
}
