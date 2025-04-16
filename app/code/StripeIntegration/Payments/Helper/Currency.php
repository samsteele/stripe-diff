<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class Currency
{
    private $convert;
    private $storeManager;
    private $priceCurrency;

    public function __construct(
        \StripeIntegration\Payments\Helper\Convert $convert,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
    ) {
        $this->convert = $convert;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
    }

    public function addCurrencySymbol($magentoAmount, $currencyCode = null)
    {
        if (empty($currencyCode))
            $currencyCode = $this->getCurrentCurrencyCode();

        $precision = $this->convert->getCurrencyPrecision($currencyCode);

        return $this->priceCurrency->format($magentoAmount, false, $precision, null, strtoupper($currencyCode));
    }

    public function getCurrentCurrencyCode()
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function getFormattedStripeAmount($stripeAmount, $currency, $order)
    {
        $orderAmount = $this->convert->stripeAmountToOrderAmount($stripeAmount, $currency, $order);

        return $this->addCurrencySymbol($orderAmount, $currency);
    }

    public function formatStripePrice($stripeAmount, string $currency)
    {
        $precision = $this->convert->getCurrencyPrecision($currency);
        $magentoAmount = $this->convert->stripeAmountToMagentoAmount($stripeAmount, $currency);

        return $this->priceCurrency->format($magentoAmount, false, $precision, null, strtoupper($currency));
    }

    public function getCurrencyPrecision(string $currency): int
    {
        return $this->convert->getCurrencyPrecision($currency);
    }
}