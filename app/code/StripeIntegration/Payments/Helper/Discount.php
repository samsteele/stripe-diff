<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class Discount
{
    private $couponCollection;
    private $quoteHelper;
    private $discountDataFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\ResourceModel\Coupon\Collection $couponCollection,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \Magento\SalesRule\Model\Rule\Action\Discount\DataFactory $discountDataFactory
    )
    {
        $this->couponCollection = $couponCollection;
        $this->quoteHelper = $quoteHelper;
        $this->discountDataFactory = $discountDataFactory;
    }

    public function getDiscountRules(?string $appliedRuleIds): array
    {
        $foundRules = [];

        if (empty($appliedRuleIds))
            return $foundRules;

        $appliedRuleIds = explode(",", $appliedRuleIds);

        foreach ($appliedRuleIds as $ruleId)
        {
            $discountRule = $this->couponCollection->getByRuleId($ruleId);
            if ($discountRule)
                $foundRules[] = $discountRule;
        }

        return $foundRules;
    }

    public function getDiscountData($orderItem, $rule)
    {
        $item = $this->quoteHelper->getQuoteItemFromProductId($orderItem->getProductId());
        if ($item->getExtensionAttributes())
        {
            $discountRules = $item->getExtensionAttributes()->getDiscounts();
            if (is_array($discountRules))
            {
                foreach ($discountRules as $discountRule)
                {
                    if ($discountRule->getRuleId() == $rule->getRuleId())
                    {
                        return $discountRule->getDiscountData();
                    }
                }
            }
        }

        return $this->discountDataFactory->create();
    }
}