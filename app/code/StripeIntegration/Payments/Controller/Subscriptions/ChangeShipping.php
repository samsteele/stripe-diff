<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Controller\Subscriptions;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class ChangeShipping implements CsrfAwareActionInterface
{
    private $formKeyValidator;
    private $helper;
    private $stripeCustomer;
    private $stripeSubscriptionFactory;
    private $customerSession;
    private $request;
    private $urlHelper;
    private $subscriptionsHelper;
    private $quoteHelper;
    private $subscriptionProductFactory;
    private $productHelper;
    private $dataHelper;
    private $orderHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
    )
    {
        $this->helper = $helper;
        $this->urlHelper = $urlHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->quoteHelper = $quoteHelper;
        $this->productHelper = $productHelper;
        $this->dataHelper = $dataHelper;
        $this->orderHelper = $orderHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->customerSession = $session;
        $this->request = $request;
        $this->formKeyValidator = $formKeyValidator;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn())
        {
            return $this->urlHelper->getControllerRedirect('customer/account/login');
        }

        $subscriptionId = $this->request->getParam('subscription_id');
        if (!$subscriptionId)
        {
            $this->helper->addError(__('Invalid subscription ID.'));
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }

        try
        {
            if (!$this->stripeCustomer->getStripeId())
            {
                $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
                $this->helper->logError("Could not load customer account for subscription with ID $subscriptionId!");
            }
            else if (!$this->stripeCustomer->ownsSubscriptionId($subscriptionId))
            {
                $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
                $this->helper->logError("Customer does not own subscription with ID $subscriptionId!");
            }
            else
            {
                return $this->changeShipping($subscriptionId);
            }
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not change shipping for subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
            $this->helper->logError("Could not change shipping for subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
    }

    protected function changeShipping($subscriptionId)
    {
        $subscription = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($subscriptionId)->getStripeObject();
        $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
        if (!$orderIncrementId)
            throw new LocalizedException(__("This subscription is not associated with an order."));

        $order = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);

        if (!$order)
            throw new LocalizedException(__("Could not load order for this subscription."));

        $this->quoteHelper->deactivateCurrentQuote();
        $quote = $this->quoteHelper->createFreshQuote();

        $productIds = $this->subscriptionsHelper->getSubscriptionProductIDs($subscription);
        $items = $order->getItemsCollection();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);

            if ($subscriptionProductModel->isSubscriptionProduct() &&
                $subscriptionProductModel->getIsSalable() &&
                in_array($subscriptionProductModel->getProductId(), $productIds)
                )
            {
                $product = $subscriptionProductModel->getProduct();

                if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
                {
                    $item = $item->getParentItem();
                    try
                    {
                        $product = $this->productHelper->getProduct($item->getProductId());

                        if (!$product->getIsSalable())
                            continue;
                    }
                    catch (\Exception $e)
                    {
                        continue;
                    }
                }

                $request = $this->dataHelper->getBuyRequest($item);
                $result = $quote->addProduct($product, $request);
                if (is_string($result))
                    throw new LocalizedException(__($result));
            }
        }

        if (!$quote->hasItems())
            throw new LocalizedException(__("Sorry, this subscription product is currently unavailable."));

        $this->subscriptionsHelper->setSubscriptionUpdateDetails($subscription, $productIds);

        $quote->getShippingAddress()->setCollectShippingRates(false);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteHelper->saveQuote($quote);
        try
        {
            if (!$order->getIsVirtual() && !$quote->getIsVirtual() && $order->getShippingMethod())
            {
                $shippingMethod = $order->getShippingMethod();
                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->addData($order->getShippingAddress()->getData());
                $shippingAddress->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($order->getShippingMethod())
                        ->save();
            }
        }
        catch (\Exception $e)
        {
            // The shipping address or method may not be available, ignore in this case
        }

        return $this->urlHelper->getControllerRedirect('checkout');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): bool
    {
        $formKey = $request->getParam('form_key');
        $isValid = $this->formKeyValidator->validate($request);
        return $formKey && $isValid;
    }
}
