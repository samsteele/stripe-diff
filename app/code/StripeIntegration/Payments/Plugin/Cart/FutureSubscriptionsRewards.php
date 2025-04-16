<?php

namespace StripeIntegration\Payments\Plugin\Cart;

use Magento\Reward\Model\RewardManagement;
use StripeIntegration\Payments\Helper\Subscriptions;

class FutureSubscriptionsRewards
{
    private $subscriptionsHelper;

    public function __construct(
        Subscriptions $subscriptionsHelper
    )
    {
        $this->subscriptionsHelper = $subscriptionsHelper;
    }

    public function beforeSet(
        RewardManagement $subject,
        $cartId
    ) {
        $this->subscriptionsHelper->checkCustomerBalancesAvailability('reward points');

        return [$cartId];
    }
}