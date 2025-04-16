<?php

namespace StripeIntegration\Tax\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\SerializerInterface;
use StripeIntegration\Tax\Exceptions\CreditmemoException;
use StripeIntegration\Tax\Helper\Transactions;
use StripeIntegration\Tax\Model\StripeTransactionReversal;
use Magento\SalesSequence\Model\Manager;
use StripeIntegration\Tax\Helper\Order;
use StripeIntegration\Tax\Model\TaxFlow;
use Magento\Framework\Serialize\JsonValidator;

class CreateTransactionReversal implements ObserverInterface
{
    private $stripeTransactionReversal;
    private $sequenceManager;
    private $serializer;
    private $taxFlow;
    private $transactionsHelper;
    private $jsonValidator;

    public function __construct(
        StripeTransactionReversal $stripeTransactionReversal,
        Manager $sequenceManager,
        SerializerInterface $serializer,
        TaxFlow $taxFlow,
        Transactions $transactionsHelper,
        JsonValidator $jsonValidator
    )
    {
        $this->stripeTransactionReversal = $stripeTransactionReversal;
        $this->sequenceManager = $sequenceManager;
        $this->serializer = $serializer;
        $this->taxFlow = $taxFlow;
        $this->transactionsHelper = $transactionsHelper;
        $this->jsonValidator = $jsonValidator;
    }

    public function execute(Observer $observer)
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();

        // Handles the reversal if the credit memo was started from the invoice page
        // Create reversal only if Stripe tax enabled
        // and there is an invoice for the credit memo
        // and the tax was calculated using Stripe Tax
        if ($this->stripeTransactionReversal->isEnabled() &&
            $creditMemo->getInvoice() &&
            $creditMemo->getInvoice()->getStripeTaxTransactionId()
        ) {
            // If there is no increment id set on the credit memo, we set it here to be able to use it as the
            // reference. During the save process, the credit memo object is checked for an increment id and
            // if it is set, it will not be set anymore.
            if (!$creditMemo->getIncrementId()) {
                $creditMemo->setIncrementId(
                    $this->sequenceManager->getSequence($creditMemo->getEntityType(), $creditMemo->getStoreId())->getNextValue()
                );
            }
            $transaction = $this->transactionsHelper->loadByStripeTransactionId($creditMemo->getInvoice()->getStripeTaxTransactionId());
            if (!$transaction->getId()) {
                $transaction = $this->createTransactions($creditMemo, $creditMemo->getInvoice());
            }
            $reversal = $this->stripeTransactionReversal->createReversal($creditMemo, $transaction);
            if (!$this->taxFlow->canCreditMemoProceed()) {
                throw new CreditmemoException(__('Credit memo could not be created.'));
            }
            $creditMemo->setStripeTaxTransactionId($reversal->getStripeTransactionId());
        } elseif ($this->stripeTransactionReversal->isEnabled()) {
            if (!$creditMemo->getIncrementId()) {
                $creditMemo->setIncrementId(
                    $this->sequenceManager->getSequence($creditMemo->getEntityType(), $creditMemo->getStoreId())->getNextValue()
                );
            }
            $transactionIds = [];
            $creditMemo->setAmountToRevert(0);
            foreach ($creditMemo->getOrder()->getInvoiceCollection() as $invoice) {
                if ($invoice->getStripeTaxTransactionId() &&
                    $creditMemo->getGrandTotal() > $creditMemo->getAmountToRevert()
                ) {
                    $transaction = $this->transactionsHelper->loadByStripeTransactionId($invoice->getStripeTaxTransactionId());
                    if (!$transaction->getId()) {
                        $transaction = $this->createTransactions($creditMemo, $invoice);
                    }
                    if (!$transaction->isFullyReverted()) {
                        $reversal = $this->stripeTransactionReversal->createReversal($creditMemo, $transaction, $invoice);
                        if (!$this->taxFlow->canCreditMemoProceed()) {
                            throw new CreditmemoException(__('Offline credit memo could not be created.'));
                        }
                        $transactionIds[] = $reversal->getStripeTransactionId();
                    }

                }
            }
            if ($transactionIds) {
                $creditMemo->setStripeTaxTransactionId($this->serializer->serialize($transactionIds));
            }
        }
    }

    /**
     * Create transactions on the fly if there were orders and reversals before the DB tables for transactions were
     * implemented.
     * Algorithm as follows:
     * - get the stripe transaction based on the transaction ID saved on invoice
     * - create the transaction in our DB
     * - check if the order has credit memos (we check for the order because for offline credit memos, they are not associated with an invoice)
     *      - if there are credit memos, get the reversal transactions for them (they can be either serialized for offline ones or only a string for online ones)
     *          - get the reversal transaction from Stripe based on the reversal transaction id
     *          - check that the original transaction id of the reversal transaction coincides with the invoice transaction id
     *              - create the reversal transaction in our DB
     *
     * @param $creditMemo
     * @param $invoice
     * @return \StripeIntegration\Tax\Model\Transaction|void
     */
    private function createTransactions($creditMemo, $invoice)
    {
        $stripeTransaction = $this->stripeTransactionReversal->getStripeTransaction($invoice->getStripeTaxTransactionId());
        if ($stripeTransaction) {
            // Create transaction in our system
            $transaction = $this->transactionsHelper->processStripeTransaction($stripeTransaction, $invoice);
            $order = $creditMemo->getOrder();
            // Check that order has credit memos
            if ($creditMemos = $order->getCreditmemosCollection()) {
                // Go through credit memos
                foreach ($creditMemos as $creditMemo) {
                    // Get the reversal ids from credit memos
                    if ($this->jsonValidator->isValid($creditMemo->getStripeTaxTransactionId())) {
                        $reversalIds = $this->serializer->unserialize($creditMemo->getStripeTaxTransactionId());
                        foreach ($reversalIds as $reversalId) {
                            // Get Stripe reversal transaction
                            $reversalTransaction = $this->stripeTransactionReversal->getStripeTransaction($reversalId);
                            // Check that the original transaction id is the same as the current invoice
                            if ($reversalTransaction && $reversalTransaction->reversal->original_transaction == $invoice->getStripeTaxTransactionId()) {
                                // Create the reversal in our DB
                                $this->transactionsHelper->processStripeReversal($reversalTransaction, $creditMemo, $transaction, $invoice);
                            }
                        }
                    } else {
                        // Get Stripe reversal transaction
                        $reversalTransaction = $this->stripeTransactionReversal->getStripeTransaction($creditMemo->getStripeTaxTransactionId());
                        // Check that the original transaction id is the same as the current invoice
                        if ($reversalTransaction && $reversalTransaction->reversal->original_transaction == $invoice->getStripeTaxTransactionId()) {
                            // Create the reversal in our DB
                            $this->transactionsHelper->processStripeReversal($reversalTransaction, $creditMemo, $transaction, $creditMemo->getInvoice());
                        }
                    }
                }
            }
            return $transaction;
        }
    }
}