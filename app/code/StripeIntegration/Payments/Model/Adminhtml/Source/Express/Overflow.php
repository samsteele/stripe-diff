<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Express;

class Overflow
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'auto',
                'label' => __('Automatic')
            ],
            [
                'value' => 'never',
                'label' => __('Expanded')
            ]
        ];
    }
}
