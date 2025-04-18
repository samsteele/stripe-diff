<?php

namespace StripeIntegration\Payments\Commands\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @codeCoverageIgnore
 */
class OriginCheckCommand extends Command
{
    private $areaCodeFactory;

    public function __construct(
        \StripeIntegration\Payments\Helper\AreaCodeFactory $areaCodeFactory
    )
    {
        $this->areaCodeFactory = $areaCodeFactory;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:webhooks:origin-check');
        $this->setDescription('Enable or disable the Stripe webhooks signature check. For security, only disable this in development mode.');
        $this->addArgument('enabled', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $newValue = $input->getArgument("enabled");
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $this->areaCodeFactory->create();
        $areaCode->setAreaCode();

        $scopeConfig = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $config = $objectManager->get(\StripeIntegration\Payments\Model\Config::class);

        $currentValue = $scopeConfig->getValue("payment/stripe_payments/webhook_origin_check");

        if ($newValue != $currentValue)
        {
            if ($newValue == "1")
            {
                $config->enableOriginCheck();
                $output->writeln("Enabled Stripe webhooks origin check.");
            }
            else
            {
                $config->disableOriginCheck();
                if ($config->getMagentoMode() != "developer")
                {
                    $output->writeln("Temporarily disabled Stripe webhooks origin check. We will re-enable the setting automatically within the hour.");
                }
                else
                {
                    $output->writeln("Disabled Stripe webhooks origin check.");
                }
            }

            $config->clearCache("config");
        }
        else
        {
            $output->writeln("No change.");
        }

        return 0;
    }
}
