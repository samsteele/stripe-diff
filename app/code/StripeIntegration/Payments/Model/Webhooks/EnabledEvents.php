<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Webhooks;

class EnabledEvents
{
    public function getEvents()
    {
        return [
            "charge.captured",
            "charge.refunded",
            "charge.succeeded",
            "checkout.session.expired",
            "checkout.session.completed",
            "customer.subscription.created",
            "customer.subscription.updated",
            "customer.subscription.deleted",
            "invoice.upcoming",
            "payment_intent.succeeded",
            "payment_intent.canceled",
            "payment_intent.partially_funded",
            "payment_intent.processing",
            "payment_intent.payment_failed",
            "payment_method.attached",
            "review.closed",
            "setup_intent.succeeded",
            "setup_intent.canceled",
            "setup_intent.setup_failed",
            "invoice.paid",
            "invoice.payment_succeeded",
            "invoice.payment_failed",
            "invoice.voided",
            "product.created" // This is a dummy event for setting up webhooks
        ];
    }
}