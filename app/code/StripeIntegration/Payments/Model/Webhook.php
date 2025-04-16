<?php

namespace StripeIntegration\Payments\Model;

class Webhook extends \Magento\Framework\Model\AbstractModel
{
    private $compare;
    private $storeManager;
    private $webhooksHelper;
    private $enabledEvents;

    public function __construct(
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \StripeIntegration\Payments\Model\Webhooks\EnabledEvents $enabledEvents,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook $resource,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $resourceCollection,
        array $data = []
    )
    {
        $this->compare = $compare;
        $this->storeManager = $storeManager;
        $this->webhooksHelper = $webhooksHelper;
        $this->enabledEvents = $enabledEvents;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Webhook');
    }

    public function pong()
    {
        $this->setLastEvent(time());
        return $this;
    }

    public function activate()
    {
        $this->setActive(1);
        return $this;
    }

    public function isOutdated()
    {
        if ($this->getConfigVersion() != \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION) {
            return true;
        }

        $urls = $this->getAllStoreURLs();
        if (!in_array($this->getUrl(), $urls)) {
            return true;
        }

        $enabledEvents = json_decode($this->getEnabledEvents(), true);
        if (!$this->compare->areArrayValuesTheSame($enabledEvents, $this->enabledEvents->getEvents())) {
            return true;
        }

        return false;
    }

    protected function getAllStoreURLs()
    {
        $urls = [];
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            $url = $this->webhooksHelper->getValidWebhookUrl($store);

            if ($url) {
                $urls[$url] = $url;
            }
        }

        return $urls;
    }
}
