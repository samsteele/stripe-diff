<?php

namespace StripeIntegration\Tax\Test\Integration\Frontend\Discounts\MultipleDiscounts;

use StripeIntegration\Tax\Test\Integration\Helper\DiscountCalculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class BundleFixedPriceProductTaxInclusiveTest extends \PHPUnit\Framework\TestCase
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
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnableBuyXGetYFree.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Tax/Test/Integration/_files/Data/EnablePercentDiscount.php
     */
    public function testTaxInclusive()
    {
        $taxBehaviour = 'inclusive';
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("BundleProductFixedPriceX3")
            ->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        // 10% off 480, 1 free item of 160. 480 - 160 - 48 = 272. 272 / 2 = 136.
        $quoteData = $this->calculator->calculateQuoteData(136 + 136, 1, 5 + 5 + 5, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $quoteItem = $this->quote->getQuoteItem('bundle-fixed-price-simple-product-bundle-2-simple-product-bundle-4');
        $quoteItemData = $this->calculator->calculateQuoteItemDataBuyXGetYFree(160, 136, 15, 3, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);
    }
}
