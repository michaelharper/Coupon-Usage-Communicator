<?php
namespace MichaelHarper\CouponUsageCommunicator\Plugin;

use Magento\SalesRule\Model\Rule;

class SalesRulePlugin
{
    public function afterGetData(Rule $subject, $result, $key = '', $index = null)
    {
        if ($key === '') {
            $additionalData = [
                'coupon_usage_communicator_enable' => $subject->getData('coupon_usage_communicator_enable'),
                'coupon_usage_communicator_emails' => $subject->getData('coupon_usage_communicator_emails'),
            ];
            $result = array_merge($result, $additionalData);
        }
        return $result;
    }
}
