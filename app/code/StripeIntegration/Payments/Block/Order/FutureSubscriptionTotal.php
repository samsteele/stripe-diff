<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Block\Order;

class FutureSubscriptionTotal extends \Magento\Sales\Block\Order\Totals
{
    private $subscriptionCollection;
    private $subscriptionModel;
    private $currencyHelper;
    private $colspan = 4;

    public function __construct(
        \StripeIntegration\Payments\Model\ResourceModel\Subscription\Collection $subscriptionCollection,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $registry, $data);

        $this->subscriptionCollection = $subscriptionCollection;
        $this->currencyHelper = $currencyHelper;

        if (!empty($data['colspan']))
        {
            $this->colspan = $data['colspan'];
        }
    }

    public function shouldRender(): bool
    {
        $order = $this->getOrder();
        if (!$order)
        {
            return false;
        }

        $payment = $order->getPayment();
        if (!$payment)
        {
            return false;
        }

        $subscriptionModel = $this->subscriptionCollection->getByOrderIncrementId($order->getIncrementId());
        if ($subscriptionModel->getStartDate() || $subscriptionModel->getTrialEnd())
        {
            $this->subscriptionModel = $subscriptionModel;
            return true;
        }

        return false;
    }

    public function getStartDateLabel()
    {
        $startDate = $this->subscriptionModel->getStartDate();
        $trialEnd = $this->subscriptionModel->getTrialEnd();

        if ($trialEnd)
        {
            $trialEnd = date("F jS", strtotime($trialEnd));
            return __("Trialing until %1", $trialEnd);
        }
        else if ($startDate)
        {
            $startDate = date("F jS", strtotime($startDate));
            return __("Starting on %1", $startDate);
        }

        return "";
    }

    public function getFrequencyLabel()
    {
        $interval = $this->subscriptionModel->getPlanInterval();
        $count = $this->subscriptionModel->getPlanIntervalCount();

        if ($count > 1)
            return __("/ %1 %2", $count, __($interval . "s"));
        else
            return __("/ %1", __($interval));
    }

    public function getFormattedAmount()
    {
        $stripeAmount = $this->subscriptionModel->getPlanAmount();
        $currency = $this->subscriptionModel->getCurrency();
        return $this->currencyHelper->formatStripePrice($stripeAmount, $currency);
    }

    public function getColspan()
    {
        return $this->colspan;
    }
}
