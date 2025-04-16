<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Tax\BaseOrderValues;

class AbstractBaseValues extends \PHPUnit\Framework\TestCase
{
    public function getBaseValuesArray($array)
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result['base_' . $key] = $value;
        }

        return $result;
    }
}