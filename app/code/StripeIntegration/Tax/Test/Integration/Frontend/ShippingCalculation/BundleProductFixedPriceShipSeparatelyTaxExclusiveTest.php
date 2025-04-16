<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\ShippingCalculation;

use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class BundleProductFixedPriceShipSeparatelyTaxExclusiveTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $compare;
    private $calculator;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Tax\Test\Integration\Helper\Quote();
        $this->compare = new Compare($this);
        $this->calculator = new Calculator('Romania');
    }

    /**
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior exclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior exclusive
     */
    public function testTaxExclusive()
    {
        $taxBehaviour = 'exclusive';
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("BundleProductFixedPriceShipSeparately")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteData = $this->calculator->calculateQuoteData(160, 1, 10, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $address = $this->quote->getQuote()->getShippingAddress();
        $addressData = $this->calculator->calculateShippingData(160, 10, 1, $taxBehaviour);
        $this->compare->compareShippingData($address, $addressData);
    }
}
