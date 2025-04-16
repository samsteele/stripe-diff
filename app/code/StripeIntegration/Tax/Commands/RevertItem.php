<?php

namespace StripeIntegration\Tax\Commands;

use StripeIntegration\Tax\Exceptions\ReversalException;
use StripeIntegration\Tax\Helper\Currency;
use StripeIntegration\Tax\Helper\LineItemsFactory;
use StripeIntegration\Tax\Model\StripeTransactionReversalFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Sales\Model\ResourceModel\Order;
use Magento\Sales\Model\OrderFactory;
use StripeIntegration\Tax\Helper\Order as OrderHelper;
use StripeIntegration\Tax\Model\ResourceModel\Transaction\LineItem\CollectionFactory as LineItemCollectionFactory;

class RevertItem extends Command
{
    public const OPTION_ORDER_ITEM_SKU = 'order-item-sku';
    public const OPTION_ORDER_INCREMENT_ID = 'order-increment-id';
    public const OPTION_QUANTITY = 'quantity';
    public const OPTION_SHIPPING = 'shipping-amount';
    public const OPTION_ADDITIONAL_FEE = 'additional-fee';

    private $orderResource;
    private $orderFactory;
    private $orderHelper;
    private $lineItemsHelper;
    private $lineItemsHelperFactory;
    private $lineItemsCollectionFactory;
    private $lineItemsCollection;
    private $reversal;
    private $reversalFactory;
    private $currencyHelper;

    private $output;
    private $shippingItem = null;
    private $additionalFeeItems = [];

    public function __construct(
        Order $orderResource,
        OrderFactory $orderFactory,
        OrderHelper $orderHelper,
        LineItemsFactory $lineItemsHelperFactory,
        LineItemCollectionFactory $lineItemsCollectionFactory,
        StripeTransactionReversalFactory $reversalFactory,
        Currency $currencyHelper
    ) {
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        $this->orderHelper = $orderHelper;
        $this->lineItemsHelperFactory = $lineItemsHelperFactory;
        $this->lineItemsCollectionFactory = $lineItemsCollectionFactory;
        $this->reversalFactory = $reversalFactory;
        $this->currencyHelper = $currencyHelper;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:tax:revert-item');
        $this->addOption(self::OPTION_ORDER_ITEM_SKU, 'sku', InputOption::VALUE_REQUIRED, __('(Required) The SKU of the order item you want to revert the tax for.'));
        $this->addOption(self::OPTION_ORDER_INCREMENT_ID, 'order', InputOption::VALUE_REQUIRED, __('(Required) The order increment ID for the order which contains the order item.'));
        $this->addOption(self::OPTION_QUANTITY, 'qty', InputOption::VALUE_REQUIRED, __('(Required) The quantity for the item that you want to revert.'));
        $this->addOption(self::OPTION_SHIPPING, 'shipping', InputOption::VALUE_OPTIONAL, __('(Optional) The Shipping amount you want reverted.'));
        $this->addOption(self::OPTION_ADDITIONAL_FEE, 'fee', InputOption::VALUE_OPTIONAL, __('(Optional) The code of any additional fee(s) for this order item that you want reverted (for example, "initial_fee"). If there is more than 1 additional fee, they should be added separated by ;.'));
        $this->setDescription('Reverts the tax for the specified order item.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->lineItemsHelper = $this->lineItemsHelperFactory->create();
        $this->reversal = $this->reversalFactory->create();

        $orderItemSku = $input->getOption(self::OPTION_ORDER_ITEM_SKU);
        $orderIncrementId = $input->getOption(self::OPTION_ORDER_INCREMENT_ID);
        $quantity = $input->getOption(self::OPTION_QUANTITY);
        $shipping = $input->getOption(self::OPTION_SHIPPING);
        $additionalFee = $input->getOption(self::OPTION_ADDITIONAL_FEE);

        $this->lineItemsCollection = $this->lineItemsCollectionFactory->create();

        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderIncrementId, 'increment_id');
        if (!$this->checkOrder($order)) {
            return 1;
        }

        $orderItem = $this->orderHelper->getOrderItemBySku($order, $orderItemSku);
        if (!$this->checkOrderItem($orderItem, $quantity)) {
            return 1;
        }

        $transactions = $this->orderHelper->getTransactionsForOrder($order);
        if (!$this->checkShipping($transactions, $shipping, $order)) {
            return 1;
        }

        $this->checkAdditionalFees($additionalFee, $orderItem, $order);

        $shippingProcessedWithLineItem = false;
        $reversalIds = [];

        // If the order item has children and the children are used to calculate the total for the order, it means
        // that the product is a bundle dynamic product, and we have to use the children to create the reversal.
        // Otherwise we use the order item directly
        if ($orderItem->getHasChildren() && $orderItem->isChildrenCalculated()) {
            $transactionsItemsToRevert = [];
            foreach ($orderItem->getChildrenItems() as $child) {
                $reference = $this->lineItemsHelper->getReferenceForInvoiceTax($child, $order, true);
                $items = $this->lineItemsCollection->getItemsByReference($reference);
                if (!$this->checkLineItems($items, $quantity)) {
                    return 1;
                }
                foreach ($items as $lineItem) {
                    if ($this->shippingItem &&
                        $lineItem->getTransactionId() == $this->shippingItem->getTransactionId() &&
                        !$shippingProcessedWithLineItem
                    ) {
                        $transactionsItemsToRevert[$lineItem->getTransactionId()]['line_items'][] = $lineItem;
                        $transactionsItemsToRevert[$lineItem->getTransactionId()]['process_shipping'] = true;

                        $shippingProcessedWithLineItem = true;
                    } else {
                        $transactionsItemsToRevert[$lineItem->getTransactionId()]['line_items'][] = $lineItem;
                    }
                }
            }
            foreach ($transactionsItemsToRevert as $transaction) {
                if (isset($transaction['process_shipping']) && $transaction['process_shipping']) {
                    $reversal = $this->reversal->createCommandLineReversal($transaction['line_items'], $quantity, $order, $this->additionalFeeItems, $this->shippingItem, $shipping);
                } else {
                    $reversal = $this->reversal->createCommandLineReversal($transaction['line_items'], $quantity, $order, $this->additionalFeeItems);
                }
                $quantity -= $transaction['line_items'][0]->getQtyRemaining();
                $reversalIds[] = $reversal->getStripeTransactionId();
            }

        } else {
            $reference = $this->lineItemsHelper->getReferenceForInvoiceTax($orderItem, $order, true);
            $items = $this->lineItemsCollection->getItemsByReference($reference);
            if (!$this->checkLineItems($items, $quantity)) {
                return 1;
            }
            foreach ($items as $lineItem) {
                if ($this->shippingItem &&
                    $lineItem->getTransactionId() == $this->shippingItem->getTransactionId() &&
                    !$shippingProcessedWithLineItem
                ) {
                    $reversal = $this->reversal->createCommandLineReversal($lineItem, $quantity, $order, $this->additionalFeeItems, $this->shippingItem, $shipping);
                    $shippingProcessedWithLineItem = true;
                } else {
                    $reversal = $this->reversal->createCommandLineReversal($lineItem, $quantity, $order, $this->additionalFeeItems, $shipping);
                }
                $quantity -= $lineItem->getQtyRemaining();
                $reversalIds[] = $reversal->getStripeTransactionId();
            }
        }

        if ($reversalIds) {
            $idsString = implode(', ', $reversalIds);
            $output->writeln("The following reversal(s) were created: $idsString");
        }

        return 0;
    }

    /**
     * Checks the shipping parameter value provided and if it is not too high and the order has a shipping item,
     * that shipping item is set to be used in the process.
     *
     * @param $transactions
     * @param $shipping
     * @param $order
     * @return bool
     */
    private function checkShipping($transactions, $shipping, $order)
    {
        if ($shipping) {
            foreach ($transactions as $transaction) {
                $shippingItem = $transaction->getShippingItemForReversal();
                if ($shippingItem->hasShippingTax()) {
                    $stripeShipping = $this->currencyHelper->magentoAmountToStripeAmount($shipping, $order->getOrderCurrencyCode());
                    if ($stripeShipping > $shippingItem->getAmountRemaining()) {
                        $magentoRemainingShipping = $this->currencyHelper->stripeAmountToMagentoAmount($shippingItem->getAmountRemaining(), $order->getOrderCurrencyCode());
                        $this->output->writeln("<error>" . "The amount specified for the shipping reversal is too high. Please provide a value less or equal to $magentoRemainingShipping." . "</error>");
                        return false;
                    }

                    $this->shippingItem = $shippingItem;
                    return true;
                }
            }
            $this->output->writeln(__("The transactions have no available tax for reversal on them. The --shipping parameter value will be ignored."));
        }

        return true;
    }

    /**
     * Performs checks on the Line items ready for reversal. If there are no line items or the quantity specified
     * is larger than the quantity in DB, exception is thrown.
     *
     * @param $items
     * @param $quantity
     * @return bool
     */
    private function checkLineItems($items, $quantity)
    {
        if (count($items) == 0) {
            $this->output->writeln("<error>" . "There are no items available for reversal." . "</error>");

            return false;
        }

        $totalRemainingQty = 0;
        foreach ($items as $lineItem) {
            $totalRemainingQty += $lineItem->getQtyRemaining();
        }

        if ($totalRemainingQty < $quantity) {
            $this->output->writeln("<error>" . "The amount specified for the item reversal is too high. Please provide a value less or equal to $totalRemainingQty." . "</error>");

            return false;
        }

        return true;
    }

    /**
     * Checks if order item can be reversed and throws exception if it does not.
     *
     * @param $orderItem
     * @param $quantity
     * @return bool
     */
    private function checkOrderItem($orderItem, $quantity)
    {
        if ($orderItem->getQtyInvoiced() == 0) {
            $this->output->writeln("<error>" . "Order item is not invoiced. An item requires an invoice to be reversed." . "<error>");

            return false;
        }

        if ($orderItem->getQtyInvoiced() <= $orderItem->getQtyRefunded()) {
            $this->output->writeln("<error>" . "Order item invoiced quantity is already reversed." . "<error>");

            return false;
        }

        $qtyAvailableForReversal = $orderItem->getQtyInvoiced() - $orderItem->getQtyRefunded();

        if ($qtyAvailableForReversal == 0) {
            $this->output->writeln("<error>" . "Quantity specified order item has no reversible quantity." . "<error>");

            return false;
        }

        if ($qtyAvailableForReversal < $quantity) {
            $this->output->writeln("<error>" . "Quantity specified for the reversal is not available. Please specify a value less or equal to $qtyAvailableForReversal." . "<error>");

            return false;
        }

        return true;
    }

    /**
     * Checks if the order has incompatible statuses and throws exception if it does.
     *
     * @param $order
     * @return bool
     */
    private function checkOrder($order)
    {
        if ($order->getStatus() == 'pending') {
            $this->output->writeln("<error>" . "This order is in pending status and no items can be reversed." . "<error>");

            return false;
        }

        if ($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            $this->output->writeln("<error>" . "This order is in pending payment state and no items can be reversed." . "<error>");

            return false;
        }

        if ($order->getStatus() == \Magento\Sales\Model\Order::STATE_CLOSED) {
            $this->output->writeln("<error>" . "This order was closed." . "<error>");

            return false;
        }

        if ($order->getStatus() == \Magento\Sales\Model\Order::STATE_CANCELED) {
            $this->output->writeln("<error>" . "This order was canceled." . "<error>");

            return false;
        }

        return true;
    }

    /**
     * Runs through the additional fees provided and sets the additional fees items to be used in the process.
     * These items can  be either on order level or line item level.
     *
     * @param $additionalFeesIds
     * @param $orderItem
     * @param $order
     * @return void
     */
    private function checkAdditionalFees($additionalFeesIds, $orderItem, $order)
    {
        if ($additionalFeesIds) {
            $additionalFeesArray = explode(';', $additionalFeesIds);
            foreach ($additionalFeesArray as $additionalFeeId) {
                $itemReference = $this->lineItemsHelper->getReferenceForInvoiceAdditionalFee($orderItem, $order, $additionalFeeId, true);
                $orderReference = $this->lineItemsHelper->getSalesEntityAdditionalFeeReference($order, $additionalFeeId);
                if ($lineItemsForItem = $this->lineItemsCollection->getItemsByReference($itemReference)) {
                    foreach ($lineItemsForItem as $lineItem) {
                        $this->additionalFeeItems[$lineItem->getTransactionId()][] = $lineItem;
                    }
                }
                if ($lineItemsForOrder = $this->lineItemsCollection->getItemsByReference($orderReference)) {
                    foreach ($lineItemsForOrder as $lineItem) {
                        $this->additionalFeeItems[$lineItem->getTransactionId()][] = $lineItem;
                    }
                }
            }
        }
    }
}