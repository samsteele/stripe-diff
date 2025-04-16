<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Order;

// Analyzes an order and keeps track of all discounts applied to it
class Discounts
{
    private $ruleRepository;
    private $invoiceItemHelper;
    private $discountHelper;
    private $order;
    private $allRules = [];
    private $itemRules = [];

    public function __construct(
        \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository,
        \StripeIntegration\Payments\Helper\Stripe\InvoiceItem $invoiceItemHelper,
        \StripeIntegration\Payments\Helper\Discount $discountHelper
    )
    {
        $this->ruleRepository = $ruleRepository;
        $this->invoiceItemHelper = $invoiceItemHelper;
        $this->discountHelper = $discountHelper;
    }

    public function getInvoiceApplicableOrderItems($order)
    {
        $items = [];

        foreach ($order->getAllItems() as $item)
        {
            if ($this->invoiceItemHelper->shouldIncludeOnInvoice($item))
            {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function fromOrder($order)
    {
        $this->order = $order;
        $this->allRules = [];
        $this->itemRules = [];

        // Rules applied on order items
        foreach ($this->getInvoiceApplicableOrderItems($order) as $item)
        {
            $discount = $item->getDiscountAmount();

            if ($discount == 0)
                continue;

            $ruleIds = $this->invoiceItemHelper->getOrderItemAppliedRuleIds($item);
            if (!empty($ruleIds))
            {
                $ruleIds = explode(',', $ruleIds);

                foreach ($ruleIds as $ruleId)
                {
                    $rule = $this->ruleRepository->getById($ruleId);
                    $discountData = $this->discountHelper->getDiscountData($item, $rule);
                    if ($discountData->getAmount() != 0)
                    {
                        $this->itemRules[$item->getProductId()][$ruleId] = $rule;
                    }
                    $this->allRules[$ruleId] = $rule;
                }
            }
        }

        return $this;
    }

    // Returns all rules which are applied to all items
    public function getAllItemsRules()
    {
        $items = $this->getInvoiceApplicableOrderItems($this->order);
        $rules = [];

        foreach ($this->allRules as $ruleId => $rule)
        {
            if (!$this->canApplyToWholeInvoice($rule->getSimpleAction()))
                continue;

            $appliesToAll = true;

            foreach ($items as $item)
            {
                if (!isset($this->itemRules[$item->getProductId()][$ruleId]))
                {
                    $appliesToAll = false;
                    break;
                }
            }

            if ($appliesToAll)
            {
                $rules[$ruleId] = $rule;
            }
        }

        return $rules;
    }

    // Returns all rules which are applied to specific items only
    public function getSpecificItemsRules()
    {
        $allItemRules = $this->getAllItemsRules();
        $items = $this->getInvoiceApplicableOrderItems($this->order);
        $rules = [];

        foreach ($this->allRules as $ruleId => $rule)
        {
            if (isset($allItemRules[$ruleId]))
                continue;

            foreach ($items as $item)
            {
                if (isset($this->itemRules[$item->getProductId()][$ruleId]))
                {
                    $rules[$item->getId()][$ruleId] = $rule;
                    break;
                }
            }
        }

        return $rules;
    }

    private function canApplyToWholeInvoice($ruleType)
    {
        return in_array($ruleType, ['by_percent', 'cart_fixed']);
    }
}