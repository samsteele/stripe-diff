---
title: Enable overcapture
subtitle: Capture more than the authorized amount.
route: /connectors/adobe-commerce/cookbooks/enable-over-capture

stripe_products: []
---

Overcapture allows you to capture more than the amount authorized during order placement. The [amount you can 
 capture](/payments/overcapture?platform=web&ui=stripe-hosted#availability-by-merchant-category) depends on the card network and your country and merchant category.

{% callout title="IC+ feature" type="caution" %}
Overcapture is an {% glossary term="IC+" %}IC+{% /glossary %} feature. If you're on blended Stripe pricing and want access to this feature, contact [Stripe Support](https://support.stripe.com).
{% /callout %}

The Stripe module supports overcapture by default. This guide describes how to enable it through a customization.

## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your vendor name.

```
app/code/Vendor/StripeCustomizations/
├── etc/
│   ├── module.xml
│   └── config.xml
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

Inside `etc/config.xml`, override the following settings from the Stripe module:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Store:etc/config.xsd">
    <default>
        <stripe_settings>
            <overcapture_enabled>1</overcapture_enabled>
        </stripe_settings>
    </default>
</config>
```

Enable the module:

```sh
php bin/magento module:enable Vendor_StripeCustomizations
php bin/magento setup:upgrade
php bin/magento cache:clean
php bin/magento cache:flush
```

## How overcapture works

When you invoice an order from the Magento admin, a new `Custom Capture Amount` input appears above **Submit Invoice**. The store's base currency symbol also appears in the input box, signaling that you must provide the overcapture amount in the base currency.

Leave the input box empty to capture the full amount of the invoice. Enter a custom amount to capture an alternate amount upon invoice submission, if the card network supports it.

## Other considerations

Using overcapture to update an authorized payment can affect the accuracy of your reconciliation:

* The invoice or order document doesn't reflect a custom capture amount. 
* Stripe Tax relies on the taxes visible on the invoice to record and reverse taxes, so it also doesn't reflect the custom capture amount.

To make sure dependent products and documents match the final payment, consider using the **Payment Action = Order** configuration setting instead. This saves the customer's payment method, but doesn't attempt an authorization. You can then edit the order before issuing the invoice, and the totals match the captured amount.
