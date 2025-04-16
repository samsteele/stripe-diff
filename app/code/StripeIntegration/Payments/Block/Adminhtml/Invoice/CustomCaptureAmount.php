<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Invoice;

use Magento\Backend\Block\Template;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class CustomCaptureAmount extends Template
{
    private $priceCurrency;
    private $orderRepository;
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        Template\Context $context,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        $this->config = $config;
        $this->orderRepository = $orderRepository;
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context, $data);
    }

    public function getCurrencySymbol()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($orderId);
        $baseCurrencyCode = $order->getBaseCurrencyCode();
        return $this->priceCurrency->getCurrency(null, $baseCurrencyCode)->getCurrencySymbol();
    }

    public function shouldDisplay()
    {
        if (!$this->config->isOvercaptureEnabled())
            return false;

        $orderId = $this->getRequest()->getParam('order_id');
        if (!is_numeric($orderId))
            return false;

        $order = $this->orderRepository->get($orderId);
        $paymentMethodCode = $order->getPayment()->getMethod();
        return in_array($paymentMethodCode, ['stripe_payments', 'stripe_payments_express']);
    }
}