<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Helper;

class Error
{
    private $display = false;

    public function isMOTOError(\Stripe\ErrorObject $error)
    {
        if (empty($error->code))
            return false;

        if (empty($error->param))
            return false;

        if ($error->code != "parameter_unknown")
            return false;

        if ($error->param != "payment_method_options[card][moto]")
            return false;

        return true;
    }

    /**
     * Get the display status
     */
    public function getDisplay(): bool
    {
        return $this->display;
    }

    /**
     * Set the display status
     *
     * @param bool $value
     */
    public function setDisplay(bool $value): void
    {
        $this->display = $value;
    }
}