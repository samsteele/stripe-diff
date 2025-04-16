---
title: Enable multicapture
subtitle: Capture the same authorization multiple times.
route: /connectors/adobe-commerce/cookbooks/enable-multi-capture

stripe_products: []
---


Partially capturing an authorization releases the remaining amount by default. To capture the remaining order amount after the initial capture, you must create a new payment, which might not succeed. You can use the Stripe multicapture feature to capture multiple installments against the same payment authorization.

{% callout title="IC+ feature" type="caution" %}
Multicapture is an {% glossary term="IC+" %}IC+{% /glossary %} feature. If you're on blended Stripe pricing and want access to this feature, contact [Stripe Support](https://support.stripe.com).
{% /callout %}

The Stripe module supports multicapture by default. This guide describes how to enable it through a customization.

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
            <multicapture_enabled>1</multicapture_enabled>
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
