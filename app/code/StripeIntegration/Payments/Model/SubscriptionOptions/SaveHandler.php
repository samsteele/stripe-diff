<?php
namespace StripeIntegration\Payments\Model\SubscriptionOptions;

use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use StripeIntegration\Payments\Model\SubscriptionOptionsFactory as SubscriptionOptionsModelFactory;

class SaveHandler implements ExtensionInterface
{
    private SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory;
    private $productExtensionFactory;

    public function __construct(
        SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory,
        \Magento\Catalog\Api\Data\ProductExtensionFactory $productExtensionFactory
    ) {
        $this->subscriptionOptionsModelFactory = $subscriptionOptionsModelFactory;
        $this->productExtensionFactory = $productExtensionFactory;
    }

    /**
     * Save stripe subscription coupon expires values
     *
     * @param object $entity Entity
     * @param array<mixed> $arguments Arguments
     * @return bool|object
     * @throws \Exception
     */
    public function execute($entity, $arguments = [])
    {
        if (!empty($entity->getSubscriptionOptions()) && is_array($entity->getSubscriptionOptions()))
        {
            $input = $entity->getSubscriptionOptions();

            $subscriptionOptionsModel = $this->subscriptionOptionsModelFactory->create()->load($entity->getId());
            $subscriptionOptionsModel->setProductId($entity->getId());

            $subscriptionOptionsModel->addData($input);

            $subscriptionOptionsModel->save();

            $extensionAttributes = $entity->getExtensionAttributes() ?? $this->productExtensionFactory->create();
            if (method_exists($extensionAttributes, 'setSubscriptionOptions'))
            {
                $extensionAttributes->setSubscriptionOptions($subscriptionOptionsModel);
                $entity->setExtensionAttributes($extensionAttributes);
            }
        }

        return $entity;
    }
}
