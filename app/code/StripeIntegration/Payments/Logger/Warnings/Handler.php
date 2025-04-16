<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Logger\Warnings;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use StripeIntegration\Payments\Model\Config;

class Handler extends Base
{
    protected $loggerType = Logger::WARNING;

    public function __construct(
        \Magento\Framework\Filesystem\DriverInterface $filesystem,
        \Magento\Framework\App\Filesystem\DirectoryList $dir
    ) {
        $filePath = $dir->getPath('log') . DIRECTORY_SEPARATOR;
        parent::__construct($filesystem, $filePath, 'stripe_warnings.log');

        $prefix = Config::$moduleVersion;
        $output = "[%datetime% v$prefix] %message% %context% %extra%\n";
        $formatter = new LineFormatter($output);

        $this->setFormatter($formatter);
    }
}
