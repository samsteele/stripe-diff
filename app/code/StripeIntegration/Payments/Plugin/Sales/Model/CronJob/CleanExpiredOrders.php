<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\CronJob;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\StoresConfig;
use Magento\Sales\Model\Order;

class CleanExpiredOrders
{
    private $storesConfig;
    private $orderCollectionFactory;
    private $helper;
    private $paymentHelper;
    private $checkoutFlow;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Payment $paymentHelper,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        StoresConfig $storesConfig,
        CollectionFactory $collectionFactory
    ) {
        $this->helper = $helper;
        $this->paymentHelper = $paymentHelper;
        $this->checkoutFlow = $checkoutFlow;
        $this->storesConfig = $storesConfig;
        $this->orderCollectionFactory = $collectionFactory;
    }

    public function beforeExecute($subject)
    {
        $this->checkoutFlow->isCleaningExpiredOrders = true;

        $lifetimes = $this->storesConfig->getStoresConfigByPath('sales/orders/delete_pending_after');
        foreach ($lifetimes as $storeId => $lifetime) {
            $orders = $this->orderCollectionFactory->create();
            $orders->addFieldToFilter('store_id', $storeId);
            $orders->addFieldToFilter('status', Order::STATE_PENDING_PAYMENT);

            $orders->getSelect()->join(
                ['payment' => $orders->getTable('sales_order_payment')],
                'main_table.entity_id = payment.parent_id',
                []
            );

            $paymentMethods = ['stripe_payments', 'stripe_payments_express', 'stripe_payments_checkout'];
            $orders->addFieldToFilter('payment.method', ['in' => $paymentMethods]);

            $orders->getSelect()->where(
                new \Zend_Db_Expr('TIME_TO_SEC(TIMEDIFF(CURRENT_TIMESTAMP, `updated_at`)) >= ' . $lifetime * 60)
            );

            foreach ($orders as $order)
            {
                if (!$order->canCancel())
                    continue;

                try
                {
                    $stripePaymentIntentModel = $this->paymentHelper->getStripePaymentIntentModel($order);
                    if ($stripePaymentIntentModel->wasSuccessfullyAuthorized())
                    {
                        // The charge.succeeded event may have not yet arrived or been processed, or a manual capture of the payment was made
                        $this->helper->setProcessingState($order, "An attempt to cancel the order was made via cron, but the payment was successful. Restoring the order to processing state.");
                        $this->helper->invoiceOrder($order, $stripePaymentIntentModel->getId(), \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, true);
                        continue;
                    }
                }
                catch (\Stripe\Exception\InvalidRequestException $e)
                {
                    if ($e->getStripeCode() == "rate_limit")
                    {
                        // We could potentially hit the API rate limit here, so we'll process this order on the next run
                        continue;
                    }
                    else
                    {
                        $this->helper->logError("Error while cleaning expired orders: " . $e->getMessage(), $e->getTraceAsString());
                        continue;
                    }
                }
                catch (\Exception $e)
                {
                    $this->helper->logError("Error while cleaning expired orders: " . $e->getMessage(), $e->getTraceAsString());
                    continue;
                }
            }
        }
    }
}
