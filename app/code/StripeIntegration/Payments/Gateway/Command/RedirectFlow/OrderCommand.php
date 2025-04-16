<?php

namespace StripeIntegration\Payments\Gateway\Command\RedirectFlow;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Framework\Exception\LocalizedException;

class OrderCommand implements CommandInterface
{
    private $config;
    private $checkoutSessionFactory;
    private $quoteHelper;
    private $customer;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory
    ) {
        $this->quoteHelper = $quoteHelper;
        $this->customer = $helper->getCustomerModel();
        $this->config = $config;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
    }

    // For some reason when a guest customer changes the email at the checkout,
    // it is only updated on $quote->getBillingAddress()->getEmail() and nowhere else
    private function updateOrderEmailFromQuote($order)
    {
        $isGuest = $order->getCustomerIsGuest();
        if (!$isGuest)
            return;

        $quote = $this->quoteHelper->loadQuoteById($order->getQuoteId());
        $email = $quote->getBillingAddress()->getEmail();

        // Check if its a valid email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            return;

        $order->getBillingAddress()->setEmail($email);
        $order->setCustomerEmail($email);
        $quote->setCustomerEmail($email);

        if (!$order->getIsVirtual())
        {
            $order->getShippingAddress()->setEmail($email);
            $quote->getShippingAddress()->setEmail($email);
        }
    }

    public function execute(array $commandSubject): void
    {
        $payment = $commandSubject['payment']->getPayment();
        $amount = $commandSubject['amount'];

        $order = $payment->getOrder();

        // We don't want to send an order email until the payment is collected asynchronously
        $order->setCanSendNewEmailFlag(false);

        try
        {
            $this->updateOrderEmailFromQuote($order);
            $this->customer->updateFromOrder($order);
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->fromOrder($order, true);
            $checkoutSessionObject = $checkoutSessionModel->getStripeObject();

            $payment->setAdditionalInformation("checkout_session_id", $checkoutSessionObject->id);
            $payment->setAdditionalInformation("payment_action", $this->config->getPaymentAction());
            $payment->setAdditionalInformation("is_transaction_pending", true);

            $order->getPayment()
                ->setIsTransactionClosed(0)
                ->setIsTransactionPending(true);
        }
        catch (\Stripe\Exception\CardException $e)
        {
            throw new LocalizedException(__($e->getMessage()));
        }
        catch (\Exception $e)
        {
            if (strstr($e->getMessage(), 'Invalid country') !== false) {
                throw new LocalizedException(__('Sorry, this payment method is not available in your country.'));
            }
            throw new LocalizedException(__($e->getMessage()));
        }

        $payment->setAdditionalInformation("payment_location", "Redirect flow");

    }
}
