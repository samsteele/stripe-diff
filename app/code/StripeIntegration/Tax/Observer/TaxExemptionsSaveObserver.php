<?php

namespace StripeIntegration\Tax\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use StripeIntegration\Tax\Exceptions\TaxExemptionsException;
use StripeIntegration\Tax\Helper\Config;
use \Magento\Framework\App\Config\Storage\WriterInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;

class TaxExemptionsSaveObserver implements ObserverInterface
{
    private $scopeConfig;
    private $configWriter;
    private $configHelper;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        Config $configHelper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->configHelper = $configHelper;
    }

    public function execute(Observer $observer)
    {
        list($scope, $scopeId) = $this->getScopeAndId($observer);
        $changedPaths = $observer->getChangedPaths();

        if (in_array(Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH, $changedPaths) && in_array(Config::STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH, $changedPaths)) {
            if ($this->isCommonGroupSelected($scope, $scopeId)) {
                $this->configWriter->delete(Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH, $scope, $scopeId);
                $this->configWriter->delete(Config::STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH, $scope, $scopeId);
                throw new TaxExemptionsException(__('Tax Exempt Customer Groups and Reverse Charge Customer Groups cannot have common group selected.'));
            }
        } elseif (in_array(Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH, $changedPaths)) {
            if ($this->isCommonGroupSelected($scope, $scopeId)) {
                $this->configWriter->delete(Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH, $scope, $scopeId);
                throw new TaxExemptionsException(__('Tax Exempt Customer Groups cannot have a common group Reverse Charge Customer Groups.'));
            }
        } elseif (in_array(Config::STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH, $changedPaths)) {
            if ($this->isCommonGroupSelected($scope, $scopeId)) {
                $this->configWriter->delete(Config::STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH, $scope, $scopeId);
                throw new TaxExemptionsException(__('Tax Reverse Charge Customer Groups cannot have a common group Exempt Customer Groups.'));
            }
        }

        return $this;
    }

    private function isCommonGroupSelected($scope, $scopeId)
    {
        $exemptGroupsArray = $this->configHelper->getConfigArrayFromStringValue(
            $this->scopeConfig->getValue(Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH, $scope, $scopeId)
        );
        $reverseChargesArray = $this->configHelper->getConfigArrayFromStringValue(
            $this->scopeConfig->getValue(Config::STRIPE_TAX_REVERSE_CHARGE_GROUPS_PATH, $scope, $scopeId)
        );

        if (!is_array($exemptGroupsArray) || !is_array($reverseChargesArray)) {
            return false;
        }

        if (array_intersect($exemptGroupsArray, $reverseChargesArray)) {
            return true;
        }

        return false;
    }

    private function getScopeAndId($observer)
    {
        // Determine the scope and scope ID based on the request parameters
        if ($storeId = $observer->getStore()) {
            $scope = ScopeInterface::SCOPE_STORES;
            $scopeId = $storeId;
        } elseif ($websiteId = $observer->getWebsite()) {
            $scope = ScopeInterface::SCOPE_WEBSITES;
            $scopeId = $websiteId;
        } else {
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeId = 0;
        }

        return [$scope, $scopeId];
    }
}