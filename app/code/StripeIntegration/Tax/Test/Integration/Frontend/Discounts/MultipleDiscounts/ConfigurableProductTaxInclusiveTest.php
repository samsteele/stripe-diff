<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\Discounts\MultipleDiscounts;

use StripeIntegration\Tax\Test\Integration\Helper\DiscountCalculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ConfigurableProductTaxInclusiveTest extends \PHPUnit\Framework\TestCase
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
        $this->calculator = new DiscountCalculator('Romania');
    }

    /**
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior inclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior inclusive
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnablePercentDiscount.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnableFixedCartDiscount.php
     */
    public function testTaxInclusive()
    {
        $taxBehaviour = 'inclusive';
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableProduct")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        // 10% off 100, 30 discount applied on 100. 100 - 10 - 30 = 60
        $quoteData = $this->calculator->calculateQuoteData(60, 1, 5, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $quoteItem = $this->quote->getQuoteItem('simple-product-red');
        $quoteItemData = $this->calculator->calculateQuoteItemData(100, 60, 5, 1, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);
    }
}
