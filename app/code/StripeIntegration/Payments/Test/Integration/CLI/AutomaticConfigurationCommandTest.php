<?php
namespace StripeIntegration\Payments\Test\Integration\CLI;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterfaceFactory;
use Symfony\Component\Console\Input\ArgvInputFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Magento\Framework\App\Config\ReinitableConfigInterface;

class AutomaticConfigurationCommandTest extends TestCase
{
    private $objectManager;
    private $scopeConfigInterface;
    private $command;
    private $webhooksSetup;

    protected function setUp(): void
    {
        /** @var \Magento\TestFramework\ObjectManager $objectManager */
        $objectManager = $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->scopeConfigInterface = $this->objectManager->get(ScopeConfigInterfaceFactory::class);
        $this->command = $this->objectManager->get(\StripeIntegration\Payments\Commands\Webhooks\AutomaticConfigurationCommand::class);
        $this->webhooksSetup = $this->objectManager->get(\StripeIntegration\Payments\Helper\WebhooksSetup::class);

        // Make sure that webhooks reconfiguration is needed
        $enabledEventsMock = $this->createMock(\StripeIntegration\Payments\Model\Webhooks\EnabledEvents::class);
        $enabledEventsMock->method('getEvents')->willReturn([]);
        $objectManager->addSharedInstance($enabledEventsMock, \StripeIntegration\Payments\Model\Webhooks\EnabledEvents::class);
    }

    /**
     * It's important to run this test last, because the configuration value persists between tests
     *
     * @depends testDisableAutomaticConfiguration
     */
    public function testEnableAutomaticConfiguration()
    {
        $inputFactory = $this->objectManager->get(ArgvInputFactory::class);
        $input = $inputFactory->create(["argv" => [null, "1"]]);
        $output = new BufferedOutput();
        $exitCode = $this->command->run($input, $output);

        $this->objectManager->get(ReinitableConfigInterface::class)->reinit();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals("1", $this->scopeConfigInterface->create()
            ->getValue("stripe_settings/automatic_webhooks_configuration", "default", 0));
        $this->assertStringContainsString("Enabled automatic webhooks configuration.", $output->fetch());
        $this->assertTrue($this->webhooksSetup->isConfigureNeeded());
    }

    public function testDisableAutomaticConfiguration()
    {
        $inputFactory = $this->objectManager->get(ArgvInputFactory::class);
        $input = $inputFactory->create(["argv" => [null, "0"]]);
        $output = new BufferedOutput();
        $exitCode = $this->command->run($input, $output);

        $this->objectManager->get(ReinitableConfigInterface::class)->reinit();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals("0", $this->scopeConfigInterface->create()
            ->getValue("stripe_settings/automatic_webhooks_configuration", "default", 0));
        $this->assertStringContainsString("Disabled automatic webhooks configuration.", $output->fetch());
        $this->assertFalse($this->webhooksSetup->isConfigureNeeded());
    }
}
