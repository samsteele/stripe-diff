<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model\SalesRule;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Api\Data\RuleExtensionInterface;
use StripeIntegration\Payments\Api\Data\CouponInterface;
use StripeIntegration\Payments\Model\ResourceModel\Coupon as ResourceCoupon;
use StripeIntegration\Payments\Model\Coupon;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SaveHandlerTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $resourceCoupon;
    private $ruleResource;
    private $saveHandler;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resourceCoupon = $this->objectManager->get(ResourceCoupon::class);
        $this->ruleResource = $this->objectManager->get(\Magento\SalesRule\Model\ResourceModel\Rule::class);
        $this->saveHandler = $this->objectManager->get(\StripeIntegration\Payments\Model\SalesRule\SaveHandler::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/active 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/subscriptions_enabled 1
     */
    public function testSaveSubscriptionCoupon()
    {
        // Create a new sales rule
        /** @var Rule $rule */
        $rule = $this->objectManager->create(Rule::class);
        $rule->setName('Test Rule')
            ->setIsAdvanced(true)
            ->setStopRulesProcessing(false)
            ->setDiscountQty(10)
            ->setCustomerGroupIds([0])
            ->setWebsiteIds([1])
            ->setCouponType(2) // SPECIFIC COUPON
            ->setSimpleAction('by_percent')
            ->setDiscountAmount(10)
            ->setIsActive(true);

        // Save the rule using resource model
        $this->ruleResource->save($rule);
        $ruleId = $rule->getRuleId();

        // Create and set extension attributes
        /** @var RuleExtensionInterface $extensionAttributes */
        $extensionAttributes = [
            CouponInterface::EXTENSION_CODE => [
                'rule_id' => $ruleId,
                'coupon_duration' => 'repeating',
                'coupon_months' => '3'
            ]
        ];
        $rule->setExtensionAttributes($extensionAttributes);

        // Save the rule again with extension attributes
        $this->saveHandler->execute($rule);

        // Load the saved coupon data
        $couponModel = $this->objectManager->create(Coupon::class);
        $this->resourceCoupon->load($couponModel, $ruleId, CouponInterface::COUPON_RULE_ID);

        // Assert the saved data
        $this->assertEquals('repeating', $couponModel->duration());
        $this->assertEquals('3', $couponModel->months());
    }
}