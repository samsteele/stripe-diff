<?php
use Magento\Directory\Model\Currency;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$rates = [
    'USD' => [
        'KRW' => '1300',
    ]
];

$currencyModel = $objectManager->create(Currency::class);
$currencyModel->saveRates($rates);