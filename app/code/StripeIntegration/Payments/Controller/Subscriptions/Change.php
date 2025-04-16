<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Controller\Subscriptions;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Change implements CsrfAwareActionInterface
{
    private $formKeyValidator;
    private $helper;
    private $stripeCustomer;
    private $stripeSubscriptionFactory;
    private $customerSession;
    private $request;
    private $urlHelper;
    private $quoteHelper;
    private $subscriptionsHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
    )
    {
        $this->helper = $helper;
        $this->urlHelper = $urlHelper;
        $this->quoteHelper = $quoteHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
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
                $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($subscriptionId);
                $order = $stripeSubscriptionModel->getOrder();
                if (!$order || !$order->getId())
                    throw new LocalizedException(__("Could not load order for this subscription."));

                $stripeSubscriptionModel->addToCart();

                $this->subscriptionsHelper->setSubscriptionUpdateDetails($stripeSubscriptionModel->getStripeObject(), [ $stripeSubscriptionModel->getProductId() ]);
                $product = $stripeSubscriptionModel->getOrderItem()->getProduct();
                $quoteItem = $this->quoteHelper->getQuote()->getItemByProduct($product);

                if (!$quoteItem) {
                    throw new LocalizedException(__("Could not load the original order items."));
                }
                $quoteItemId = $quoteItem->getId();

                return $this->urlHelper->getControllerRedirect('checkout/cart/configure', ['id' => $quoteItemId, 'product_id' => $product->getId()]);
            }
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not change subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be changed. Please contact us for assistance."));
            $this->helper->logError("Could not change subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
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
