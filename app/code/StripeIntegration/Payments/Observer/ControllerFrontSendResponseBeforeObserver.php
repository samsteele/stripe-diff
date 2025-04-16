<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class ControllerFrontSendResponseBeforeObserver implements ObserverInterface
{
    private $errorHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Error $errorHelper
    ) {
        $this->errorHelper = $errorHelper;
    }

    public function execute(Observer $observer)
    {
        $response = $observer->getEvent()->getResponse();

        if ($this->errorHelper->getDisplay()) {
            $response->clearHeader('errorRedirectAction');
        }
    }
}