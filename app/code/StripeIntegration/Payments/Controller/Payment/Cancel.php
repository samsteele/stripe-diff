<?php

namespace StripeIntegration\Payments\Controller\Payment;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Cancel implements ActionInterface
{
    private $checkoutSession;
    private $resultFactory;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        ResultFactory $resultFactory
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->resultFactory = $resultFactory;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $lastRealOrderId = $this->checkoutSession->getLastRealOrderId();
        $this->checkoutSession->restoreQuote();
        $this->checkoutSession->setLastRealOrderId($lastRealOrderId);
        return $this->redirect('checkout');
    }

    public function redirect($url, array $params = [])
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath($url, $params);

        return $redirect;
    }
}
