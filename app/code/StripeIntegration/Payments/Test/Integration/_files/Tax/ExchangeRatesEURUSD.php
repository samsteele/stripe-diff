<?php
use Magento\Directory\Model\Currency;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

// make it so the rate from EUR to USD is not equal to 1 / (rate from USD to EUR)
$rates = [
    'EUR' => [
        'USD' => '2',
    ]
];

$currencyModel = $objectManager->create(Currency::class);
$currencyModel->saveRates($rates);