<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper\Stripe;

class Invoice
{
    public function getStripeInvoiceParams($magentoInvoice)
    {
        return [
            "number" => $magentoInvoice->getIncrementId()
        ];
    }
}