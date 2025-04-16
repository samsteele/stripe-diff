<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Logger\CheckoutCrash;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $loggerType = Logger::CRITICAL;

    public function __construct(
        \Magento\Framework\Filesystem\DriverInterface $filesystem,
        \Magento\Framework\App\Filesystem\DirectoryList $dir
    ) {
        $filePath = $dir->getPath('log') . DIRECTORY_SEPARATOR;
        parent::__construct($filesystem, $filePath, 'stripe_checkout_crashes.log');
    }
}
