<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Payment;

use Magento\Payment\Block\ConfigurableInfo;

class BankTransfers extends ConfigurableInfo
{
    protected $_template = 'form/bank_transfers.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);
    }

    public function getDaysDue()
    {
        return 30;
    }
}
