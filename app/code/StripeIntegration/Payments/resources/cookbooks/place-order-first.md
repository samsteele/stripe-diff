---
title: How to place an order before collecting a 3D Secure payment
subtitle: Change the default behavior of the Stripe module when 3DS authentication is required.
route: /connectors/adobe-commerce/cookbooks/place-order-first

stripe_products: []
---



This guide describes a customization that changes the default behavior of the Stripe module when 3DS authentication is required. In the default design, when 3DS is required, the payment is collected when the 3DS authentication succeeds and before the order is placed.

Using this customization, the order is first  placed in `Pending Payment` status, and the 3DS modal opens. If 3DS succeeds, the customer is redirected to the success page. Stripe then asynchronously sends the charge.succeeded webhook event back to your website, which causes the order to switch to `Processing` or `Complete` status.

If the customer fails 3DS authentication, or if they abandon the payment process, the order automatically cancels through cron after 2-3 hours. During this time, the inventory remains reserved. If you need to cancel the order sooner, you can configure it with the [pending payment order lifetime](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/orders/order-scheduled-operations#set-pending-payment-order-lifetime) setting in the admin area.
Ò
## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.

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
            <manual_authentication>
                <rest_api>x</rest_api> <!-- Removing "card" and "link" will achieve the desired behavior -->
            </manual_authentication>
        </stripe_settings>
    </default>
</config>
```
{% callout type="note" %}
The single `x` value for `<rest_api>`is a placeholder character that forces Magento to respect the override. If you leave the value empty, Magento ignores it and wont override the value.
{%/callout %}

Enable the module:

```sh
php bin/magento module:enable Vendor_StripeCustomizations
php bin/magento setup:upgrade
php bin/magento cache:clean
php bin/magento cache:flush
```

## GraphQL considerations

The REST API is used by the majority of Magento themes that are based on the core Luma theme. If you're using a custom storefront which uses GraphQL instead of the REST API, then this behavior is the default, and you don't need to make the change described above.

If however you prefer your GraphQL-based storefront to place the order _after_ the payment succeeds, you can use the same customization approach with the following config inside `etc/config.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Store:etc/config.xsd">
    <default>
        <stripe_settings>
            <manual_authentication>
                <graphql_api>card,link</graphql_api>
            </manual_authentication>
        </stripe_settings>
    </default>
</config>
```
