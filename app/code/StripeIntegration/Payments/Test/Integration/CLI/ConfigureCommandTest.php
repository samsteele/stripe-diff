<?php
namespace StripeIntegration\Payments\Test\Integration\CLI;

use PHPUnit\Framework\TestCase;
use Magento\TestFramework\ObjectManager;
use Symfony\Component\Console\Input\ArgvInputFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigureCommandTest extends TestCase
{
    private $objectManager;
    private $webhooksSetup;
    private $configureCommand;
    private $webhookResource;

    protected function setUp(): void
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->webhooksSetup = $this->objectManager->get(\StripeIntegration\Payments\Helper\WebhooksSetup::class);
        $this->configureCommand = $this->objectManager->get(\StripeIntegration\Payments\Commands\Webhooks\ConfigureCommand::class);
        $this->webhookResource = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\Webhook::class);
    }

    private function executeCommand($interactive = false)
    {
        if ($interactive)
        {
            // Use CommandTester for interactive mode
            $commandTester = new CommandTester($this->configureCommand);

            // Provide inputs that will be used when the command asks questions
            // This simulates pressing Enter or selecting the first option
            $commandTester->setInputs(['0']);

            $commandTester->execute(['--interactive' => true]);

            return [
                'exitCode' => $commandTester->getStatusCode(),
                'output' => $commandTester->getDisplay()
            ];
        }
        else
        {
            // Non-interactive mode remains the same
            $args = ['argv' => [null]];
            $input = $this->objectManager->get(ArgvInputFactory::class)->create($args);
            $output = new BufferedOutput();

            return [
                'exitCode' => $this->configureCommand->run($input, $output),
                'output' => $output->fetch()
            ];
        }
    }

    private function clearWebhooks()
    {
        $connection = $this->webhookResource->getConnection();
        $tableName = $this->webhookResource->getMainTable();
        $connection->delete($tableName);
    }

    public function testAutomaticWebhooksConfigure()
    {
        $this->clearWebhooks();
        $this->assertTrue($this->webhooksSetup->isConfigureNeeded());

        $result = $this->executeCommand(false);

        $this->assertEquals(0, $result['exitCode']);
        $this->assertStringContainsString('Configured webhook endpoint', $result['output']);
        $this->assertFalse($this->webhooksSetup->isConfigureNeeded());

        // Test that running it again doesn't create duplicate endpoints
        $result = $this->executeCommand(false);
        $this->assertEquals(0, $result['exitCode']);

        return true;
    }

    /**
     * @depends testAutomaticWebhooksConfigure
     */
    public function testInteractiveWebhooksConfigure()
    {
        $this->clearWebhooks();
        $this->assertTrue($this->webhooksSetup->isConfigureNeeded());

        $result = $this->executeCommand(true);

        $this->assertEquals(0, $result['exitCode']);
        $this->assertStringContainsString('Configured webhook endpoint', $result['output']);
        $this->assertFalse($this->webhooksSetup->isConfigureNeeded());
    }
}
