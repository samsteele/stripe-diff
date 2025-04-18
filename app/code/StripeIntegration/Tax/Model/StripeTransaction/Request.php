<?php

namespace StripeIntegration\Tax\Model\StripeTransaction;

use StripeIntegration\Tax\Helper\DateTime;

class Request
{
    public const CALCULATION_FIELD_NAME = 'calculation';
    public const REFERENCE_FIELD_NAME = 'reference';
    public const METADATA_FIELD_NAME = 'metadata';
    public const EXPAND_FIELD_NAME = 'expand';

    private $calculation;
    private $reference;
    private $metadata;
    private $expand;

    private $dateTimeHelper;

    public function __construct(
        DateTime $dateTimeHelper
    ) {
        $this->dateTimeHelper = $dateTimeHelper;
    }

    public function formData($invoice)
    {
        $this->calculation = $invoice->getStripeTaxCalculationId();
        $this->reference = sprintf('Invoice # %s_%s', $invoice->getIncrementId(), $this->dateTimeHelper->getTimestampInMilliseconds());
        $this->metadata = [
            'payment_transaction_id' => $invoice->getOrder()->getPayment()->getLastTransId(),
            'order_id' => $invoice->getOrder()->getIncrementId()
        ];
        $this->expand = ['line_items'];

        return $this;
    }

    public function toArray()
    {
        return [
            self::CALCULATION_FIELD_NAME => $this->calculation,
            self::REFERENCE_FIELD_NAME => $this->reference,
            self::METADATA_FIELD_NAME => $this->metadata,
            self::EXPAND_FIELD_NAME => $this->expand,
        ];
    }
}