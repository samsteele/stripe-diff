---
title: Add external payment methods to the payment form
subtitle: Display non-Stripe payment methods inside the PaymentElement.
route: /connectors/adobe-commerce/cookbooks/external-payment-methods

stripe_products: []
---


The Stripe PaymentElement can display a number of [external payment methods](/payments/external-payment-methods?platform=web#available-external-payment-methods). We define an external payment method as one that isn't integrated with your Stripe Dashboard. Instead, you redirect your customer to an external URL for payment collection. All transactions happen outside Stripe, without any knowledge about their amount, currency, status, and so on. These payments don't display anywhere in your Stripe Dashboard. Stripe collects no payment fees when such a transaction takes place.

When payments are collected externally, no reconciliation happens between the Magento order and the external payment. The order remains in Pending Payment status and without and payment status details. This means that you also need to implement some reconciliation as well.

Stripe doesn't provide any guidelines on how to reconcile external payments with Magento orders. To implement reconciliation, contact the external payment method's support team for their specific integration guides.

In this guide, we only describe how to enable external payment methods and display them on your checkout page. When the customer clicks the Place Order button, they are redirected to an external URL which you specify for the external payment method.

## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.

```
app/code/Vendor/StripeCustomizations/
├── etc/
│   ├── module.xml
│   └── di.xml
├── Plugin/
│   └── Helper/
│       └── PaymentMethodPlugin.php
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

Inside `etc/di.xml`, define the following plugin:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="StripeIntegration\Payments\Helper\PaymentMethod">
        <plugin
            name="vendor_stripecustomizations_helper_paymentmethod_plugin"
            type="Vendor\StripeCustomizations\Plugin\Helper\PaymentMethodPlugin"
            sortOrder="10"
            disabled="false" />
    </type>
</config>
```

Inside `Plugin/Helper/PaymentMethodPlugin.php`, create the an afterMethod interceptor:

```php
<?php

namespace Vendor\Module\Plugin;

class PaymentMethodPlugin
{
    public function afterGetExternalPaymentMethods(
        \StripeIntegration\Payments\Helper\PaymentMethod $subject,
        array $result,
        $quote
    ): array {
        // Add custom payment method to the array
        $result[] = [
            'code' => 'external_payment_method_code',
            'redirect_url' => "https://example.com/checkout?merchant=stripeintegration&amount=" . $quote->getGrandTotal() * 100
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

