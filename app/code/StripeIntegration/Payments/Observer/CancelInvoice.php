<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Exception\LocalizedException;

// sales_order_payment_cancel_invoice
class CancelInvoice implements ObserverInterface
{
    private $helper;
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->helper = $helper;
        $this->config = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $payment = $observer->getPayment();
        $method = $payment->getMethod();

        try
        {
            if (in_array($method, ['stripe_payments_invoice', 'stripe_payments_bank_transfers']))
            {
                $invoiceId = $payment->getAdditionalInformation('invoice_id');
                if (empty($invoiceId))
                {
                    throw new LocalizedException(__('No invoice found in Stripe for this payment.'));
                }
                $this->config->getStripeClient()->invoices->voidInvoice($invoiceId, []);
            }
        }
        catch (\Exception $e)
        {
            $this->helper->throwError($e->getMessage());
        }
    }
}
