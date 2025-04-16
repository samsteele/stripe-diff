<?php

namespace StripeIntegration\Payments\Block\Adminhtml;

class SelectPaymentMethod extends \Magento\Backend\Block\Widget\Form\Generic
{
    protected $_template = 'form/select_payment_method.phtml';
    private $paymentMethodHelper;
    private $paymentsConfig;
    private $customer;
    private $sessionQuote;
    private $helper;
    private $initParams;
    private $serializer;
    private $escaper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \Magento\Framework\Escaper $escaper,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->sessionQuote = $sessionQuote;
        $this->customer = $helper->getCustomerModel();
        $this->helper = $helper;
        $this->initParams = $initParams;
        $this->serializer = $serializer;
        $this->escaper = $escaper;
    }

    protected function initStripeCustomer()
    {
        // This is an order which the merchant might be trying to edit. It is saved in the session
        // and retrieved from the order edit page.
        $order = $this->sessionQuote->getOrder();
        if (!$order || !$order->getPayment() || !$order->getPayment()->getAdditionalInformation('customer_stripe_id'))
            return;

        $this->customer->load($order->getPayment()->getAdditionalInformation('customer_stripe_id'));
    }

    public function isOrderEdit()
    {
        $order = $this->sessionQuote->getOrder();
        return $order && $order->getPayment() && $order->getPayment()->getAdditionalInformation('payment_action') == "order";
    }

    public function getSavedPaymentMethods()
    {
        try
        {
            if ($this->isOrderEdit())
            {
                $order = $this->sessionQuote->getOrder();
                $stripeCustomerId = $order->getPayment()->getAdditionalInformation('customer_stripe_id');
                $paymentMethodId = $order->getPayment()->getAdditionalInformation('token');
                $this->customer->fromStripeId($stripeCustomerId);
                $paymentMethod = $this->paymentsConfig->getStripeClient()->paymentMethods->retrieve($paymentMethodId, []);
                return $this->paymentMethodHelper->formatPaymentMethods([
                    $paymentMethod->type => [ $paymentMethod ]
                ]);
            }

            if (!$this->customer->getStripeId())
            {
                $this->customer->createStripeCustomer();
                return [];
            }
            else if (!$this->customer->getCustomerId())
            {
                // Guest customers
                $params = $this->customer->getParams();
                $this->customer->createNewStripeCustomer($params); // Misleading method name, this updates the customer object
            }

            $methods = $this->customer->getSavedPaymentMethods(null, true);

            return $methods;
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e, $e->getTraceAsString());
            return [];
        }
    }

    public function getAddNewPaymentMethodURL()
    {
        $mode = $this->paymentsConfig->getStripeMode();

        if ($mode == "test")
            return "http://dashboard.stripe.com/test/customers/" . $this->customer->getStripeId();
        else
            return "http://dashboard.stripe.com/customers/" . $this->customer->getStripeId();
    }

    public function getAdminInitParams()
    {
        $params = $this->initParams->getAdminParams();

        // Prepare the array so that it can be assigned to a data- attribute on the HTML element
        $jsonParams = $this->serializer->serialize($params);
        $preparedParams = $this->escaper->escapehtml($jsonParams);

        return $preparedParams;
    }
}
