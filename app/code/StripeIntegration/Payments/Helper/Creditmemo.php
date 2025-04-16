<?php

namespace StripeIntegration\Payments\Helper;

class Creditmemo
{
    private $creditmemoRepository;
    private $creditmemoManagement;

    public function __construct(
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Sales\Api\CreditmemoManagementInterface $creditmemoManagement
    ) {
        $this->creditmemoRepository = $creditmemoRepository;
        $this->creditmemoManagement = $creditmemoManagement;
    }

    public function saveCreditmemo($creditmemo)
    {
        return $this->creditmemoRepository->save($creditmemo);
    }

    public function refundCreditmemo($creditmemo, $offline = false)
    {
        $this->creditmemoManagement->refund($creditmemo, $offline);
    }
}
