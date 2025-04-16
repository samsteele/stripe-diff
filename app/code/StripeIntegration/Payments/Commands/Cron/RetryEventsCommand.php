<?php

namespace StripeIntegration\Payments\Commands\Cron;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use StripeIntegration\Payments\Exception\GenericException;

class RetryEventsCommand extends Command
{
    private $areaCodeFactory;
    private $webhookEventCollectionFactory;
    private $enabledEvents;

    public function __construct(
        \StripeIntegration\Payments\Helper\AreaCodeFactory $areaCodeFactory,
        \StripeIntegration\Payments\Model\Webhooks\EnabledEvents $enabledEvents,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory
    )
    {
        $this->areaCodeFactory = $areaCodeFactory;
        $this->enabledEvents = $enabledEvents;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:cron:retry-events');
        $this->setDescription('Retries webhook events of a specific type which have failed to be processed when they initially arrived.');
        $this->addArgument('type', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument("type");

        if (!in_array($type, $this->enabledEvents->getEvents()))
        {
            throw new GenericException("Invalid event type: $type");
        }

        $areaCode = $this->areaCodeFactory->create()->setAreaCode();
        $webhookEventCollection = $this->webhookEventCollectionFactory->create()->getAllFailedEventsOfType($type);

        foreach ($webhookEventCollection as $webhookEventModel)
        {
            try
            {
                $orderNumber = ($webhookEventModel->getOrderIncrementId() ? " (Order #" . $webhookEventModel->getOrderIncrementId() . ")" : "");
                $output->writeln("<info>Processing event " . $webhookEventModel->getEventId() . $orderNumber . "...</info>");
                $webhookEventModel->process();
                $webhookEventModel->refresh();

                if ($webhookEventModel->getLastErrorStatusCode())
                {
                    $output->writeln($webhookEventModel->getLastErrorStatusCode() . " " . $webhookEventModel->getLastError());
                }
            }
            catch (\Exception $e)
            {
                $output->writeln($e->getMessage());
                $webhookEventModel->setLastErrorFromException($e);
            }
        }

        return 0;
    }
}
