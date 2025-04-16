<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

// See \Magento\Framework\Stdlib\DateTime
class DateTime
{
    public function dbTimestamp()
    {
        $dateTime = new \DateTime();
        return $dateTime->getTimestamp();
    }

    public function dbDate($timestamp)
    {
        return (new \DateTime())->setTimestamp($timestamp)->format('Y-m-d');
    }
}