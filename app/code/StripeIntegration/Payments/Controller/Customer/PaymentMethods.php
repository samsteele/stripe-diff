<?php

namespace StripeIntegration\Payments\Controller\Customer;

use Magento\Customer\Model\Session;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;

class PaymentMethods implements ActionInterface
{
    private $resultPageFactory;
    private $helper;
    private $customerSession;
    private $request;
    private $urlHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Url $urlHelper,
        PageFactory $resultPageFactory,
        Session $session,
        RequestInterface $request
    )
    {
        $this->helper = $helper;
        $this->urlHelper = $urlHelper;
        $this->resultPageFactory = $resultPageFactory;
        $this->customerSession = $session;
        $this->request = $request;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn())
        {
            return $this->urlHelper->getControllerRedirect('customer/account/login');
        }

        $params = $this->request->getParams();

        if (isset($params['redirect_status']))
        {
            // Used when adding redirect-based methods such as Revolut
            return $this->outcome($params['redirect_status'], $params);
        }

        return $this->resultPageFactory->create();
    }

    public function outcome($code, $params)
    {
        if ($code == "succeeded")
        {
            $this->helper->addSuccess(__("The payment method has been successfully added."));
        }

        return $this->urlHelper->getControllerRedirect('stripe/customer/paymentmethods');
    }
}
