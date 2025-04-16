<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\InfoInterface;
use Psr\Log\LoggerInterface;

class BankTransfers extends \Magento\Payment\Model\Method\Adapter
{
    private $helper;
    private $bankTransfersHelper;
    private $config;
    private $areaCodeHelper;
    protected $convert;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\BankTransfers $bankTransfersHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        $code,
        $formBlockType,
        $infoBlockType,
        ?CommandPoolInterface $commandPool = null,
        ?ValidatorPoolInterface $validatorPool = null,
        ?CommandManagerInterface $commandExecutor = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->helper = $helper;
        $this->bankTransfersHelper = $bankTransfersHelper;
        $this->convert = $convert;
        $this->areaCodeHelper = $areaCodeHelper;

        if ($this->helper->isAdmin())
            $formBlockType = 'StripeIntegration\Payments\Block\Adminhtml\Payment\BankTransfers';

        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            $commandExecutor,
            $logger
        );
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        if ($this->areaCodeHelper->isAdmin())
        {
            return $this->assignAdminData($data);
        }
        else
        {
            return $this->assignFrontendData($data);
        }
    }

    private function assignFrontendData($data)
    {
        $additionalData = $data->getAdditionalData();

        if (empty($additionalData["payment_method"]) || strpos($additionalData["payment_method"], "pm_") === false)
        {
            return $this;
        }

        $paymentMethodId = $additionalData["payment_method"];
        /** @var InfoInterface $info */
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation("token", $paymentMethodId);

        return parent::assignData($data);
    }

    private function assignAdminData($data)
    {
        $daysDue = $data->getAdditionalData('days_due');
        $daysDue = max(0, $daysDue);
        $daysDue = min(999, $daysDue);
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('days_due', $daysDue);

        return parent::assignData($data);
    }

    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        try
        {
            if (!$quote || !$quote->getId())
                return false;

            if (!$this->config->initStripe())
                return false;

            $paymentMethodOptions = $this->bankTransfersHelper->getPaymentMethodOptions();
            if (!$paymentMethodOptions)
                return false;

            $quoteCurrency = $quote->getQuoteCurrencyCode();
            $quoteCountry = $quote->getBillingAddress()->getCountryId();

            if (!$this->isCountryCurrencySupported($quoteCountry, $quoteCurrency))
                return false;

            $quoteBaseAmount = $quote->getBaseGrandTotal();
            $minimumAmount = $this->config->getConfigData("minimum_amount", "bank_transfers");
            if (is_numeric($minimumAmount) && $quoteBaseAmount < $minimumAmount)
                return false;

            return parent::isAvailable($quote);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage());
            return false;
        }
    }

    protected function isCountryCurrencySupported($countryCode, $currency)
    {
        $accountModel = $this->config->getAccountModel();
        $accountCurrency = $accountModel->getDefaultCurrency();
        if ($accountCurrency != strtolower($currency))
        {
            return false;
        }

        switch ($countryCode)
        {
            case "US":
                return $currency == "USD";
            case "GB":
                return $currency == "GBP";
            case "JP":
                return $currency == "JPY";
            case "MX":
                return $currency == "MXN";
            default:
                return $currency == "EUR";
        }
    }

    public function isActive($storeId = null)
    {
        if ($this->areaCodeHelper->isAdmin())
            return true;

        return parent::isActive($storeId);
    }
}
