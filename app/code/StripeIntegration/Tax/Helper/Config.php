<?php

namespace StripeIntegration\Tax\Helper;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use \Magento\Tax\Model\Config as CoreTaxConfig;

class Config
{
    public const STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH = 'tax/stripe_tax_exemptions/tax_exempt_customer_groups';
    public const STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH = 'tax/stripe_tax_exemptions/reverse_charge_customer_groups';
    private $scopeConfig;
    private $resourceConfig;
    private $storeHelper;
    private $encryptor;
    private $coreTaxConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConfig $resourceConfig,
        Store $storeHelper,
        EncryptorInterface $encryptor,
        CoreTaxConfig $coreTaxConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->storeHelper = $storeHelper;
        $this->encryptor = $encryptor;
        $this->coreTaxConfig = $coreTaxConfig;
    }

    public function getConfigData($field, $storeId = null)
    {
        if (empty($storeId)) {
            $storeId = $this->storeHelper->getCurrentStore()->getId();
        }
        return $this->scopeConfig->getValue("tax/stripe_tax/$field", ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getTaxConfigData($group, $field, $storeId = null)
    {
        if (empty($storeId)) {
            $storeId = $this->storeHelper->getCurrentStore()->getId();
        }
        return $this->scopeConfig->getValue("tax/{$group}/{$field}", ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getCoreTaxConfig()
    {
        return $this->coreTaxConfig;
    }

    public function getIsEnabled($storeId = null)
    {
        return $this->getConfigData('enabled', $storeId);
    }

    public function getStripeMode($storeId = null)
    {
        return $this->getConfigData('stripe_mode', $storeId);
    }

    public function getSecretKey($mode = null, $storeId = null)
    {
        if (!$mode) {
            $mode = $this->getStripeMode($storeId);
        }
        $key = $this->getConfigData("stripe_{$mode}_sk", $storeId);

        return $this->decrypt($key);
    }

    public function getPublishableKey($mode = null, $storeId = null)
    {
        if (!$mode) {
            $mode = $this->getStripeMode($storeId);
        }

        return $this->getConfigData("stripe_{$mode}_pk", $storeId);
    }

    public function setConfigData($path, $value, $scope, $storeId)
    {
        return $this->resourceConfig->saveConfig($path, $value, $scope, $storeId);
    }

    public function decrypt($key)
    {
        if (empty($key))
            return null;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $key))
            $key = $this->encryptor->decrypt($key);

        if (empty($key))
            return null;

        return trim($key);
    }

    public function getStripeTaxExemptionGroups($storeId = null)
    {
        $configValue = $this->getTaxConfigData('stripe_tax_exemptions', 'tax_exempt_customer_groups', $storeId);

        return $this->getConfigArrayFromStringValue($configValue);
    }

    public function getStripeTaxReverseChargeGroups($storeId = null)
    {
        $configValue = $this->getTaxConfigData('stripe_tax_exemptions', 'reverse_charge_customer_groups', $storeId);

        return $this->getConfigArrayFromStringValue($configValue);
    }

    /**
     * Returns an array of values for multiselect settings.
     *
     * @return array
     */
    public function getConfigArrayFromStringValue($value)
    {
        if ($value !== null) {
            return explode(',', $value);
        }

        return $value;
    }
}