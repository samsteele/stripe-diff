/*browser:true*/
/*global define*/
define(
    [
        'ko',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Customer/js/customer-data',
        'StripeIntegration_Payments/js/stripe',
        'StripeIntegration_Payments/js/action/get-checkout-methods',
        'StripeIntegration_Payments/js/action/get-checkout-session-id',
        'Magento_Checkout/js/view/payment/default',
        'mage/translate',
        'stripejs',
        'domReady!'
    ],
    function (
        ko,
        $,
        quote,
        additionalValidators,
        placeOrderAction,
        fullScreenLoader,
        customerData,
        stripe,
        getCheckoutMethods,
        getCheckoutSessionId,
        Component,
        $t
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                self: this,
                template: 'StripeIntegration_Payments/payment/checkout',
                code: "stripe_checkout",
                customRedirect: true,
                guestEmail: null,
                methodIcons: ko.observableArray([])
            },
            redirectAfterPlaceOrder: false,

            initObservable: function()
            {
                this._super().observe([
                    'methodIcons',
                    'permanentError'
                ]);

                var params = window.checkoutConfig.payment.stripe_payments.initParams;

                stripe.initStripe(params);

                var self = this;
                var currentTotals = quote.totals();
                var currentBillingAddress = quote.billingAddress();
                var currentShippingAddress = quote.shippingAddress();
                this.guestEmail = quote.guestEmail;

                getCheckoutMethods(quote, self.setPaymentMethods.bind(self));

                quote.billingAddress.subscribe(function(address)
                {
                    if (!address)
                        return;

                    if (self.isAddressSame(address, currentBillingAddress))
                        return;

                    currentBillingAddress = address;

                    getCheckoutMethods(quote, self.setPaymentMethods.bind(self));
                }, this);

                quote.shippingAddress.subscribe(function(address)
                {
                    if (!address)
                        return;

                    if (self.isAddressSame(address, currentShippingAddress))
                        return;

                    currentShippingAddress = address;

                    getCheckoutMethods(quote, self.setPaymentMethods.bind(self));
                }, this);

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(currentTotals.total_segments))
                        return;

                    currentTotals = totals;

                    getCheckoutMethods(quote, self.setPaymentMethods.bind(self));
                }, this);

                return this;
            },

            isAddressSame: function(address1, address2)
            {
                var a = this.stringifyAddress(address1);
                var b = this.stringifyAddress(address2);

                return a == b;
            },

            stringifyAddress: function(address)
            {
                if (typeof address == "undefined" || !address)
                    return null;

                return JSON.stringify({
                    "countryId": (typeof address.countryId != "undefined") ? address.countryId : "",
                    "region": (typeof address.region != "undefined") ? address.region : "",
                    "city": (typeof address.city != "undefined") ? address.city : "",
                    "postcode": (typeof address.postcode != "undefined") ? address.postcode : ""
                });
            },

            setPaymentMethods: function(response)
            {
                var methods = [];

                if (typeof response == "string")
                    response = JSON.parse(response);

                if (typeof response.error != "undefined")
                {
                    this.permanentError(response.error);
                }

                if (typeof response.methods != "undefined" && response.methods.length > 0)
                    methods = response.methods;

                var icons = window.checkoutConfig.payment.stripe_payments.icons;
                var self = this;

                methods.forEach(function(method)
                {
                    if (self.hasPaymentMethod(icons, method))
                        return;

                    if (typeof window.checkoutConfig.payment.stripe_payments.pmIcons[method] != "undefined")
                    {
                        icons.push({
                            "code": method,
                            "path": window.checkoutConfig.payment.stripe_payments.pmIcons[method].icon,
                            "name": window.checkoutConfig.payment.stripe_payments.pmIcons[method].name
                        });
                    }
                    else if (method != "card")
                    {
                        icons.push({
                            "code": method,
                            "path": window.checkoutConfig.payment.stripe_payments.pmIcons.bank.icon,
                            "name": self.methodName(method)
                        });
                    }
                });

                this.methodIcons(icons);
            },

            hasPaymentMethod: function(collection, code)
            {
                var exists = collection.filter(function (o)
                {
                  return o.hasOwnProperty("code") && o.code == code;
                }).length > 0;

                return exists;
            },

            performRedirect: function()
            {
                var self = this;

                getCheckoutSessionId().then(function (response)
                {
                    if (response && response.length && response.indexOf("cs_") === 0)
                    {
                        self.redirect.bind(self)(response);
                    }
                    else
                    {
                        self.showError($t('Could not redirect to Stripe Checkout.'));
                    }
                },
                function (e)
                {
                    if (e.responseJSON && e.responseJSON.message)
                    {
                        self.showError(e.responseJSON.message);
                    }
                    else
                    {
                        self.showError($t('An error has occurred on the server. Could not redirect to Stripe Checkout.'));
                    }
                    console.error(e.responseJSON.message);
                });
            },

            checkoutPlaceOrder: function()
            {
                var self = this;

                if (additionalValidators.validate())
                {
                    fullScreenLoader.startLoader();
                    getCheckoutSessionId().then(function (response)
                    {
                        if (response && response.length && response.indexOf("cs_") === 0)
                        {
                            self.redirect.bind(self)(response);
                        }
                        else
                        {
                            self.placeOrder.bind(self)(self.performRedirect.bind(self));
                        }
                    },
                    function (e)
                    {
                        if (e.responseJSON && e.responseJSON.message)
                        {
                            self.showError(e.responseJSON.message);
                        }
                        else
                        {
                            self.showError($t('An error has occurred on the server. Could not redirect to Stripe Checkout.'));
                        }
                        console.error(e.responseJSON.message);
                    });
                }

                return false;
            },

            placeOrder: function()
            {
                var self = this;

                placeOrderAction(self.getData(), self.messageContainer)
                .then(
                    this.performRedirect.bind(this),
                    function (e)
                    {
                        if (e.responseJSON && e.responseJSON.message)
                        {
                            self.showError(e.responseJSON.message);
                        }
                        else
                        {
                            self.showError($t('An error has occurred. Could not redirect to Stripe Checkout.'));
                        }
                        console.error(e.responseJSON.message);
                    }
                );

                return false;
            },

            redirectToURL: function(url)
            {
                try
                {
                    customerData.invalidate(['cart']);
                    $.mage.redirect(url);
                }
                catch (e)
                {
                    console.error(e);
                }
            },

            redirect: function(sessionId)
            {
                try
                {
                    customerData.invalidate(['cart']);
                    stripe.stripeJs.redirectToCheckout({ sessionId: sessionId }, this.onRedirectFailure.bind(this));
                }
                catch (e)
                {
                    fullScreenLoader.stopLoader();
                    console.error(e);
                }
            },

            onRedirectFailure: function(result)
            {
                if (result.error)
                {
                    this.showError(result.error.message);
                }
                else
                {
                    this.showError($("A redirect error has occurred."));
                    console.error(result);
                }
            },

            methodName: function(code)
            {
                if (typeof code == 'undefined')
                    return '';

                return code.charAt(0).toUpperCase() + Array.from(code).splice(1).join('');
            },

            showError: function(message)
            {
                fullScreenLoader.stopLoader();
                document.getElementById('stripe-checkout-actions-toolbar').scrollIntoView(true);
                this.messageContainer.addErrorMessage({ "message": message });
            },
        });
    }
);
