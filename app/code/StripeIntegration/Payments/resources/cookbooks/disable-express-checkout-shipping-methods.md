---
title: Disable specific shipping methods in Express Checkout modals
subtitle: Remove specific shipping methods from the Express Checkout modal.
route: /connectors/adobe-commerce/cookbooks/disable-express-checkout-payment-methods
stripe_products: []
---


Certain shipping methods, like **Pick up from store**, might require additional customer input, such as the pickup address. These aren't suitable for Express Checkout wallets, which don't provide a way to customize the payment modal.

If you use this kind of shipping method, you can disable it for Express Checkout using a module customization.

## Create a new module

Create a new module with the following directory structure. Replace `Vendor` with your preferred vendor name.

```
app/code/Vendor/StripeCustomizations/
├── etc/
│   └── module.xml
├── Plugin/
│   └── Api/
│       └── Response/
│           └── ECEResponse.php
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

Inside `etc/module.xml`, define the module and set up dependencies to ensure it loads after the Stripe module.

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
            name="vendor_stripecustomizations_eceresponse_plugin"
            type="Vendor\StripeCustomizations\Plugin\Api\Response\ECEResponse"
            sortOrder="10"
            disabled="false" />
    </type>
</config>
```

Inside `Plugin/Api/Response/ECEResponse.php`, create the an afterMethod interceptor:

```php
<?php
namespace Vendor\StripeCustomizations\Plugin\Payments;

class ECEResponse
{
    /**
     * After plugin for getShippingRatesForQuoteShippingAddress method.
     *
     * @param $subject
     * @param array $result
     * @return array
     */
    public function afterGetShippingRatesForQuoteShippingAddress($subject, array $result)
    {
        $idToRemove = "store_pickup";

        return array_filter($result, function ($shippingRate) use ($idToRemove) {
            return $shippingRate['id'] !== $idToRemove;
        });
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