<?php

namespace StripeIntegration\Tax\Model;

use StripeIntegration\Tax\Helper\Transactions;
use StripeIntegration\Tax\Model\StripeTransactionReversal\Request;
use StripeIntegration\Tax\Helper\Logger;

class StripeTransactionReversal
{
    private $config;
    private $request;
    private $logger;
    private $taxFlow;
    private $transactionsHelper;

    public function __construct(
        Config $config,
        Request $request,
        Logger $logger,
        TaxFlow $taxFlow,
        Transactions $transactionsHelper
    )
    {
        $this->config = $config;
        $this->request = $request;
        $this->logger = $logger;
        $this->taxFlow = $taxFlow;
        $this->transactionsHelper = $transactionsHelper;
    }

    public function createReversal($creditMemo, $transaction, $invoice = null)
    {
        try {
            $request = $this->request->formData($creditMemo, $transaction, $invoice)->toArray();
            $reversalTransaction = $this->config->getStripeClient()->tax->transactions->createReversal($request);
            if ($this->isValidResponse($reversalTransaction)) {
                $reversalTransaction = $this->transactionsHelper->processStripeReversal($reversalTransaction, $creditMemo, $transaction, $invoice);
                $this->taxFlow->creditMemoTransactionSuccessful = true;

                return $reversalTransaction;
            }
        } catch (\Exception $e) {
            $errorMessage = 'Issue occurred while reverting tax:' . PHP_EOL . $e->getMessage();
            $this->logger->logError($errorMessage, $e->getTraceAsString());
        }

        return null;
    }

    public function createCommandLineReversal($lineItem, $quantity, $order, $additionalFeesItems, $shippingItem = null, $shipping = null)
    {
        try {
            $request = $this->request->formCommandLineData($lineItem, $quantity, $order, $additionalFeesItems, $shippingItem, $shipping)->toArray();
            $reversalTransaction = $this->config->getStripeClient()->tax->transactions->createReversal($request);
            if ($this->isValidResponse($reversalTransaction)) {
                if (is_array($lineItem)) {
                    $reversalTransaction = $this->transactionsHelper->processStripeCommandLineReversal($reversalTransaction, $lineItem[0]->getTransaction());
                } else {
                    $reversalTransaction = $this->transactionsHelper->processStripeCommandLineReversal($reversalTransaction, $lineItem->getTransaction());
                }

                return $reversalTransaction;
            }
        } catch (\Exception $e) {
            $errorMessage = 'Issue occurred while reverting tax:' . PHP_EOL . $e->getMessage();
            $this->logger->logError($errorMessage, $e->getTraceAsString());
        }

        return null;
    }

    private function isValidResponse($transaction)
    {
        if ($transaction->id && $transaction->getLastResponse()->code === 200) {
            return true;
        }

        return false;
    }

    public function isEnabled()
    {
        return $this->config->isEnabled();
    }

    public function getStripeTransaction($transactionId)
    {
        try {
            $transaction = $this->config->getStripeClient()->tax->transactions->retrieve($transactionId, ['expand' => ['line_items']]);
            if ($transaction->getLastResponse()->code === 200) {
                return $transaction;
            }
        } catch (\Exception $e) {
            $this->logger->logError(sprintf('Unable to retrieve transaction %s: ', $transactionId) . $e->getMessage());
        }

        return null;
    }

}