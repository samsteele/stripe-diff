<?php

namespace StripeIntegration\Tax\Helper;

class DateTime
{
    public function getTimestampInMilliseconds()
    {
        $date = new \DateTime();
        $timestamp = (float)$date->format('U.u');

        return round($timestamp * 1000);
    }
}