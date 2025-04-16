<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\Discounts\BuyXGetYFree;

use StripeIntegration\Tax\Test\Integration\Helper\DiscountCalculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SimpleProductTaxExclusiveTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store tax/stripe_tax/prices_and_promotions_tax_behavior exclusive
     * @magentoConfigFixture current_store tax/stripe_tax/shipping_tax_behavior exclusive
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnableBuyXGetYFree.php
     */
    public function testTaxExclusive()
    {
        $taxBehaviour = 'exclusive';
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("NormalQty3")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteData = $this->calculator->calculateQuoteData(100 + 100, 1, 5 + 5 + 5, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $quoteItem = $this->quote->getQuoteItem('simple-product');
        $quoteItemData = $this->calculator->calculateQuoteItemDataBuyXGetYFree(100, 100, 15, 3, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);
    }
}
