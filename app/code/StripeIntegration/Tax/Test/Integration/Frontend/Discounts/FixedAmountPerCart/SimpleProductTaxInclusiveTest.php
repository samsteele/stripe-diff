<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\Discounts\FixedAmountPerCart;

use StripeIntegration\Tax\Test\Integration\Helper\DiscountCalculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SimpleProductTaxInclusiveTest extends \PHPUnit\Framework\TestCase
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
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnableFixedCartDiscount.php
     */
    public function testTaxInclusive()
    {
        $taxBehaviour = 'inclusive';
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("TwoProductsSimple")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteData = $this->calculator->calculateQuoteData(81.25 + 48.75, 1, 5 + 5, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $quoteItem = $this->quote->getQuoteItem('simple-product');
        $quoteItemData = $this->calculator->calculateQuoteItemData(100, 81.25, 10, 1, $taxBehaviour, [48.75], true);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);

        $quoteItem = $this->quote->getQuoteItem('simple-product-bundle-4');
        $quoteItemData = $this->calculator->calculateQuoteItemData(60, 48.75, 10, 1, $taxBehaviour, [81.25], false);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);
    }
}
