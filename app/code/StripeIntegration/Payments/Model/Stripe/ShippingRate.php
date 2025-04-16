<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Stripe;

use StripeIntegration\Payments\Exception\Exception;

class ShippingRate
{
    use StripeObjectTrait;

    private $objectSpace = 'shippingRates';
    private $helper;
    private $shippingRateCollection;
    private $shippingRateResourceModel;
    private $config;
    private $convert;

    public function __construct(
        \StripeIntegration\Payments\Model\ResourceModel\ShippingRate\Collection $shippingRateCollection,
        \StripeIntegration\Payments\Model\ResourceModel\ShippingRate $shippingRateResourceModel,
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Convert $convert
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->config = $config;
        $this->shippingRateCollection = $shippingRateCollection;
        $this->shippingRateResourceModel = $shippingRateResourceModel;
        $this->helper = $helper;
        $this->convert = $convert;
    }

    public function fromOrder($order)
    {
        if ($order->getIsVirtual())
        {
            throw new Exception("The order does not have a shipping address.");
        }

        $shippingAmount = $order->getShippingAmount();

        if (!is_numeric($shippingAmount) || $shippingAmount <= 0)
        {
            // No shipping amount, no shipping rate
            return $this;
        }

        $shippingTaxAmount = $order->getShippingTaxAmount();

        if (is_numeric($shippingTaxAmount) && $shippingTaxAmount > 0)
        {
            throw new Exception("Shipping tax is not supported.");
        }

        $displayName = $order->getShippingDescription();
        $currency = $order->getOrderCurrencyCode();

        $data = [
            'display_name' => $displayName,
            'fixed_amount' => [
                'amount' => $this->convert->magentoAmountToStripeAmount($shippingAmount, $currency),
                'currency' => $currency
            ],
            'type' => 'fixed_amount'
        ];

        $accountModel = $this->config->getAccountModel();
        if (!$accountModel->getId())
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("Could not load Stripe account details."));
        }

        $shippingRateModel = $this->shippingRateCollection->findBy($accountModel->getId(), $displayName, $shippingAmount, $currency);

        try
        {
            if ($shippingRateModel->getShippingRateId() && $this->getObject($shippingRateModel->getShippingRateId()))
            {
                $this->load($shippingRateModel->getShippingRateId());
                return $this;
            }
            else
            {
                $this->createObject($data);
                $shippingRateModel->setStripeAccountId($accountModel->getId());
                $shippingRateModel->setShippingRateId($this->getId());
                $shippingRateModel->setDisplayName($displayName);
                $shippingRateModel->setAmount($shippingAmount);
                $shippingRateModel->setCurrency($currency);
                $this->shippingRateResourceModel->save($shippingRateModel);
            }
        }
        catch (\Exception $e)
        {
            throw new \Magento\Framework\Exception\LocalizedException(__("The tax rate could not be created in Stripe: %1", $e->getMessage()));
        }

        return $this;
    }
}
