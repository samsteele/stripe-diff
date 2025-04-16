<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\Exception;
use Magento\Framework\Exception\NoSuchEntityException;

class Quote
{
    // $quoteId is set right before the order is placed from inside Plugin/Sales/Model/Service/OrderService,
    // as the GraphQL flow may place an order without a loaded quote. Used for loading the quote later.
    public $quoteId = null;

    private $quotesCache = [];

    private $backendSessionQuote;
    private $checkoutSession;
    private $quoteRepository;
    private $areaCodeHelper;
    private $productHelper;
    private $subscriptionProductFactory;
    private $quoteFactory;
    private $logHelper;

    public function __construct(
        \Magento\Backend\Model\Session\Quote $backendSessionQuote,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\Logger $logHelper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory
    )
    {
        $this->backendSessionQuote = $backendSessionQuote;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->productHelper = $productHelper;
        $this->logHelper = $logHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->quoteFactory = $quoteFactory;
    }

    // This method is not inside the subscriptions helper to avoid circular dependencies between Model/Config and other classes.
    public function hasSubscriptions(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $quoteId = $quote->getId();

        if ($quoteId)
        {
            if (isset($this->quotesCache[$quoteId]))
            {
                if ($this->quotesCache[$quoteId]->getHasSubscriptions() !== null)
                {
                    return $this->quotesCache[$quoteId]->getHasSubscriptions();
                }
            }
            else
            {
                $this->quotesCache[$quoteId] = $quote;
            }
        }

        $items = $quote->getAllItems();
        $hasSubscriptions = $this->hasSubscriptionsIn($items);
        $quote->setHasSubscriptions($hasSubscriptions);

        return $hasSubscriptions;
    }

    public function hasSubscriptionsIn($quoteItems)
    {
        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                return true;
            }
        }

        return false;
    }

    public function getQuote($quoteId = null): \Magento\Quote\Api\Data\CartInterface
    {
        // Admin area new order page
        if ($this->areaCodeHelper->isAdmin())
            return $this->getBackendSessionQuote();

        // Front end checkout
        $quote = $this->getSessionQuote();

        // API Request
        if (empty($quote) || !is_numeric($quote->getGrandTotal()))
        {
            try
            {
                if ($quoteId)
                    $quote = $this->quoteRepository->get($quoteId);
                else if ($this->quoteId) {
                    $quote = $this->quoteRepository->get($this->quoteId);
                }
            }
            catch (\Exception $e)
            {

            }
        }

        return $quote;
    }

    public function getQuoteDescription($quote)
    {
        if ($quote->getCustomerIsGuest())
            $customerName = $quote->getBillingAddress()->getName();
        else
            $customerName = $quote->getCustomerName();

        if (!empty($customerName))
            $description = __("Cart %1 by %2", $quote->getId(), $customerName);
        else
            $description = __("Cart %1", $quote->getId());

        return $description;
    }

    public function loadQuoteById($quoteId)
    {
        if (!is_numeric($quoteId))
            return null;

        if (!empty($this->quotesCache[$quoteId]))
            return $this->quotesCache[$quoteId];

        $this->quotesCache[$quoteId] = $this->quoteFactory->create()->load($quoteId);

        return $this->quotesCache[$quoteId];
    }

    private function getBackendSessionQuote()
    {
        return $this->backendSessionQuote->getQuote();
    }

    private function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function saveQuote($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $this->quoteRepository->save($quote);

        return $quote;
    }

    /**
     * Add product to shopping cart (quote)
     */
    public function addProduct($productId, ?array $requestInfo = null)
    {
        if (!$productId)
            throw new \Magento\Framework\Exception\LocalizedException(__('The product does not exist.'));

        try
        {
            $request = new \Magento\Framework\DataObject($requestInfo);
            $product = $this->productHelper->getProduct($productId);
            $result = $this->getQuote()->addProduct($product, $request);
        }
        catch (NoSuchEntityException $e)
        {
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__("The product wasn't found. Verify the product and try again."));
        }
        catch (\Exception $e)
        {
            $this->logHelper->logError($e->getMessage(), $e->getTraceAsString());
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__("We can't add this item to your shopping cart right now."));
        }

        if (is_string($result))
        {
            $this->checkoutSession->setUseNotice(false);
            throw new \Magento\Framework\Exception\LocalizedException(__($result));
        }

        $this->checkoutSession->setLastAddedProductId($productId);
        return $result;
    }

    public function removeItem($itemId)
    {
        $item = $this->getQuote()->removeItem($itemId);

        if ($item->getHasError()) {
            throw new \Magento\Framework\Exception\LocalizedException(__($item->getMessage()));
        }

        return $this;
    }

    public function isProductInCart($productId)
    {
        $quote = $this->getQuote();
        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            if ($item->getProductId() == $productId)
                return true;
        }

        return false;
    }

    /**
     * Adding products to cart by ids
     */
    public function addProductsByIds(array $productIds)
    {
        foreach ($productIds as $productId) {
            $this->addProduct($productId);
        }

        return $this;
    }

    public function isMultiShipping($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        return $quote->getIsMultiShipping();
    }

    public function clearCache()
    {
        $this->quotesCache = [];
    }

    public function reloadQuote($quote)
    {
        $quote = $this->quoteRepository->get($quote->getId());
        $this->quotesCache[$quote->getId()] = $quote;
        return $quote;
    }

    public function hasSubscriptionsWithStartDate($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() &&
                $subscriptionProductModel->hasStartDate()
            )
            {
                return true;
            }
        }

        return false;
    }

    public function hasFutureSubscriptionsIn($quoteItems)
    {
        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() &&
                ($subscriptionProductModel->hasTrialPeriod() || $subscriptionProductModel->hasStartDate())
            ) {
                return true;
            }
        }

        return false;
    }

    public function hasOnlyTrialSubscriptions($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        if (!$quote || !$quote->getId())
            return false;

        $items = $quote->getAllItems();
        $trialSubscriptions = 0;

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if (!$subscriptionProductModel->isSubscriptionProduct())
                return false;

            if (!$subscriptionProductModel->hasTrialPeriod())
                return false;

            $trialSubscriptions++;
        }

        return $trialSubscriptions > 0;
    }

    public function getNonBillableSubscriptionItems($items)
    {
        $nonBillableItems = [];

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);

            if (!$subscriptionProductModel->isSubscriptionProduct())
                continue;

            if (!$subscriptionProductModel->hasZeroInitialOrderPrice())
                continue;

            if ($item->getParentItem()) // Bundle and configurable subscriptions
            {
                $item = $item->getParentItem();
                $nonBillableItems[] = $item;

                // Get all child products
                foreach ($items as $item2)
                {
                    if ($item2->getParentItemId() == $item->getId())
                        $nonBillableItems[] = $item2;
                }
            }
            else
            {
                $nonBillableItems[] = $item;
            }
        }

        return $nonBillableItems;
    }

    // Checks if the quote has a 100% discount rule, and that the discount will eventually expire
    public function hasFullyDiscountedSubscriptions($quote)
    {
        $items = $quote->getAllItems();

        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);

            if (!$subscriptionProductModel->isSubscriptionProduct())
            {
                continue;
            }

            if ($item->getParentItem())
            {
                $item = $item->getParentItem();
            }

            if ($item->getBasePrice() > 0 && $item->getBasePrice() <= $item->getBaseDiscountAmount())
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the total on the quote is fully being redeemed through a combination of gift cards, store credit and
     * reward points
     *
     * @param $quote
     * @return bool
     * @throws \StripeIntegration\Payments\Exception\InvalidSubscriptionProduct
     */
    public function isZeroTotalSubscriptionFromAdjustment($quote)
    {
        $totalAdjustment = floatval($quote->getRewardCurrencyAmount()) + floatval($quote->getGiftCardsAmountUsed()) + floatval($quote->getCustomerBalanceAmountUsed());

        if ($this->hasSubscriptions($quote)) {
            // The way the adjustments will be used is we assume that first they will be applied to other types of
            // products and then to the subscriptions, so if the grand total is 0 and the adjustment is greater than 0,
            // then a deduction has taken place. If there is a non-billable subscription in the cart, the grand total
            // is still set to what it is before the subscription recurring price is taken out.
            if (($quote->getGrandTotal() == 0) && ($totalAdjustment > 0)) {
                return true;
            }
        }

        return false;
    }

    public function reCollectTotals($quote)
    {
        $shippingMethod = null;
        $quote->getBillingAddress()->unsetData('cached_items_all');
        $quote->getBillingAddress()->unsetData('cached_items_nominal');
        $quote->getBillingAddress()->unsetData('cached_items_nonnominal');
        if (!$quote->getIsVirtual())
        {
            $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
            $quote->getShippingAddress()->unsetData('cached_items_all');
            $quote->getShippingAddress()->unsetData('cached_items_nominal');
            $quote->getShippingAddress()->unsetData('cached_items_nonnominal');
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }
        foreach ($quote->getAllItems() as $item)
        {
            $item->setTaxCalculationPrice(null);
            $item->setBaseTaxCalculationPrice(null);
        }
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        if ($shippingMethod)
        {
            // We restore it because when the shipping rates are collected, the shipping method is reset
            $quote->getShippingAddress()->setShippingMethod($shippingMethod);
        }
    }

    public function removeSubscriptions(\Magento\Quote\Api\Data\CartInterface $quote)
    {
        $removed = false;
        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                if ($item->getParentItem())
                {
                    $quote->removeItem($item->getParentItem()->getId());
                    $removed = true;
                }
                else
                {
                    $quote->removeItem($item->getId());
                    $removed = true;
                }
            }
        }

        return $removed;
    }

    public function getQuoteItemFromProductId($productId)
    {
        $quote = $this->getQuote();
        $quoteItems = $quote->getAllItems();

        foreach ($quoteItems as $quoteItem)
        {
            if ($quoteItem->getProductId() == $productId)
            {
                return $quoteItem;
            }
        }

        throw new Exception("Quote item not found for order item");
    }

    public function deactivateQuoteById($quoteId)
    {
        if (empty($quoteId))
            return;

        try
        {
            $quote = $this->quoteRepository->get($quoteId);
            $this->deactivateQuote($quote);
        }
        catch (\Exception $e)
        {

        }
    }

    public function deactivateQuote($quote)
    {
        if (empty($quote) || !$quote->getId())
            return;

        try
        {
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
        }
        catch (\Exception $e)
        {

        }
    }

    public function deactivateCurrentQuote()
    {
        $quote = $this->getQuote();
        if ($quote && $quote->getId())
        {
            $this->deactivateQuote($quote);
        }
    }

    public function createFreshQuote()
    {
        // Create a new empty quote
        $quote = $this->quoteFactory->create();

        // Set store ID and other essential properties
        $quote->setStoreId($this->checkoutSession->getStoreId());

        // If customer is logged in, associate the quote with them
        if ($this->checkoutSession->getCustomerId()) {
            $quote->setCustomerId($this->checkoutSession->getCustomerId());
            $quote->setCustomerEmail($this->checkoutSession->getCustomerEmail());
            $quote->setCustomerIsGuest(0);
        } else {
            $quote->setCustomerIsGuest(1);
        }

        // Save the quote
        $this->quoteRepository->save($quote);

        // Set as active quote in session
        $this->checkoutSession->setQuoteId($quote->getId());
        $this->checkoutSession->replaceQuote($quote);

        // Add to quotes cache
        $this->quotesCache[$quote->getId()] = $quote;

        return $quote;
    }
}
