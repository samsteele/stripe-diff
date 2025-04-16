<?php

namespace StripeIntegration\Tax\Test\Integration\AdminArea\PriceCalculation;

use StripeIntegration\Tax\Test\Integration\Helper\Calculator;
use StripeIntegration\Tax\Test\Integration\Helper\Compare;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SimpleProductCustomPriceTaxExclusiveTest extends \PHPUnit\Framework\TestCase
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
        $this->quote->createAdmin()
            ->setCustomer('LoggedIn')
            ->setCart("Normal");

        $quoteItem = $this->quote->getQuoteItem('tax-simple-product');
        $quoteItem->setCustomPrice(150);
        $quoteItem->setOriginalCustomPrice(150);
        $quoteItem->getProduct()->setIsSuperMode(true);

        $this->quote->setShippingAddress("Romania")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Romania")
            ->setPaymentMethod("checkmo");

        $quoteData = $this->calculator->calculateQuoteData(150, 2, 5, $taxBehaviour);
        $this->compare->compareQuoteData($this->quote->getQuote(), $quoteData);

        $quoteItem = $this->quote->getQuoteItem('tax-simple-product');
        $quoteItemData = $this->calculator->calculateQuoteItemData(150, 10, 2, $taxBehaviour);
        $this->compare->compareQuoteItemData($quoteItem, $quoteItemData);
    }
}
