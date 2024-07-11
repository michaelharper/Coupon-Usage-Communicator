<?php
namespace MichaelHarper\CouponUsageCommunicator\Logger;

use Magento\Framework\Logger\Handler\Base;

class Handler extends Base
{
    protected $loggerType = \Monolog\Logger::INFO;
    protected $fileName = '/var/log/coupon_usage_communicator.log';
}
