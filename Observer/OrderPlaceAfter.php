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

        if (!$emails) {
            $this->logger->info('No emails configured for this coupon rule');
            return;
        }

        $this->logger->info('Emails configured for this coupon: ' . $emails);

        $emailsArray = explode(',', $emails);

        foreach ($emailsArray as $email) {
            try {
                $transport = $this->transportBuilder
                    ->setTemplateIdentifier('coupon_usage_notification')
                    ->setTemplateOptions([
                        'area' => 'frontend',
                        'store' => $this->storeManager->getStore()->getId()
                    ])
                    ->setTemplateVars(['order' => $order, 'couponCode' => $couponCode])
                    ->setFrom('general')
                    ->addTo(trim($email))
                    ->getTransport();

                $transport->sendMessage();
                $this->logger->info('Email sent successfully to: ' . $email);

                // Uncomment the following line to save email content for debugging
                file_put_contents('/var/www/html/var/log/email_content.html', $transport->getMessage()->getBody()->generateBody());

            } catch (\Exception $e) {
                $this->logger->error('Failed to send email to ' . $email . '. Error: ' . $e->getMessage());
            }
        }
    }
}
