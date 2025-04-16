---
title: Style the payment form at the checkout
subtitle: Change the theme or appearance of Stripe's PaymentElement.
route: /connectors/adobe-commerce/cookbooks/style-payment-element

stripe_products: []
---



You can change the theme or appearance of Stripe's [PaymentElement](/payments/payment-element) on your checkout page, using the [Appearance API](/elements/appearance-api).

You can set the following parameter in the Appearance API:

 * [Theme](/elements/appearance-api?platform=web#theme): A foundational UI built by Stripe.
 * [Variables](/elements/appearance-api?platform=web#variables): CSS definitions to apply across the theme.
 * [Rules](/elements/appearance-api?platform=web#rules): Conditions that target individual DOM elements within iframe of the payment form.

This guide instructs you how to change the Appearance API parameters of the Stripe module by defining a custom module that overrides them.

## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.

```
app/code/Vendor/StripeCustomizations/
├── etc/
│   ├── module.xml
│   └── di.xml
├── Plugin/
│   └── Payments/
│       └── Helper/
│           └── InitParamsPlugin.php
├── registration.php
```

Inside `registration.php`, register your module with Magento.

```php
<?php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Vendor_StripeCustomizations',
    __DIR__
);
```

Inside `etc/module.xml`, define the module and set up dependencies to make sure it loads after the Stripe module.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Vendor_StripeCustomizations" setup_version="1.0.0">
        <sequence>
            <module name="StripeIntegration_Payments"/>
        </sequence>
    </module>
</config>
```

Inside `etc/di.xml`, define the plugin:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="StripeIntegration\Payments\Helper\InitParams">
        <plugin
            name="vendor_stripecustomizations_payments_initparams_plugin"
            type="Vendor\StripeCustomizations\Plugin\Payments\Helper\InitParamsPlugin"
            sortOrder="10"
            disabled="false" />
    </type>
</config>
```

Inside `Plugin/Payments/Helper/InitParamsPlugin.php`, create the plugin class:

```php
<?php
namespace Vendor\StripeCustomizations\Plugin\Payments\Helper;

use StripeIntegration\Payments\Helper\InitParams;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class InitParamsPlugin
{
    /**
     * After plugin for getElementOptions method.
     *
     * @param InitParams $subject
     * @param array $result
     * @return array
     */
    public function afterGetElementOptions(InitParams $subject, $result)
    {
        $result['appearance']['variables']['colorPrimary'] = '#FF5733'; // Example: Primary color
        $result['appearance']['variables']['spacingUnit'] = '4px';       // Example: Spacing unit
        $result['appearance']['rules'] = [
            [
                'selector' => 'button',
                'style' => [
                    'backgroundColor' => '#FF5733',
                    'fontSize' => '16px'
                ]
            ],
            [
                'selector' => 'input',
                'style' => [
                    'borderColor' => '#CCCCCC'
                ]
            ]
        ];

        return $result;
    }
}
```

Enable the module:

```sh
php bin/magento module:enable Vendor_StripeCustomizations
php bin/magento setup:upgrade
php bin/magento cache:clean
php bin/magento cache:flush
```
