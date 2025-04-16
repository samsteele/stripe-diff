<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Customer;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ObjectManager;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Tests for Customer Subscriptions Controller
 */
class SubscriptionsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $customerSession;
    private $urlHelper;
    private $helper;
    private $resultPageFactory;
    private $subscriptionsHelper;
    private $order;

    public function setUp(): void
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->customerSession = $this->objectManager->get(Session::class);
        $this->urlHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Url::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->subscriptionsHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
        $this->resultPageFactory = $this->objectManager->get(\Magento\Framework\View\Result\PageFactory::class);
        $this->order = $this->objectManager->get(\Magento\Sales\Model\Order::class);
    }

    /**
     * Test guest user redirect
     */
    public function testGuestUserRedirect()
    {
        // Ensure customer is logged out
        $this->customerSession->logout();

        // Create mocks for dependencies
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(false);

        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('customer/account/login')
            ->willReturn($mockRedirectResult);

        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn([]);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $this->helper,
            $this->subscriptionsHelper,
            $mockUrlHelper,
            $this->order,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect to the login page
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }

    /**
     * Test normal execution for logged-in customer
     */
    public function testLoggedInCustomerAccess()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Mock the result page factory
        $mockResultPage = $this->createMock(\Magento\Framework\View\Result\Page::class);
        $mockResultPageFactory = $this->createMock(\Magento\Framework\View\Result\PageFactory::class);
        $mockResultPageFactory->method('create')->willReturn($mockResultPage);

        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn([]);

        // Create controller with the mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $mockResultPageFactory,
            $mockCustomerSession,
            $this->helper,
            $this->subscriptionsHelper,
            $this->urlHelper,
            $this->order,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify that for logged in customers, it returns the result page
        $this->assertInstanceOf(\Magento\Framework\View\Result\Page::class, $result);
    }

    /**
     * Test view order functionality
     */
    public function testViewOrder()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with viewOrder parameter
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['viewOrder' => '12345']);

        // Mock the order
        $mockOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $mockOrder->method('loadByIncrementId')->with('12345')->willReturnSelf();
        $mockOrder->method('getId')->willReturn(67890);

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('sales/order/view/', ['order_id' => 67890])
            ->willReturn($mockRedirectResult);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $this->helper,
            $this->subscriptionsHelper,
            $mockUrlHelper,
            $mockOrder,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }

    /**
     * Test view non-existent order
     */
    public function testViewNonExistentOrder()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with viewOrder parameter
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['viewOrder' => '99999']);

        // Mock the order - non-existent case
        $mockOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $mockOrder->method('loadByIncrementId')->with('99999')->willReturnSelf();
        $mockOrder->method('getId')->willReturn(null);

        // Mock the helper to verify error message is added
        $mockHelper = $this->createMock(\StripeIntegration\Payments\Helper\Generic::class);
        $mockHelper->expects($this->once())->method('addError');

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('stripe/customer/subscriptions')
            ->willReturn($mockRedirectResult);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $mockHelper,
            $this->subscriptionsHelper,
            $mockUrlHelper,
            $mockOrder,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }

    /**
     * Test update success handling
     */
    public function testUpdateSuccess()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with updateSuccess parameter
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['updateSuccess' => '1']);

        // Mock the helper to verify success message is added
        $mockHelper = $this->createMock(\StripeIntegration\Payments\Helper\Generic::class);
        $mockHelper->expects($this->once())->method('addSuccess');

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('stripe/customer/subscriptions')
            ->willReturn($mockRedirectResult);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $mockHelper,
            $this->subscriptionsHelper,
            $mockUrlHelper,
            $this->order,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }

    /**
     * Test update cancel handling
     */
    public function testUpdateCancel()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with updateCancel parameter
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['updateCancel' => '1']);

        // Mock the subscriptionsHelper
        $mockSubscriptionsHelper = $this->createMock(\StripeIntegration\Payments\Helper\Subscriptions::class);
        $mockSubscriptionsHelper->expects($this->once())->method('cancelSubscriptionUpdate');

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('stripe/customer/subscriptions')
            ->willReturn($mockRedirectResult);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $this->helper,
            $mockSubscriptionsHelper,
            $mockUrlHelper,
            $this->order,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }

    /**
     * Test unknown parameter handling (redirect to clean URL)
     */
    public function testUnknownParamsRedirect()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with unknown parameters
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['unknown' => 'parameter']);

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->method('getControllerRedirect')
            ->with('stripe/customer/subscriptions')
            ->willReturn($mockRedirectResult);

        // Create controller with mocked dependencies
        $controller = new \StripeIntegration\Payments\Controller\Customer\Subscriptions(
            $this->resultPageFactory,
            $mockCustomerSession,
            $this->helper,
            $this->subscriptionsHelper,
            $mockUrlHelper,
            $this->order,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }
}