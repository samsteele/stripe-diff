<?php

namespace StripeIntegration\Tax\Test\Integration\Adminarea\TaxExemptions;

use Magento\Framework\Event\ManagerInterface;
use StripeIntegration\Tax\Exceptions\TaxExemptionsException;
use StripeIntegration\Tax\Helper\Config;

class ConfigSaveTaxExemptionsChangedTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $eventManager;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->eventManager = $this->objectManager->get(ManagerInterface::class);
    }

    /**
     * @magentoConfigFixture tax/stripe_tax_exemptions/tax_exempt_customer_groups 1
     * @magentoConfigFixture tax/stripe_tax_exemptions/reverse_charge_customer_groups 1
     */
    public function testObserver()
    {
        $configPaths = [
            Config::STRIPE_TAX_TAX_EXEMPT_GROUPS_PATH
        ];
        $section = 'tax';

        $eventData['section'] = $section;
        $eventData['changed_paths'] = $configPaths;

        $this->expectException(TaxExemptionsException::class);
        $this->expectExceptionMessage("Tax Exempt Customer Groups cannot have a common group Reverse Charge Customer Groups.");
        $this->eventManager->dispatch("admin_system_config_changed_section_{$section}", $eventData);
    }
}