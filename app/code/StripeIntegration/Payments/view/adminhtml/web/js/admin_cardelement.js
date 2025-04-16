var billingDetails = null;
var cardElement = null;
var params = null;
var initializedElement = null;

var getBillingDetails = function()
{
    if (!order)
        return null;

    var data = order.serializeData(order.billingAddressContainer).toObject();

    var details = {
        address: {
            city: data["order[billing_address][city]"],
            country: data["order[billing_address][country_id]"],
            line1: data["order[billing_address][street][0]"],
            line2: data["order[billing_address][street][1]"],
            postal_code: data["order[billing_address][postcode]"],
            state: data["order[billing_address][region]"],
        },
        email: jQuery("#email").val(),
        name: data["order[billing_address][firstname]"] + " " + data["order[billing_address][lastname]"],
        phone: data["order[billing_address][telephone]"]
    };

    return details;
};

var disableCardValidation = function()
{
    // Disable server side card validation
    if (typeof AdminOrder != 'undefined' && AdminOrder.prototype.loadArea && typeof AdminOrder.prototype._loadArea == 'undefined')
    {
        AdminOrder.prototype._loadArea = AdminOrder.prototype.loadArea;
        AdminOrder.prototype.loadArea = function(area, indicator, params)
        {
          if (typeof area == "object" && area.indexOf('card_validation') >= 0)
            area = area.splice(area.indexOf('card_validation'), 0);

          if (area.length > 0)
            return this._loadArea(area, indicator, params);
        };
    }
};

define([
    'StripeIntegration_Payments/js/stripe',
    'jquery',
    'mage/translate',
    'domReady!',
    'mage/mage'
],
function(stripe, $, $t)
{
    var params = null;

    var hideError = function()
    {
        try
        {
            $('label.stripe.mage-error').hide();
        }
        catch (e)
        {

        }
    };

    var showError = function(message)
    {
        try
        {
            $('#edit_form').trigger('processStop');
            var errorContainer = $('label.stripe.mage-error')[0];
            $(errorContainer).text(message);
            errorContainer.show();
            $("#order-methods")[0].scrollIntoView({
                behavior: "smooth",
                block: "start"
            });
            return false;
        }
        catch (e)
        {
            console.warn(message);
        }
    };

    var onPaymentMethodChange = function()
    {
        hideError();

        if (this.id == 'new_card')
        {
            $("#new_card_container").show();
        }
        else
        {
            $("#new_card_container").hide();
        }
    };

    var onCardElementChange = function(event)
    {
        hideError();
    };

    var onSubmitOrder = function(e)
    {
        hideError();

        var $editForm = $('#edit_form');
        if (!$editForm.valid())
            return;

        if (!isStripeMethodSelected())
            return order._submit();

        var countSavedMethods = $('div.saved-payment-method-option').length;
        if (countSavedMethods > 0)
        {
            var selection = $('input[name="payment[payment_method]"]:checked');
            if (selection.length == 0)
            {
                return showError($t("Please select a payment method."));
            }

            if (selection[0].id != "new_card")
                return order._submit();
        }

        $('#edit_form').trigger('processStart');
        var options = {
            type: 'card',
            card: cardElement
        };

        var billingDetails = getBillingDetails();
        if (billingDetails)
            options.billing_details = billingDetails;

        stripe.stripeJs.createPaymentMethod(options)
        .then(function(result)
        {
            if (result.error)
            {
                return showError(result.error.message);
            }

            if (countSavedMethods > 0)
                selection[0].value = result.paymentMethod.id;
            else
                $('input[name="payment[payment_method]"]').val(result.paymentMethod.id);

            order._submit();
        });

        return false;
    };

    var isStripeMethodSelected = function()
    {
        var methods = $('[name^="payment["]');

        if (methods.length === 0)
            return false;

        var stripe = methods.filter(function(index, value)
        {
            if (value.id == "p_method_stripe_payments")
                return value;
        });

        if (stripe.length == 0)
            return false;

        return stripe[0].checked;
    };

    var getCardElementParams = function()
    {
        var cardElement = document.getElementById('stripe-card-element');
        var encodedParams = cardElement.getAttribute('data-init-params');

        try
        {
            var decodedParams = JSON.parse(encodedParams);
            return JSON.parse(decodedParams);
        }
        catch (e)
        {
            return null;
        }
    };

    var initCardElement = function(params)
    {
        // Check if #stripe-card-element is already present
        if ($('#stripe-card-element').length == 0)
        {
            return;
        }

        if ($('div.saved-payment-method-option').length == 0)
        {
            $("#new_card_container").show();
        }
        else
        {
            $('input[type=radio][name="payment[payment_method]"]').on('change', onPaymentMethodChange);
        }

        var elements = stripe.stripeJs.elements({
            locale: params.locale
        });

        cardElement = elements.create('card', params.cardElementOptions);
        cardElement.mount('#stripe-card-element');
        cardElement.on('change', onCardElementChange);

        initializedElement = document.getElementById('stripe-card-element');
    };

    var bindOrderSubmit = function()
    {
        if (typeof order == 'undefined')
            return false;

        if (typeof order._submit == "undefined")
        {
            order._submit = order.submit;
        }

        order.submit = onSubmitOrder;

        return true;
    };

    var init = function()
    {
        if (getCardElementParams())
        {
            params = getCardElementParams();
        }

        disableCardValidation();
        bindOrderSubmit();

        var dataForm = $('#payment_form_stripe_payments');
        dataForm.mage('validation', {});

        stripe.initStripe(params, function(err)
        {
            if (err)
            {
                console.error(err);
                return;
            }

            initCardElement(params);
        });
    };

    return function(config)
    {
        params = config;

        // Check if element is already in DOM and visible
        if (document.getElementById('stripe-card-element'))
        {
            init();
        }

        var mutationObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                // Check for added nodes
                if (mutation.addedNodes && mutation.addedNodes.length > 0) {
                    // Check if card element was directly added
                    for (var i = 0; i < mutation.addedNodes.length; i++) {
                        var node = mutation.addedNodes[i];
                        // Check if the node itself is the element
                        if (node.id === 'stripe-card-element' && initializedElement != node) {
                            init();
                            return;
                        }

                        // Check if it's an element node that might contain our target
                        if (node.nodeType === 1) {
                            var cardElement = node.querySelector('#stripe-card-element');
                            if (cardElement && initializedElement != cardElement) {
                                init();
                                return;
                            }
                        }
                    }
                }
            });
        });

        mutationObserver.observe(document.body, { childList: true, subtree: true });

        return config;
    };
});
