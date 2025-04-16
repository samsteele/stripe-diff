<?php

namespace StripeIntegration\Payments\Controller\Customer;

use Magento\Framework\App\ActionInterface;

class Subscriptions implements ActionInterface
{
    private $resultPageFactory;
    private $helper;
    private $subscriptionsHelper;
    private $order;
    private $customerSession;
    private $request;
    private $urlHelper;

    public function __construct(
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Customer\Model\Session $session,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        \Magento\Sales\Model\Order $order,
        \Magento\Framework\App\RequestInterface $request
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->urlHelper = $urlHelper;
        $this->order = $order;
        $this->customerSession = $session;
        $this->request = $request;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn())
            return $this->urlHelper->getControllerRedirect('customer/account/login');

        $params = $this->request->getParams();

        if (isset($params['viewOrder']))
            return $this->viewOrder($params['viewOrder']);
        else if (isset($params['updateSuccess']))
            return $this->onUpdateSuccess();
        else if (isset($params['updateCancel']))
            return $this->onUpdateCancel();
        else if (!empty($params))
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');

        return $this->resultPageFactory->create();
    }

    protected function onUpdateCancel()
    {
        $this->subscriptionsHelper->cancelSubscriptionUpdate();
        return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
    }

    protected function onUpdateSuccess()
    {
        $this->helper->addSuccess(__("The subscription has been updated successfully."));
        return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
    }

    protected function  viewOrder($incrementOrderId)
    {
        $this->order->loadByIncrementId($incrementOrderId);

        if ($this->order->getId())
            return $this->urlHelper->getControllerRedirect('sales/order/view/', ['order_id' => $this->order->getId()]);
        else
        {
            $this->helper->addError("Order #$incrementOrderId could not be found!");
            return $this->urlHelper->getControllerRedirect('stripe/customer/subscriptions');
        }
    }
}
