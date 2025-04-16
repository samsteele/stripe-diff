<?php

namespace StripeIntegration\Payments\Model\Stripe;

use Magento\Framework\Exception\LocalizedException;

class Coupon
{
    use StripeObjectTrait;

    private $objectSpace = 'coupons';
    public $rule = null;
    private $helper;
    private $currencyHelper;
    private $convert;
    private $discountHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\Discount $discountHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->helper = $helper;
        $this->currencyHelper = $currencyHelper;
        $this->convert = $convert;
        $this->discountHelper = $discountHelper;
    }

    public function fromSubscriptionProfile($profile)
    {
        $currency = $profile['currency'];
        $amount = $profile['discount_amount_magento'];
        $ruleId = $profile['expiring_coupon']['rule_id'] ?? null;
        $hasTrial = $profile['trial_end'] || $profile['trial_days'];
        $data = $this->getSubscriptionCouponParams($amount, $currency, $ruleId, $hasTrial);

        if (!$data)
            return $this;

        $this->getObject($data['id']);

        if (!$this->getStripeObject())
        {
            $this->createObject($data);
        }

        if (!$this->getStripeObject())
        {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("The discount could not be created in Stripe: %1", $this->getLastError())
            );
        }

        return $this;
    }

    public function fromOrderItem($orderItem, $order, $ruleId)
    {
        if ($orderItem->getDiscountAmount() == 0)
        {
            return $this;
        }

        $currency = $order->getOrderCurrencyCode();
        $data = $this->getOrderItemCouponParams($orderItem, $order, $currency, $ruleId);

        if (!$data)
        {
            return $this;
        }

        $this->getObject($data['id']);

        if (!$this->getStripeObject())
        {
            $this->createObject($data);
        }

        if (!$this->getStripeObject())
        {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("The discount could not be created in Stripe: %1", $this->getLastError())
            );
        }

        if ($this->getStripeObject()->name != $data['name'])
        {
            $this->update([
                'name' => $data['name']
            ]);
        }

        return $this;
    }

    // Should only be called if the rule applies to the whole order
    public function fromRule($rule, $order)
    {
        $data = $this->getOrderCouponParams($order, $rule);

        if (!$data)
        {
            return $this;
        }

        $this->getObject($data['id']);

        if (!$this->getStripeObject())
        {
            $this->createObject($data);
        }

        if (!$this->getStripeObject())
        {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("The discount could not be created in Stripe: %1", $this->getLastError())
            );
        }

        if ($this->getStripeObject()->name != $data['name'])
        {
            $this->update([
                'name' => $data['name']
            ]);
        }

        return $this;
    }

    public function fromFixedAmount($amount, $currency, $name, $couponGroup)
    {
        if (empty($amount))
        {
            throw new LocalizedException(__("Invalid discount amount: %1", $amount));
        }

        if (empty($currency))
        {
            throw new LocalizedException(__("Invalid discount currency: %1", $currency));
        }

        if (empty($name))
        {
            throw new LocalizedException(__("Invalid discount name: %1", $name));
        }

        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        $couponId = ((string)$stripeAmount) . strtoupper($currency) . "-once-$couponGroup";

        $data = [
            'id' => $couponId,
            'amount_off' => $stripeAmount,
            'currency' => $currency,
            'name' => $name
        ];

        $this->getObject($data['id']);

        if (!$this->getStripeObject())
        {
            $this->createObject($data);
        }

        if (!$this->getStripeObject())
        {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("The discount could not be created in Stripe: %1", $this->getLastError())
            );
        }

        if ($this->getStripeObject()->name != $data['name'])
        {
            $this->update([
                'name' => $data['name']
            ]);
        }

        return $this;
    }

    public function fromGiftCards($order)
    {
        $currency = $order->getOrderCurrencyCode();
        $amount = $order->getGiftCardsAmount();

        $discountType = "amount_off";
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);

        $giftCards = json_decode($order->getGiftCards());
        if (count($giftCards) > 1)
        {
            $name = __("%1 Gift Cards", $this->currencyHelper->addCurrencySymbol($amount, $currency));
        }
        else
        {
            $name = __("%1 Gift Card", $this->currencyHelper->addCurrencySymbol($amount, $currency));
        }

        $params = [
            $discountType => $stripeAmount,
            'currency' => $currency,
            'name' => $name
        ];

        try
        {
            $this->createObject($params);
        }
        catch (\Exception $e)
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("The gift cards for order #%1 could not be created in Stripe: %2", $order->getIncrementId(), $e->getMessage()));
        }

        return $this;
    }

    private function getCouponExpirationParams($ruleId, $hasTrial)
    {
        $defaults = ['duration' => 'forever'];

        if (empty($ruleId))
            return $defaults;

        $coupon = $this->helper->loadStripeCouponByRuleId($ruleId);
        $duration = $coupon->duration();
        $months = $coupon->months();

        if ($months && $months > 0)
        {
            return [
                'duration' => $duration,
                'duration_in_months' => $months
            ];
        }
        else if ($duration == "once" && $hasTrial)
        {
            return [
                'duration' => 'repeating',
                'duration_in_months' => 1
            ];
        }

        return ['duration' => $duration];
    }

    private function getSubscriptionCouponParams($amount, $currency, $ruleId, $hasTrial)
    {
        if (empty($amount) || empty($ruleId))
            return null;

        $this->rule = $rule = $this->helper->loadRuleByRuleId($ruleId);
        $action = $rule->getSimpleAction();
        if (empty($action))
            return null;

        $action = "by_fixed";
        $discountType = "amount_off";
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        $couponId = ((string)$stripeAmount) . strtoupper($currency);
        $name = $this->currencyHelper->addCurrencySymbol($amount, $currency) . " Discount";

        $expirationParams = $this->getCouponExpirationParams($ruleId, $hasTrial);

        switch ($expirationParams['duration'])
        {
            case 'repeating':
                $couponId .= "-months-" . $expirationParams['duration_in_months'];
                break;
            case 'once':
                $couponId .= "-once";
                break;
        }

        $couponId .= "-$ruleId";

        $params = [
            'id' => $couponId,
            $discountType => $stripeAmount,
            'currency' => $currency,
            'name' => $name
        ];

        $params = array_merge($params, $expirationParams);

        return $params;
    }

    private function cleanPercentage(string $percent)
    {
        $parts = explode(".", $percent);
        $decimalLength = 0;

        if (count($parts) > 1)
        {
            // Trim 0s from the end of $parts[1]
            $parts[1] = rtrim($parts[1], "0");
            $decimalLength = strlen($parts[1]);
        }

        if ($decimalLength > 0)
        {
            return $parts[0] . "." . $parts[1];
        }
        else
        {
            return $parts[0];
        }
    }

    private function getOrderCouponParams($order, $rule)
    {
        $currency = $order->getOrderCurrencyCode();
        $action = $rule->getSimpleAction();

        switch ($action)
        {
            case 'by_percent':
                $percent = $this->cleanPercentage((string)$rule->getDiscountAmount());
                $percentCode = str_replace(".", "p", $percent);
                $couponId = "$percentCode-percent-once-" . $rule->getRuleId();

                $params = [
                    'id' => $couponId,
                    'percent_off' => $percent,
                    'currency' => $currency,
                    'name' => $rule->getName()
                ];

                break;
            case 'cart_fixed':
                $baseAmount = $rule->getDiscountAmount();
                $amount = $this->convert->baseAmountToOrderAmount($baseAmount, $order);
                $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
                $couponId = ((string)$stripeAmount) . strtoupper($currency) . "-once-" . $rule->getRuleId();

                $params = [
                    'id' => $couponId,
                    'amount_off' => $stripeAmount,
                    'currency' => $currency,
                    'name' => $rule->getName()
                ];
                break;
            case 'by_fixed':
            case 'buy_x_get_y':
            default:
                throw new LocalizedException(__("Discount action %1 does not apply to the whole order.", $action));
        }

        return $params;
    }

    private function getOrderItemCouponParams($orderItem, $order, $currency, $ruleId)
    {
        $amount = $orderItem->getDiscountAmount();

        if (empty($amount) || empty($ruleId) || empty($currency))
            return null;

        $this->rule = $rule = $this->helper->loadRuleByRuleId($ruleId);
        $action = $rule->getSimpleAction();
        switch ($action)
        {
            case 'by_percent':
                return $this->getOrderCouponParams($order, $rule);
            case 'by_fixed':
            case 'buy_x_get_y':
                $discountData = $this->discountHelper->getDiscountData($orderItem, $rule);
                $amount = $discountData->getAmount();

                if (empty($amount))
                    return null;

                $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
                $couponId = ((string)$stripeAmount) . strtoupper($currency) . "-once-" . $ruleId;

                $params = [
                    'id' => $couponId,
                    'amount_off' => $stripeAmount,
                    'currency' => $currency,
                    'name' => $rule->getName()
                ];
                break;
            case 'cart_fixed':
                throw new LocalizedException(__("Unsupported discount action: %1", $action));
            default:
                throw new LocalizedException(__("Invalid discount action: %1", $action));
        }

        return $params;
    }

    public function getApplyToShipping()
    {
        if (!empty($this->rule))
        {
            return $this->rule->getApplyToShipping();
        }

        return false;
    }
}
