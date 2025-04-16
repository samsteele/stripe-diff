<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Stripe;

class TaxRate
{
    use StripeObjectTrait;

    private $objectSpace = 'taxRates';
    private $helper;
    private $taxHelper;
    private $taxRateCollection;
    private $taxRateResourceModel;
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\ResourceModel\TaxRate\Collection $taxRateCollection,
        \StripeIntegration\Payments\Model\ResourceModel\TaxRate $taxRateResourceModel,
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->config = $config;
        $this->taxRateCollection = $taxRateCollection;
        $this->taxRateResourceModel = $taxRateResourceModel;
        $this->helper = $helper;
        $this->taxHelper = $taxHelper;
    }

    public function fromData($displayName, $inclusive, $percentage, $country)
    {
        $data = [
            'display_name' => $displayName,
            'inclusive' => $inclusive,
            'percentage' => $percentage,
            'country' => $country,
            'active' => true
        ];

        $accountModel = $this->config->getAccountModel();
        if (!$accountModel->getId())
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("Could not load Stripe account details."));
        }

        $taxRateModel = $this->taxRateCollection->findBy($accountModel->getId(), $displayName, $inclusive, $percentage, $country);

        try
        {
            if ($taxRateModel->getTaxRateId() && $this->getObject($taxRateModel->getTaxRateId()))
            {
                $this->load($taxRateModel->getTaxRateId());
                return $this;
            }
            else
            {
                $this->createObject($data);
                $taxRateModel->setStripeAccountId($accountModel->getId());
                $taxRateModel->setTaxRateId($this->getId());
                $taxRateModel->setDisplayName($displayName);
                $taxRateModel->setInclusive($inclusive);
                $taxRateModel->setPercentage($percentage);
                $taxRateModel->setCountryCode($country);
                $this->taxRateResourceModel->save($taxRateModel);
            }
        }
        catch (\Exception $e)
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("The tax rate could not be created in Stripe: %1", $e->getMessage()));
        }

        return $this;
    }
}
