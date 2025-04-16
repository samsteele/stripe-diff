<?php

namespace StripeIntegration\Tax\Test\Integration\Helper;


class Tests
{
    private $objectManager;
    private $invoiceService;
    private $orderHelper;
    private $invoiceHelper;
    private $creditmemoFactory;
    private $creditmemoService;

    public function __construct()
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->invoiceService = $this->objectManager->get(\Magento\Sales\Model\Service\InvoiceService::class);
        $this->orderHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Order();
        $this->invoiceHelper = new \StripeIntegration\Tax\Test\Integration\Helper\Invoice();
        $this->creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $this->creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
    }

    public function invoiceOnline($order, $itemQtys, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE)
    {
        $orderItemIDs = [];
        $orderItemQtys = [];

        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $orderItemIDs[$orderItem->getSku()] = $orderItem->getId();
        }

        foreach ($itemQtys as $sku => $qty)
        {
            if (isset($orderItemIDs[$sku]))
            {
                $id = $orderItemIDs[$sku];
                $orderItemQtys[$id] = $qty;
            }
        }

        $invoice = $this->invoiceService->prepareInvoice($order, $orderItemQtys);
        $invoice->setRequestedCaptureCase($captureCase);
        $order->setIsInProcess(true);
        $invoice->register();
        $invoice->pay();
        $this->orderHelper->saveOrder($order);

        return $this->invoiceHelper->saveInvoice($invoice);
    }

    public function refundOffline($order, $itemQtys, $shipping = 0)
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItem())
                continue;

            $sku = $item->getSku();
            if(isset($itemQtys[$sku])) {
                $qtys[$item->getId()] = $itemQtys[$sku];
            }
        }

        if (count($itemQtys) != count($qtys))
            throw new \Exception("Specified SKU not found in order items.");

        $params = [
            "qtys" => $qtys,
            "shipping_amount" => $shipping,
            "adjustment_positive" => 0,
            "adjustment_negative" => 0
        ];

        $creditmemo = $this->creditmemoFactory->createByOrder($order, $params);

        // Create the offilne credit memo
        return $this->creditmemoService->refund($creditmemo, true);
    }
}