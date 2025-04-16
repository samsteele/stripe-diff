<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class Patch008PaymentMethodConfiguration implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()->from(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['scope', 'scope_id', 'path', 'value']
        )->where('path LIKE ?', 'payment/stripe_payments/payments/payment_method_configuration');

        $configData = $connection->fetchAll($select);

        foreach ($configData as $config)
        {
            $connection->insert(
                $this->moduleDataSetup->getTable('core_config_data'),
                [
                    'scope' => $config['scope'],
                    'scope_id' => $config['scope_id'],
                    'path' => 'payment/stripe_payments/pmc_all_carts',
                    'value' => $config['value']
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        /**
         * This is dependency to another patch. Dependency should be applied first
         * One patch can have few dependencies
         * Patches do not have versions, so if in old approach with Install/Ugrade data scripts you used
         * versions, right now you need to point from patch with higher version to patch with lower version
         * But please, note, that some of your patches can be independent and can be installed in any sequence
         * So use dependencies only if this important for you
         */
        return [];
    }

    public function revert()
    {

    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        /**
         * This internal Magento method, that means that some patches with time can change their names,
         * but changing name should not affect installation process, that's why if we will change name of the patch
         * we will add alias here
         */
        return [];
    }
}