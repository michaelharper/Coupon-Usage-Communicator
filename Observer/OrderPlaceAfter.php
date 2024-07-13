<?php
namespace MichaelHarper\CouponUsageCommunicator\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\Coupon;
use Psr\Log\LoggerInterface;
use Magento\Framework\Escaper;
use MichaelHarper\CouponUsageCommunicator\Logger\Logger;

class OrderPlaceAfter implements ObserverInterface
{
    protected $ruleFactory;
    protected $coupon;
    protected $scopeConfig;
    protected $transportBuilder;
    protected $storeManager;
    protected $logger;
    protected $escaper;

    public function __construct(
        RuleFactory $ruleFactory,
        Coupon $coupon,
        ScopeConfigInterface $scopeConfig,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        Logger $logger,
        Escaper $escaper
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->coupon = $coupon;
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->escaper = $escaper;
    }

    protected function isLoggingEnabled()
    {
        return $this->scopeConfig->isSetFlag('sales/coupon_communicator/enable_logging', ScopeInterface::SCOPE_STORE);
    }

    protected function log($message, $level = 'info')
    {
        if ($this->isLoggingEnabled()) {
            switch ($level) {
                case 'error':
                    $this->logger->error($message);
                    break;
                case 'critical':
                    $this->logger->critical($message);
                    break;
                case 'warning':
                    $this->logger->warning($message);
                    break;
                default:
                    $this->logger->info($message);
            }
        }
    }

    public function execute(Observer $observer)
    {
        try {
            $this->log('OrderPlaceAfter observer triggered');

            if (!$this->scopeConfig->isSetFlag('sales/coupon_communicator/enable', ScopeInterface::SCOPE_STORE)) {
                $this->log('Coupon Usage Communicator is disabled');
                return;
            }

            $order = $observer->getEvent()->getOrder();
            $couponCode = $order->getCouponCode();

            if (!$couponCode) {
                $this->log('No coupon code used in this order');
                return;
            }

            $this->log('Coupon code used: ' . $couponCode);

            $coupon = $this->coupon->loadByCode($couponCode);
            $rule = $this->ruleFactory->create()->load($coupon->getRuleId());
            $ruleName = $rule->getName() ?? 'N/A';

            $templateVars = [
                'order' => $order,
                'couponCode' => $this->escaper->escapeHtml($couponCode ?? 'N/A'),
                'orderIncrementId' => $this->escaper->escapeHtml($order->getIncrementId() ?? 'N/A'),
                'customerName' => $this->escaper->escapeHtml($order->getCustomerName() ?? 'Valued Customer'),
                'ruleName' => $this->escaper->escapeHtml($ruleName ?? 'N/A')
            ];

            $emails = $rule->getData('coupon_usage_communicator_emails');

            if (empty($emails)) {
                $this->log('No emails configured for this coupon rule');
                return;
            }

            $this->log('Emails configured for this coupon: ' . $emails);

            $emailsArray = is_string($emails) ? explode(',', $emails) : [];

            if (empty($emailsArray)) {
                $this->log('No valid emails found after parsing');
                return;
            }

            // Log the variables before sending the email
            $this->log('Template variables: ' . json_encode([
                    'order' => $order,
                    'orderIncrementId' => $order->getIncrementId(),
                    'couponCode' => $couponCode,
                    'customerName' => $order->getCustomerName(),
                    'ruleName' => $ruleName,
                ]));

            foreach ($emailsArray as $email) {
                try {
                    $this->log('Template variables: ' . json_encode([
                            'order' => $order,
                            'orderIncrementId' => $order->getIncrementId(),
                            'couponCode' => $couponCode,
                            'customerName' => $order->getCustomerName(),
                            'ruleName' => $ruleName,
                        ]));

                    $subjectVars = [
                        'incrementId' => $order->getIncrementId() ?? 'N/A',
                        'couponCode' => $couponCode ?? 'N/A'
                    ];

                    $incrementId = $order->getIncrementId() ?? 'N/A';
                    $couponCodeForSubject = $couponCode ?? 'N/A';

                    $subject = __('New order %1 placed with coupon %2', $incrementId, $couponCodeForSubject);

                    // Log the subject and its components for debugging
                    $this->log("Preparing email subject. Order ID: $incrementId, Coupon: $couponCodeForSubject");
                    $this->log("Generated subject: $subject");

                    $requiredVars = ['orderIncrementId', 'couponCode', 'customerName', 'ruleName'];
                    foreach ($requiredVars as $var) {
                        if (!isset($templateVars[$var]) || $templateVars[$var] === null) {
                            $this->log("Required variable '$var' is null or not set. Setting default value.", 'warning');
                            $templateVars[$var] = 'N/A';
                        }
                    }

                    $transport = $this->transportBuilder
                        ->setTemplateIdentifier('coupon_usage_notification')
                        ->setTemplateOptions([
                            'area' => 'frontend',
                            'store' => $this->storeManager->getStore()->getId(),
                            'subject' => $subject  // Add the subject here
                        ])
                        ->setTemplateVars($templateVars)
                        ->setFrom('general')
                        ->addTo(trim($email))
                        ->getTransport();

                    // Check if setSubject method exists (for compatibility with different Magento versions)
//                    if (method_exists($this->transportBuilder, 'setSubject')) {
//                        $transport = $transport->setSubject($subject);
//                    } else {
//                        // If setSubject doesn't exist, try to set it in template options
//                        $transport = $this->transportBuilder->setTemplateOptions([
//                            'area' => 'frontend',
//                            'store' => $this->storeManager->getStore()->getId(),
//                            'subject' => $subject
//                        ]);
//                    }

                    $this->log('About to send email to: ' . $email);
                    $transport->sendMessage();
                    $this->log('Email sent successfully to: ' . $email);

                } catch (\Exception $e) {
                    $this->log('Failed to send email to ' . $email . '. Error: ' . $e->getMessage(), 'error');
                    $this->log($e->getTraceAsString(), 'error');
                }
            }


        } catch (\Exception $e) {
            $this->log('Exception in OrderPlaceAfter observer: ' . $e->getMessage(), 'critical');
            $this->log($e->getTraceAsString(), 'critical');
            // Don't re-throw the exception, as this would prevent the order from being placed
        }
    }

}
