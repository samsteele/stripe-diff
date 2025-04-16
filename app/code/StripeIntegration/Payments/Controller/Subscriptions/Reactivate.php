<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Controller\Subscriptions;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use StripeIntegration\Payments\Exception\GenericException;

class Reactivate implements CsrfAwareActionInterface
{
    private $formKeyValidator;
    private $helper;
    private $stripeCustomer;
    private $subscriptionFactory;
    private $customerSession;
    private $request;
    private $urlHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
    )
    {
        $this->helper = $helper;
        $this->urlHelper = $urlHelper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->subscriptionFactory = $subscriptionFactory;
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
                $this->helper->addError(__("Sorry, the subscription could not be reactivated. Please contact us for assistance."));
                $this->helper->logError("Could not load customer account for subscription with ID $subscriptionId!");
            }
            else if (!$this->stripeCustomer->ownsSubscriptionId($subscriptionId))
            {
                $this->helper->addError(__("Sorry, the subscription could not be reactivated. Please contact us for assistance."));
                $this->helper->logError("Customer does not own subscription with ID $subscriptionId!");
            }
            else
            {
                $subscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
                $redirectUrl = $subscriptionModel->reactivate();
                if (is_string($redirectUrl))
                {
                    return $this->urlHelper->getControllerRedirect($redirectUrl);
                }
                else
                {
                    $this->helper->addSuccess(__("The subscription has been reactivated."));
                }
            }
        }
        catch (GenericException | LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not reactivate the subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, there was a problem reactivating the subscription"));
            $this->helper->logError("Unable to reactivate the subscription $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
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
