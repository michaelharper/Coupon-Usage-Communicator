<?php
namespace MichaelHarper\CouponUsageCommunicator\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $loggerType = Logger::INFO;
    protected $fileName = '/var/log/coupon_usage_communicator.log';
}
