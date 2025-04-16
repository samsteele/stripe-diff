define(
    [
        'ko',
        'Magento_Checkout/js/view/summary/abstract-total',
        'Magento_Checkout/js/model/quote',
        'Magento_Catalog/js/price-utils',
        'Magento_Checkout/js/model/totals',
        'mage/translate',
        'StripeIntegration_Payments/js/action/get-future-subscriptions',
        'Magento_Customer/js/customer-data'
    ],
    function (ko, Component, quote, priceUtils, totals, $t, getFutureSubscriptions, customerData) {
        "use strict";
        return Component.extend({
            defaults: {
                isFullTaxSummaryDisplayed: window.checkoutConfig.isFullTaxSummaryDisplayed || false,
                template: 'StripeIntegration_Payments/checkout/future_subscriptions',
                futureSubscriptions: ko.observable(window.checkoutConfig.payment.stripe_payments.futureSubscriptions),
                hasFutureSubscriptions: ko.observable(window.checkoutConfig.payment.stripe_payments.hasFutureSubscriptions),
                fetching: ko.observable(false)
            },
            totals: quote.getTotals(),
            isTaxDisplayedInGrandTotal: window.checkoutConfig.includeTaxInGrandTotal || false,

            initialize: function ()
            {
                this._super();

                this.observe(['futureSubscriptions', 'hasFutureSubscriptions']);
                this.futureSubscriptions(window.checkoutConfig.payment.stripe_payments.futureSubscriptions);
                this.hasFutureSubscriptions(window.checkoutConfig.payment.stripe_payments.hasFutureSubscriptions);

                this.canDisplay = ko.computed(function()
                {
                    return this.hasFutureSubscriptions();
                }, this);

                this.getTitle = ko.computed(function()
                {
                    return this.getData('title');
                }, this);

                this.getStartDateLabel = ko.computed(function()
                {
                    return this.getData('start_date_label');
                }, this);

                this.getFrequencyLabel = ko.computed(function()
                {
                    return this.getData('frequency_label');
                }, this);

                this.getFormattedAmount = ko.computed(function()
                {
                    return this.getData('formatted_amount');
                }, this);

                this.futureSubscriptions(this.getFutureSubscriptions());

                var grandTotal = quote.totals().grand_total;

                quote.totals.subscribe(function (totals)
                {
                    if (grandTotal == quote.totals().grand_total)
                        return;

                    grandTotal = quote.totals().grand_total;

                    this.refresh(quote);
                }, this);

                if (quote.isVirtual()) {
                    quote.billingAddress.subscribe(function (billingAddress) {
                        if (this.hasFutureSubscriptions()) {
                            this.refresh(quote);
                        }
                    }, this);
                } else {
                    quote.shippingAddress.subscribe(function (shippingAddress) {
                        if (this.hasFutureSubscriptions()) {
                            this.refresh(quote);
                        }
                    }, this);
                }
            },

            getFutureSubscriptions: function()
            {
                if (
                    window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.stripe_payments &&
                    window.checkoutConfig.payment.stripe_payments.hasFutureSubscriptions &&
                    window.checkoutConfig.payment.stripe_payments.futureSubscriptions
                )
                {
                    return window.checkoutConfig.payment.stripe_payments.futureSubscriptions;
                }

                return null;
            },

            refresh: function(quote)
            {
                if (!this.getFutureSubscriptions())
                    return;

                if (this.fetching())
                    return;

                var self = this;
                this.fetching(true);

                getFutureSubscriptions(quote)
                    .always(function()
                    {
                        self.fetching(false);
                    })
                    .done(function (subscriptions)
                    {
                        try {
                            var data = JSON.parse(subscriptions);
                            window.checkoutConfig.payment.stripe_payments.futureSubscriptions = data;
                            self.futureSubscriptions(data);
                        } catch (e) {
                            console.warn('Could not retrieve future subscriptions details: ' + e.message);
                            self.futureSubscriptions(window.checkoutConfig.payment.stripe_payments.futureSubscriptions);
                        }
                    })
                    .fail(function (xhr, textStatus, errorThrown)
                    {
                        console.warn(console.warn('Could not retrieve future subscriptions details: ' + xhr.responseText));
                    });
            },

            getData: function(key)
            {
                var config = this.futureSubscriptions();

                if (config == null)
                    return '';

                if ((key in config))
                    return config[key];

                return '';
            },

            config: function()
            {
                return window.checkoutConfig.payment.stripe_payments;
            }
        });
    }
);
