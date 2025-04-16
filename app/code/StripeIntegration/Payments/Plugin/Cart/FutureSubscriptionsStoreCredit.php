<?php

namespace StripeIntegration\Payments\Plugin\Cart;

use Magento\CustomerBalance\Model\BalanceManagement;
use StripeIntegration\Payments\Helper\Subscriptions;

class FutureSubscriptionsStoreCredit
{
    private $subscriptionsHelper;

    public function __construct(
        Subscriptions $subscriptionsHelper
    )
    {
        $this->subscriptionsHelper = $subscriptionsHelper;
    }

    public function beforeApply(
        BalanceManagement $subject,
        $cartId
    ) {
        $this->subscriptionsHelper->checkCustomerBalancesAvailability('store credit');

        return [$cartId];
    }
}