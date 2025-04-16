<?php

namespace StripeIntegration\Tax\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use StripeIntegration\Tax\Helper\Exception;
use StripeIntegration\Tax\Model\StripeTax;
use StripeIntegration\Tax\Model\TaxFlow;

class OrderProceed implements ObserverInterface
{
    private $taxFlow;
    private $stripeTax;
    private $exceptionHelper;

    public function __construct(
        TaxFlow $taxFlow,
        StripeTax $stripeTax,
        Exception $exceptionHelper
    ) {
        $this->taxFlow = $taxFlow;
        $this->stripeTax = $stripeTax;
        $this->exceptionHelper = $exceptionHelper;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        // Check only if Tax enabled and order is new
        if ($this->stripeTax->isEnabled() && !$order->getId()) {
            if (!$this->taxFlow->canOrderProceed()) {
                $this->exceptionHelper->throwTaxCalculationException();
            }
        }
    }
}