<?php

namespace StripeIntegration\Tax\Model\Reversal;

use StripeIntegration\Tax\Model\Transaction\LineItem as TransactionLineItem;

class LineItem extends \Magento\Framework\Model\AbstractModel
{
    private $resourceModel;

    public function __construct(
        \StripeIntegration\Tax\Model\ResourceModel\Reversal\LineItem  $resourceModel,
        \Magento\Framework\Model\Context                        $context,
        \Magento\Framework\Registry                             $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb           $resourceCollection = null,
        array                                                   $data = []
    )
    {
        $this->resourceModel = $resourceModel;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Tax\Model\ResourceModel\Reversal\LineItem');
    }

    public function createFromStripeTransactionLineItem($lineItem, $transactionModel)
    {
        $this->setTransactionId($transactionModel->getId());
        $this->setStripeOriginalTransactionId($transactionModel->getOriginalTransactionId());
        $this->setStripeId($lineItem->id);
        $this->setAmount($lineItem->amount);
        $this->setAmountTax($lineItem->amount_tax);
        $this->setQty($lineItem->quantity);
        $this->setReference($lineItem->reference);
        $this->setTaxBehavior($lineItem->tax_behavior);
        $this->setTaxCode($lineItem->tax_code);
        $this->setType(TransactionLineItem::LINE_ITEM_TYPE_LINE_ITEM);
        if (isset($lineItem->reversal)) {
            $this->setOriginalStripeId($lineItem->reversal->original_line_item);
        }

        $this->resourceModel->save($this);

        return $this;
    }

    public function createShippingFromStripeTransaction($transaction, $transactionModel)
    {
        $shipping = $transaction->shipping_cost;
        $this->setTransactionId($transactionModel->getId());
        $this->setStripeOriginalTransactionId($transactionModel->getOriginalTransactionId());
        $this->setAmount($shipping->amount);
        $this->setAmountTax($shipping->amount_tax);
        $this->setTaxBehavior($shipping->tax_behavior);
        $this->setTaxCode($shipping->tax_code);
        $this->setType(TransactionLineItem::LINE_ITEM_TYPE_SHIPPING);

        $this->resourceModel->save($this);

        return $this;
    }
}