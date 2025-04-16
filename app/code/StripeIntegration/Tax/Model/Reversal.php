<?php

namespace StripeIntegration\Tax\Model;

class Reversal extends \Magento\Framework\Model\AbstractModel
{
    private $resourceModel;

    public function __construct(
        \StripeIntegration\Tax\Model\ResourceModel\Reversal $resourceModel,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        $this->resourceModel = $resourceModel;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\ResourceModel\Reversal');
    }

    public function createFromStripeTransaction($transaction, $creditMemo, $invoice = null)
    {
        $this->setStripeTransactionId($transaction->id);
        $this->setOrderIncrementId($creditMemo->getOrder()->getIncrementId());
        if ($invoice) {
            $this->setInvoiceIncrementId($invoice->getIncrementId());
        } else {
            $this->setInvoiceIncrementId($creditMemo->getInvoice()->getIncrementId());
        }
        $this->setCreditmemoIncrementId($creditMemo->getIncrementId());
        $this->setReference($transaction->reference);
        $this->setStripeCreatedAt($transaction->created);
        if (isset($transaction->reversal)) {
            $this->setOriginalTransactionId($transaction->reversal->original_transaction);
        }

        $this->resourceModel->save($this);

        return $this;
    }

    public function createFromStripeCommandLineTransaction($reversalTransaction, $transaction)
    {
        $this->setStripeTransactionId($reversalTransaction->id);
        $this->setOrderIncrementId($transaction->getOrderIncrementId());
        $this->setInvoiceIncrementId($transaction->getInvoiceIncrementId());
        $this->setReference($reversalTransaction->reference);
        $this->setStripeCreatedAt($reversalTransaction->created);
        if (isset($reversalTransaction->reversal)) {
            $this->setOriginalTransactionId($reversalTransaction->reversal->original_transaction);
        }

        $this->resourceModel->save($this);

        return $this;
    }
}