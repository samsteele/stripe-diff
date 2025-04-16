<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model;

class Invoice
{
    private $transactions = [];
    private $transactionSearchResultFactory;
    private $subscriptionProductFactory;

    public function __construct(
        \Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactionSearchResultFactory,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory
    )
    {
        $this->transactionSearchResultFactory = $transactionSearchResultFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
    }

    public function getTransactions($order)
    {
        if (isset($this->transactions[$order->getId()]))
            return $this->transactions[$order->getId()];

        $transactions = $this->transactionSearchResultFactory->create()->addOrderIdFilter($order->getId());
        return $this->transactions[$order->getId()] = $transactions;
    }

    public function hasSubscriptions($subject)
    {
        $items = $subject->getAllItems();

        foreach ($items as $item)
        {
            if (!$item->getProductId())
                continue;

            if ($this->subscriptionProductFactory->create()->fromProductId($item->getProductId())->isSubscriptionProduct())
                return true;
        }

        return false;
    }
}
