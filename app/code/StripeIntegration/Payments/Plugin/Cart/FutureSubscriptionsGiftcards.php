<?php

namespace StripeIntegration\Payments\Plugin\Cart;

use Magento\GiftCardAccount\Model\Service\GiftCardAccountManagement;
use StripeIntegration\Payments\Helper\Subscriptions;

class FutureSubscriptionsGiftcards
{
    private $subscriptionsHelper;

    public function __construct(
        Subscriptions $subscriptionsHelper
    )
    {
        $this->subscriptionsHelper = $subscriptionsHelper;
    }

    public function beforeSaveByQuoteId(
        GiftCardAccountManagement $subject,
        $cartId,
        \Magento\GiftCardAccount\Api\Data\GiftCardAccountInterface $giftCardAccountData
    ) {
        $this->subscriptionsHelper->checkCustomerBalancesAvailability('gift cards');

        return [$cartId, $giftCardAccountData];
    }
}