<?php

namespace StripeIntegration\Tax\Helper;

use StripeIntegration\Tax\Exceptions\TaxCalculationException;
use StripeIntegration\Tax\Model\TaxFlow;

class Exception
{
    private $taxFlow;

    public function __construct(
        TaxFlow $taxFlow
    )
    {
        $this->taxFlow = $taxFlow;
    }

    public function throwTaxCalculationException()
    {
        if ($this->taxFlow->customerInvalidLocation) {
            throw new TaxCalculationException(__('Your address could not be verified.'));
        } else {
            throw new TaxCalculationException(__('Tax could not be calculated.'));
        }
    }
}