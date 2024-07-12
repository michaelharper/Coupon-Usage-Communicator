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

class OrderPlaceAfter implements ObserverInterface
{
    protected $ruleFactory;
    protected $coupon;
    protected $scopeConfig;
    protected $transportBuilder;
    protected $storeManager;
    protected $logger;

    public function __construct(
        RuleFactory $ruleFactory,
        Coupon $coupon,
        ScopeConfigInterface $scopeConfig,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->coupon = $coupon;
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $this->logger->info('OrderPlaceAfter observer triggered');

            if (!$this->scopeConfig->isSetFlag('sales/coupon_communicator/enable', ScopeInterface::SCOPE_STORE)) {
                $this->logger->info('Coupon Usage Communicator is disabled');
                return;
            }

            $order = $observer->getEvent()->getOrder();
            $couponCode = $order->getCouponCode();

            if (!$couponCode) {
                $this->logger->info('No coupon code used in this order');
                return;
            }

            $this->logger->info('Coupon code used: ' . $couponCode);

            $coupon = $this->coupon->loadByCode($couponCode);
            $rule = $this->ruleFactory->create()->load($coupon->getRuleId());

            $emails = $rule->getData('coupon_usage_communicator_emails');

            if (empty($emails)) {
                $this->logger->info('No emails configured for this coupon rule');
                return;
            }

            $this->logger->info('Emails configured for this coupon: ' . $emails);

            $emailsArray = is_string($emails) ? explode(',', $emails) : [];

            if (empty($emailsArray)) {
                $this->logger->info('No valid emails found after parsing');
                return;
            }

            // Log the variables before sending the email
            $this->logger->info('Template variables: ' . json_encode([
                    'orderIncrementId' => $order->getIncrementId(),
                    'couponCode' => $couponCode,
                    'customerName' => $order->getCustomerName()
                ]));

            foreach ($emailsArray as $email) {
                try {
                    $this->logger->info('Template variables: ' . json_encode([
                            'orderIncrementId' => $order->getIncrementId(),
                            'couponCode' => $couponCode,
                            'customerName' => $order->getCustomerName()
                        ]));

                    $transport = $this->transportBuilder
                        ->setTemplateIdentifier('coupon_usage_notification')
                        ->setTemplateOptions([
                            'area' => 'frontend',
                            'store' => $this->storeManager->getStore()->getId()
                        ])
                        ->setTemplateVars([
                            'orderIncrementId' => $order->getIncrementId(),
                            'couponCode' => $couponCode,
                            'customerName' => $order->getCustomerName()
                        ])
                        ->setFrom('general')
                        ->addTo(trim($email))
                        ->getTransport();

                    $transport->sendMessage();
                    $this->logger->info('Email sent successfully to: ' . $email);

                } catch (\Exception $e) {
                    $this->logger->error('Failed to send email to ' . $email . '. Error: ' . $e->getMessage());
                }
            }


        } catch (\Exception $e) {
            $this->logger->critical('Exception in OrderPlaceAfter observer: ' . $e->getMessage());
            $this->logger->critical($e->getTraceAsString());
            // Don't re-throw the exception, as this would prevent the order from being placed
        }
    }

}
