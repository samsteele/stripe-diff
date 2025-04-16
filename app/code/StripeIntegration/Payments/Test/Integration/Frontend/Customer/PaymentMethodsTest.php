<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Customer;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ObjectManager;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Tests for Customer Payment Methods Controller
 */
class PaymentMethodsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $customerSession;
    private $urlHelper;
    private $helper;
    private $resultPageFactory;

    public function setUp(): void
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->customerSession = $this->objectManager->get(Session::class);
        $this->urlHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Url::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->resultPageFactory = $this->objectManager->get(\Magento\Framework\View\Result\PageFactory::class);
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

        $mockRedirect = $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockRedirect->method('create')->willReturn($mockRedirectResult);
        $mockRedirectResult->method('setPath')->with('customer/account/login')->willReturnSelf();

        $mockRequest = $this->createMock(HttpRequest::class);

        // Create controller with mocked dependencies - fixed parameter order
        $controller = new \StripeIntegration\Payments\Controller\Customer\PaymentMethods(
            $this->helper,
            $this->urlHelper,
            $this->resultPageFactory,
            $mockCustomerSession,
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

        // Create controller with the mocked dependencies - fixed parameter order
        $controller = new \StripeIntegration\Payments\Controller\Customer\PaymentMethods(
            $this->helper,
            $this->urlHelper,
            $mockResultPageFactory,
            $mockCustomerSession,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify that for logged in customers, it returns the result page
        $this->assertInstanceOf(\Magento\Framework\View\Result\Page::class, $result);
    }

    /**
     * Test redirect status handling
     */
    public function testRedirectStatus()
    {
        // Create a mock customer session that appears logged in
        $mockCustomerSession = $this->createMock(Session::class);
        $mockCustomerSession->method('isLoggedIn')->willReturn(true);

        // Create a mock request with redirect_status parameter
        $mockRequest = $this->createMock(HttpRequest::class);
        $mockRequest->method('getParams')->willReturn(['redirect_status' => 'succeeded']);

        // Mock the helper to verify success message is added
        $mockHelper = $this->createMock(\StripeIntegration\Payments\Helper\Generic::class);
        $mockHelper->expects($this->once())->method('addSuccess');

        // Mock URL helper for the redirect
        $mockUrlHelper = $this->createMock(\StripeIntegration\Payments\Helper\Url::class);
        $mockRedirectResult = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $mockUrlHelper->expects($this->once())
            ->method('getControllerRedirect')
            ->with('stripe/customer/paymentmethods')
            ->willReturn($mockRedirectResult);

        // Mock the result page factory
        $mockResultPageFactory = $this->createMock(\Magento\Framework\View\Result\PageFactory::class);

        // Create controller with mocked dependencies - fixed parameter order
        $controller = new \StripeIntegration\Payments\Controller\Customer\PaymentMethods(
            $mockHelper,
            $mockUrlHelper,
            $mockResultPageFactory,
            $mockCustomerSession,
            $mockRequest
        );

        // Execute the controller
        $result = $controller->execute();

        // Verify it's a redirect
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Redirect::class, $result);
    }
}