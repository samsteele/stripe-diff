---
title: Integrate a custom fee to the tax calculation
subtitle: Add additional taxable fees to sold products.
route: /connectors/adobe-commerce/cookbooks/tax-additional-fees

stripe_products: []
---



You might need to add additional fees to products when calculating tax and calculate tax for those fees. 
## Add a custom fee
The Stripe Tax module creates requests at the point of order, invoice, and credit memo creation and sends them to the [Stripe Tax calculation API](/api/tax/calculations).
The module runs through the items of the quote, invoice, or credit memo to add the details of the items or the entire order to the calculation request.

If you add a custom fee that requires tax calculation, you need to provide the details to the Stripe Tax module so the tax calculation request can add it.

In this integration, the developer of the custom fee provides the total value of the custom fee through an observer, and it's added to the Tax calculation API request and sent to Stripe to be calculated.

# How the Stripe Tax module works

The Stripe Tax module adds the additional fees based on where the fees occur: 
* At the item level (the tax is applied to each item of the quote, invoice, and credit memo)
2. At the quote, order, invoice, or credit memo level (the tax is set for the whole basket and further)

The third party developer needs to send the following three details:
* The total price of the custom fee (if an item has a custom tax of 3, and the quantity of the item is 2, the value sent is 6)
* The tax class for the custom fee
* The code of the custom fee

These three components will be sent forward in an array with the following structure:

```php
$details = [
    'amount' => $amountValue, // generic value—provide your own when developing
    'tax_class_id' => $classId, // generic value—provide your own when developing
    'code' => 'custom_fee' // generic value—provide your own when developing
];
```

You need to send this array structure based on the action being performed (quote calculation, invoice creation, or credit memo creation) and where the custom fee applies (item, quote, invoice, or credit memo level).

The observer sends this information to the Tax module, and provides an object that the information is added to. The observer needs to listen to one of the following six events, depending on the stage of the order process and where the custom fee needs to be applied:
* `stripe_tax_additional_fee_item` applies to the items on an order
* `stripe_tax_additional_fee_quote` applies to the quote
* `stripe_tax_additional_fee_invoice_item` applies to invoice items
4. `stripe_tax_additional_fee_invoice` applies to the whole invoice
5. `stripe_tax_additional_fee_creditmemo_item` applies to credit memo items
6. `stripe_tax_additional_fee_creditmemo` applies to the whole credit memo

Each of the events contains a Magento data object called `additional_fees_container` where you can add the details of what needs to be calculated by the Stripe Tax module. To add the details of the tax calculation, call the method `->addAdditionalFee()` with the details array as a parameter:
```php
$additionalFees = $observer->getAdditionalFeesContainer();

// other operations to get the values to send forward

$details = [
    'amount' => $amountValue, // generic value—provide your own when developing
    'tax_class_id' => $classId, // generic value—provide your own when developing
    'code' => 'custom_fee' // generic value—provide your own when developing
];

$additionalFees->addAdditionalFee($details);
}
```

For cases when these additional fees might be added either on a parent or child item (for example, a bundled dynamic product), we send events for both the bundled product and the products within it.

When using bundled products or other types of products where you can specify the quantity of the top-level product and sub-product separately, send the amount of the additional fee and consider the quantity of both the parent and child items.
qty of the parent and the child items.

After Stripe calculates the tax for the invoices, you receive an array of the tax and the base tax. You can use this information to set custom fields in the database if you require it. The array contains the fee code as a key and the
calculated values that are set for it. You can access it through the `getAdditionalFeesTax()` method called in the item or on the invoice.


### Example for applying tax on the item at the quote level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_item">
    <observer name="your_custom_observer_name" instance="Vendor\YourModule\Observer\AddAdditionalFeeForQuoteItem" />
</event>
```
The data provided to the event in addition to the `additional_fees_container` is:
* `item`: The item the tax is calculated for
* `quote`: The quote the item belongs to

Inside your observer file (`app/code/Vendor/YourModule/Observer/AddAdditionalFeeForQuoteItem.php`), add the code for
creating the details for the calculation as in the following example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForQuoteItem implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $item = $observer->getItem();
        $quote = $observer->getQuote();

        // Calculations where you determine that the item has an additional tax and the tax needs to be calculated
        // After the calculations are complete and you have the values, add them to the details array and send the array forward

        $itemDetails = [
                'amount' => $amount, // generic value determined from previous calculations—provide your own when developing
                'tax_class_id' => $taxClassId, // generic value determined from previous calculations—provide your own when developing
                'code' => 'custom_fee' // generic value—provide your own when developing
            ];

        $additionalFees->addAdditionalFee($itemDetails);
    }
}
```

### Example for applying tax at the quote level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_quote">
    <observer name="stripe_tax_additional_fee_quote" instance="Vendor\YourModule\Observer\AddAdditionalFeeForQuote" />
</event>
```
The data provided to the event in addition to the `additional_fees_container` is:
1. `quote`: The quote the tax is calculated for
2. `total`: The collected totals up to this point

Inside your observer file `app/code/Vendor/YourModule/Observer/AddAdditionalFeeForQuote.php` add the code for
creating the details for the calculation as in the following example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForQuote implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $quote = $observer->getQuote();
        $total = $observer->getTotal();

        // Calculations where you determine that the quote has an additional tax and the tax needs to be calculated
        // After the calculations are done and you have the values, add them to the details array and send the array forward

        $details = [
                'amount' => $amount, // generic value determined from previous calculations—provide your own when developing
                'tax_class_id' => $taxClassId, // generic value determined from previous calculations—provide your own when developing
                'code' => 'custom_fee' // generic value—provide your own when developing
            ];

        $additionalFees->addAdditionalFee($details);
    }
}
```

### Example for applying tax on the item at the invoice level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_invoice_item">
    <observer name="your_custom_observer_name" instance="Vendor\YourModule\Observer\AddAdditionalFeeForInvoiceItem" />
</event>
```
The data provided to the event in addition to the `additional_fees_container` is:
* `item`: The item the tax is calculated for—you can get other details such as the order item for this item one time in the observer.
2. `invoice`: The quote the item belongs to—you can get information such as the order of the item from the invoice. 

Inside your observer file `app/code/Vendor/YourModule/Observer/AddAdditionalFeeForInvoiceItem.php` add the code for
creating the details for the calculation, as in the following example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForInvoiceItem implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $item = $observer->getItem();
        $invoice = $observer->getInvoice();

        // Calculations where you determine that the item has an additional tax and the tax needs to be calculated
        // After the calculations are complete and you have the values, add them to the details array and send the array forward

        $itemDetails = [
                'amount' => $amount, // generic value determined from previous calculations, provide your own when developing
                'tax_class_id' => $taxClassId, // generic value determined from previous calculations, provide your own when developing
                'code' => 'custom_fee' // generic value, provide your own when developing
            ];

        $additionalFees->addAdditionalFee($itemDetails);
    }
}
```

### Example for applying tax at the invoice level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_invoice">
    <observer name="stripe_tax_additional_fee_quote" instance="Vendor\YourModule\Observer\AddAdditionalFeeForInvoice" />
</event>
```
The data provided to the event apart from the `additional_fees_container` is:
1. `invoice`: The invoice where the custom fee is applied
2. `order`: The order the invoice belongs to

Inside your observer file `app/code/Vendor/YourModule/Observer/AddAdditionalFeeForInvoice.php` add the code for
creating the details for the calculation. The following is an example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForInvoice implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $invoice = $observer->getInvoice();
        $order = $observer->getOrder();

        // Calculations where you determine that the invoice has an additional tax and the tax needs to be calculated
        // After the calculations are complete and you have the values, add them to the details array and send the array forward

        $details = [
                'amount' => $amount, // generic value determined from previous calculations, please provide your own when developing
                'tax_class_id' => $taxClassId, // generic value determined from previous calculations—provide your own when developing
                'code' => 'custom_fee' // generic value—provide your own when developing
            ];

        $additionalFees->addAdditionalFee($details);
    }
}
```

### Changes to credit memos

When creating credit memos, the structure of the array that needs to be passed back to the Stripe Tax module changes.
The `tax_class_id` is removed from the array and you get a new field called `amount_tax`, which must contain the tax amount that you want to refund for the custom fee. 
```php
$details = [
    'amount' => $amount,
    'amount_tax' => $taxAmount,
    'code' => 'custom_fee'
];
```
{% callout type="note" %}
The `code` component of the details array needs to be the same code component provided in the invoice step. This ensures that Stripe knows what component to subtract the refunded amounts from.
{% /callout %}
amounts from.

### Example for applying tax on the item at the credit memo level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_creditmemo_item">
    <observer name="your_custom_observer_name" instance="Vendor\YourModule\Observer\AddAdditionalFeeForCreditmemoItem" />
</event>
```
The data provided to the event in addition to the `additional_fees_container` is:
* `item`: The item the tax is calculated for—you can get other details such as the order item for this item one time in the observer.
* `creditmemo`: The quote the item belongs to
3. `invoice`: The quote the item belongs to
4. `order`: The quote the item belongs to

Inside your observer file (`app/code/Vendor/YourModule/Observer/AddAdditionalFeeForCreditmemoItem.php`), add the code for creating the details for the calculation as in the following example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForCreditmemoItem implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $item = $observer->getItem();
        $creditmemo = $observer->getCreditmemo();
        $invoice = $observer->getInvoice();
        $order = $observer->getOrder();

        // Calculations where you determine that the item has an additional tax and the tax needs to be refunded
        // After the calculations are complete and you have the values, add them to the details array and send the array forward

        $itemDetails = [
                'amount' => $amount, // generic value determined from previous calculations, please provide your own when developing
                'tax_amount' => $taxAmount, // generic value determined from previous calculations, please provide your own when developing
                'code' => 'custom_fee' // generic value, please provide your own when developing
            ];

        $additionalFees->addAdditionalFee($itemDetails);
    }
}
```

### Example for applying tax at the credit memo level

Inside your events file (`app/code/Vendor/YourModule/etc/events.xml`), add the following event:
```xml
<event name="stripe_tax_additional_fee_creditmemo">
    <observer name="stripe_tax_additional_fee_quote" instance="Vendor\YourModule\Observer\AddAdditionalFeeForCreditmemo" />
</event>
```
The data provided to the event in addition to the `additional_fees_container` is:
* `creditmemo`: The quote the item belongs to
* `invoice`: The quote the item belongs to
* `order`: The quote the item belongs to

Inside your observer file (`app/code/Vendor/YourModule/Observer/AddAdditionalFeeForCreditmemo.php`), add the code for
creating the details for the calculation as in the following example:
```php
<?php

namespace Vendor\YourModule\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class AddAdditionalFeeForInvoice implements ObserverInterface
{

    public function execute(Observer $observer)
    {
        $additionalFees = $observer->getAdditionalFeesContainer();
        $creditmemo = $observer->getCreditmemo();
        $invoice = $observer->getInvoice();
        $order = $observer->getOrder();

        // Calculations where you determine that the invoice has an additional tax and the tax needs to be refunded
        // After the calculations are complete and you have the values, add them to the details array and sent the array forward

        $details = [
                'amount' => $amount, // generic value determined from previous calculations, please provide your own when developing
                'tax_amount' => $taxClassId, // generic value determined from previous calculations, please provide your own when developing
                'code' => 'custom_fee' // generic value, please provide your own when developing
            ];

        $additionalFees->addAdditionalFee($details);
    }
}
```
