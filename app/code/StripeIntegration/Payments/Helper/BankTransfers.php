<?php

namespace StripeIntegration\Payments\Helper;

class BankTransfers
{
    private $quoteHelper;
    private $configHelper;
    private $storeHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Config $configHelper,
        \StripeIntegration\Payments\Helper\Store $storeHelper
    ) {
        $this->quoteHelper = $quoteHelper;
        $this->configHelper = $configHelper;
        $this->storeHelper = $storeHelper;
    }

    public function getPaymentMethodOptions()
    {
        $quote = $this->quoteHelper->getQuote();
        $billingAddress = $quote->getBillingAddress();

        // Get the country code
        $countryCode = $billingAddress->getCountryId();
        if (empty($countryCode))
            return null;

        switch ($countryCode)
        {
            case "US":
                $bankTransfer = [
                    'type' => 'us_bank_transfer',
                ];
                break;

            case "GB":
                $bankTransfer = [
                    'type' => 'gb_bank_transfer',
                ];
                break;

            case "JP":
                $bankTransfer = [
                    'type' => 'jp_bank_transfer',
                ];
                break;

            case "MX":
                $bankTransfer = [
                    'type' => 'mx_bank_transfer',
                ];
                break;
            case "BE": // Belgium
            case "DE": // Germany
            case "ES": // Spain
            case "FR": // France
            case "IE": // Ireland
            case "NL": // Netherlands
                $bankTransfer = [
                    'type' => 'eu_bank_transfer',
                    'eu_bank_transfer' => [
                        'country' => $countryCode
                    ]
                ];
                break;
            case "AT": // Austria
            case "BG": // Bulgaria
            case "HR": // Croatia
            case "CY": // Cyprus
            case "CZ": // Czech Republic
            case "DK": // Denmark
            case "EE": // Estonia
            case "FI": // Finland
            case "GR": // Greece
            case "HU": // Hungary
            case "IT": // Italy
            case "LV": // Latvia
            case "LT": // Lithuania
            case "LU": // Luxembourg
            case "MT": // Malta
            case "PL": // Poland
            case "PT": // Portugal
            case "RO": // Romania
            case "SI": // Slovenia
            case "SK": // Slovakia
            case "SE": // Sweden
                $defaultEUCountry = $this->configHelper->getConfigData("payment/stripe_payments_bank_transfers/default_eu_country", $this->storeHelper->getStoreId());
                if (empty($defaultEUCountry))
                    return null;

                $bankTransfer = [
                    'type' => 'eu_bank_transfer',
                    'eu_bank_transfer' => [
                        'country' => $defaultEUCountry
                    ]
                ];
                break;
            default:
                return null;
        }

        return [
            "customer_balance" => [
                'funding_type' => 'bank_transfer',
                'bank_transfer' => $bankTransfer,
            ]
        ];
    }

    // This method exists because it needs to be mocked
    public function getStripeInvoiceNumber($magentoInvoice)
    {
        return $magentoInvoice->getIncrementId();
    }
}