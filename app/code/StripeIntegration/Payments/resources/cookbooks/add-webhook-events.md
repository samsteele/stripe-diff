---
title: Add Custom Events to Stripe Webhooks
subtitle: Extend the list of supported webhook events in the Stripe module for Adobe Commerce.
route: /connectors/adobe-commerce/cookbooks/add-custom-events

stripe_products: []
---

Stripe Webhooks are a powerful tool to trigger actions based on events that occur in your Stripe account. This guide describes how to extend the Stripe module to add a custom event to the list of enabled events.

# Create a new module

First, create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.

```
app/code/Vendor/StripeCustomizations/
├── etc/
│   ├── module.xml
│   └── di.xml
├── Plugin/
│   └── Webhooks/
│       └── EnabledEventsPlugin.php
├── registration.php
```

## 1. Register your module

Inside `registration.php`, register your module with Magento.

```php
<?php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Vendor_StripeCustomizations',
    __DIR__
);
```

## 2. Define your module configuration

Inside `etc/module.xml`, define the module and specify that it depends on the Stripe module.

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

## 3. Configure dependency injection

Inside `etc/di.xml`, define a plugin for the `EnabledEvents` class.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="StripeIntegration\Payments\Model\Webhooks\EnabledEvents">
        <plugin
            name="vendor_stripecustomizations_webhooks_enabledevents_plugin"
            type="Vendor\StripeCustomizations\Plugin\Webhooks\EnabledEventsPlugin"
            sortOrder="10"
            disabled="false" />
    </type>
</config>
```

## 4. Create the plugin

Inside `Plugin/Webhooks/EnabledEventsPlugin.php`, define an `after` plugin for the `getEvents` method.

```php
<?php

namespace Vendor\StripeCustomizations\Plugin\Webhooks;

use StripeIntegration\Payments\Model\Webhooks\EnabledEvents;

class EnabledEventsPlugin
{
    /**
     * After plugin for getEvents method.
     *
     * @param EnabledEvents $subject
     * @param array $result
     * @return array
     */
    public function afterGetEvents(EnabledEvents $subject, array $result)
    {
        // Add custom events to the list
        $result[] = 'custom.event.example';
        $result[] = 'another.custom.event';

        return $result;
    }
}
```

Here, you add your custom events (`custom.event.example` and `another.custom.event`) to the existing list of events.

For a full list of supported event types, please refer to the [Types of events](https://docs.stripe.com/api/events/types) documentation.

## 5. Enable your module

Run the following commands to enable and deploy your module:

```sh
php bin/magento module:enable Vendor_StripeCustomizations
php bin/magento setup:upgrade
php bin/magento cache:clean
php bin/magento cache:flush
```

## 6. Verify the changes

Run the following command from the CLI to reconfigure webhooks:

```sh
bin/magento stripe:webhooks:configure
```

Navigate to your [Stripe dashboard](https://dashboard.stripe.com/webhooks) and manually inspect the new webhook endpoint configuration. When you hover over the "Listening for xx events" tab, you should be able to see the new webhook event in the list.
