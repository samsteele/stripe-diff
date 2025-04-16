<?php
namespace StripeIntegration\Tax\Plugin\Quote\Model;

use Magento\Quote\Api\Data\PaymentInterface;
use StripeIntegration\Tax\Model\StripeTax;

class QuoteManagement
{
    private $taxFlow;
    private $stripeTax;

    public function __construct(
        \StripeIntegration\Tax\Model\TaxFlow $taxFlow,
        StripeTax $stripeTax
    )
    {
        $this->taxFlow = $taxFlow;
        $this->stripeTax = $stripeTax;
    }

    public function beforePlaceOrder(
        \Magento\Quote\Model\QuoteManagement $subject,
        $cartId,
        ?PaymentInterface $paymentMethod = null
    )
    {
        if ($this->stripeTax->isEnabled()) {
            $this->taxFlow->isNewOrderBeingPlaced = true;
        }

        return [$cartId, $paymentMethod];
    }

    public function afterPlaceOrder(
        \Magento\Quote\Model\QuoteManagement $subject,
        $returnValue
    )
    {
        $this->taxFlow->isNewOrderBeingPlaced = false;
        $this->taxFlow->customerInvalidLocation = false;

        return $returnValue;
    }
}
