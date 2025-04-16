<?php

namespace StripeIntegration\Tax\Model\StripeTax\Request;

class CustomerDetails
{
    public const ADDRESS_KEY = 'address';
    public const ADDRESS_SOURCE_KEY = 'address_source';
    public const IP_ADDRESS_KEY = 'ip_address';
    public const TAX_IDS_KEY = 'tax_ids';
    public const TAXABILITY_OVERRIDE_KEY = 'taxability_override';

    private $address;
    private $addressSource;
    private $ipAddress;
    private $taxIds;
    private $taxabilityOverride;

    private $customerDetailsHelper;

    public function __construct(
        \StripeIntegration\Tax\Helper\CustomerDetails $customerDetailsHelper
    )
    {
        $this->customerDetailsHelper = $customerDetailsHelper;
    }

    public function formData($address, $quote)
    {
        $addressForApi = $this->customerDetailsHelper->getAddressFromShippingAssignment($address);
        if (!$addressForApi && $address->getCustomerId()) {
            $addressForApi = $this->customerDetailsHelper->getAddressFromDefaultAddresses($address->getCustomerId(), $quote);
        }

        if ($addressForApi) {
            $this->address = $addressForApi['data'];
            $this->addressSource = $addressForApi['source'];
        } else {
            $this->ipAddress = $this->customerDetailsHelper->getCurrentUserIp();
        }
        $this->taxabilityOverride = $this->customerDetailsHelper->getTaxabilityOverride($quote);
    }

    public function formDataForInvoiceTax($order)
    {
        if ($order->getIsVirtual()) {
            $addressForApi = $this->customerDetailsHelper->getAddressFromOrderAddress($order->getBillingAddress());
        } else {
            $addressForApi = $this->customerDetailsHelper->getAddressFromOrderAddress($order->getShippingAddress());
        }

        $this->address = $addressForApi['data'];
        $this->addressSource = $addressForApi['source'];
        $this->taxabilityOverride = $this->customerDetailsHelper->getTaxabilityOverride($order);
    }

    public function toArray()
    {
        $customerDetails = [];
        if ($this->address) {
            $customerDetails[self::ADDRESS_KEY] = $this->address;
            $customerDetails[self::ADDRESS_SOURCE_KEY] = $this->addressSource;
        } else {
            $customerDetails[self::IP_ADDRESS_KEY] = $this->ipAddress;
        }
        $customerDetails[self::TAXABILITY_OVERRIDE_KEY] = $this->taxabilityOverride;

        return $customerDetails;
    }

    public function getIpAddress()
    {
        return $this->ipAddress;
    }
}