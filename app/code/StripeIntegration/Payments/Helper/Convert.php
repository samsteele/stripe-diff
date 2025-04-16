<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Exception\Exception;

class Convert
{
    public const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    public const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public function isZeroDecimalCurrency(string $currency)
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES);
    }

    public function isThreeDecimalCurrency(string $currency)
    {
        return in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES);
    }

    public function magentoAmountToStripeAmount($amount, $currency): int
    {
        if (!is_numeric($amount))
            return 0;

        $amount = floatval($amount);

        if ($this->isZeroDecimalCurrency($currency))
            return (int) round($amount);

        if ($this->isThreeDecimalCurrency($currency))
            return (int) round($amount * 100) * 10;

        return (int) round($amount * 100);
    }

    public function stripeAmountToMagentoAmount($amount, string $currency): float
    {
        if (!is_numeric($amount))
            return 0.0;

        $amount = floatval($amount);

        if ($this->isZeroDecimalCurrency($currency))
            return $amount;

        if ($this->isThreeDecimalCurrency($currency))
            return $amount / 1000;

        return $amount / 100;
    }

    public function getCurrencyPrecision(string $currency): int
    {
        if ($this->isZeroDecimalCurrency($currency))
            return 0;

        if ($this->isThreeDecimalCurrency($currency))
            return 3;

        return 2;
    }

    public function stripeAmountToOrderAmount($amount, $currency, $order)
    {
        if (strtolower($currency) != strtolower($order->getOrderCurrencyCode()))
            throw new Exception("The order currency does not match the Stripe currency");

        return $this->stripeAmountToMagentoAmount($amount, $currency);
    }

    public function stripeAmountToQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new Exception("The quote currency does not match the Stripe currency");

        return $this->stripeAmountToMagentoAmount($amount, $currency);
    }

    public function stripeAmountToBaseQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new Exception("The order currency does not match the Stripe currency");

        $precision = $this->getCurrencyPrecision($currency);
        $magentoAmount = $this->stripeAmountToMagentoAmount($amount, $currency);

        $baseAmount = round(floatval($magentoAmount / $quote->getBaseToQuoteRate()), $precision);

        return $baseAmount;
    }

    public function baseAmountToOrderAmount($baseAmount, $order)
    {
        $baseToOrderRate = (float) $order->getBaseToOrderRate();

        if (empty($baseToOrderRate))
        {
            $baseToOrderRate = 1;
        }

        $orderCurrency = $order->getOrderCurrencyCode();
        $currencyPrecision = $this->getCurrencyPrecision($orderCurrency);

        return round(floatval($baseAmount * $baseToOrderRate), $currencyPrecision);
    }

    public function baseAmountToCurrencyAmount($baseAmount, $targetCurrency, $referenceOrder)
    {
        if (strtolower($targetCurrency) == strtolower($referenceOrder->getBaseCurrencyCode()))
        {
            return $baseAmount;
        }
        else
        {
            $rate = $referenceOrder->getBaseToOrderRate();

            if (empty($rate))
            {
                throw new Exception("Currency code $targetCurrency was not used to place order #" . $referenceOrder->getIncrementId());
            }

            $currencyPrecision = $this->getCurrencyPrecision($targetCurrency);
            return round(floatval($baseAmount * $rate), $currencyPrecision);
        }
    }

    public function orderAmountToBaseAmount($amount, $currency, $order, $precision = 4)
    {
        if (strtolower($currency) == strtolower($order->getOrderCurrencyCode()))
            $rate = $order->getBaseToOrderRate();
        else
            throw new Exception("Currency code $currency was not used to place order #" . $order->getIncrementId());

        // $rate = $this->currencyFactory->create()->load($order->getBaseCurrencyCode())->getAnyRate($currency);
        if (empty($rate))
            return $amount; // The base currency and the order currency are the same

        return round(floatval($amount / $rate), $precision);
    }
}