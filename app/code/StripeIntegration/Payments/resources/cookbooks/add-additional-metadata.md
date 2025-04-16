---
title: Add additional metadata to payments
subtitle: Send additional payments metadata from Adobe Commerce to Stripe.
route: /connectors/adobe-commerce/cookbooks/add-additional-metadata

stripe_products: []
---



When you click a payment in your Stripe Dashboard, you might see some metadata already set on the payment, such as the order number in Magento and the module version used to collect the payment. This guide describes how to extend the Stripe module to add additional metadata to each payment.

## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.


```
app/code/Vendor/StripeCustomizations/
├── etc/
│   ├── module.xml
│   └── di.xml
├── Plugin/
│   └── Payments/
│       └── ConfigPlugin.php
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
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="StripeIntegration\Payments\Model\Config">
        <plugin
            name="vendor_stripecustomizations_payments_config_plugin"
            type="Vendor\StripeCustomizations\Plugin\Payments\ConfigPlugin"
            sortOrder="10"
            disabled="false" />
    </type>
</config>
```

Inside `Plugin/Payments/ConfigPlugin.php`, create the an afterMethod interceptor:

```php
<?php
namespace Vendor\StripeCustomizations\Plugin\Payments;

use StripeIntegration\Payments\Model\Config;

class ConfigPlugin
{
    /**
     * After plugin for getMetadata method.
     *
     * @param Config $subject
     * @param array $result
     * @param Order $order
     * @return array
     */
    public function afterGetMetadata(Config $subject, array $result, $order)
    {
        // Add new metadata
        $result['CustomKey1'] = 'CustomValue1';
        $result['CustomKey2'] = 'CustomValue2';

        // You can add dynamic data based on business logic
        // For example, adding customer group
        $customerGroup = $order->getCustomerGroupId();
        $result['Customer Group'] = $customerGroup;

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
