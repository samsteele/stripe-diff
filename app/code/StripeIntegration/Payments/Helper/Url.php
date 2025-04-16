<?php

namespace StripeIntegration\Payments\Helper;

class Url
{
    private $urlBuilder;
    private $resultFactory;
    private $request;

    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\App\Request\Http $request
    )
    {
        $this->urlBuilder = $urlBuilder;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
    }

    public function getUrl($path, $additionalParams = [])
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->urlBuilder->getUrl($path, $params + $additionalParams);
    }

    public function getControllerRedirect($path, $additionalParams = [])
    {
        $resultRedirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath($path, $additionalParams);
        return $resultRedirect;
    }
}