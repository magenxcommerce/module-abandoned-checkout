<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magenx\AbandonedCheckout\Model\ResourceModel\Reminder as ReminderResource;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the exact, store-scoped eligibility rules to one candidate quote and,
 * if it passes, sends the reminder and stamps the quote.
 */
class ReminderProcessor
{
    public function __construct(
        private readonly Config $config,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReminderSender $sender,
        private readonly ReminderResource $reminderResource,
        private readonly DateTime $date,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{entity_id:int, store_id:int, updated_at:string, notified_at:?string, reminder_count:int, base_grand_total:float} $candidate
     * @return bool True if a reminder was actually sent.
     */
    public function process(array $candidate): bool
    {
        $storeId = $candidate['store_id'];

        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable $e) {
            // Orphaned store id — nothing we can do.
            $this->logger->debug(
                'Magenx_AbandonedCheckout: could not load store ' . $storeId . ': ' . $e->getMessage()
            );
            return false;
        }

        if (!$this->config->isEnabled($storeId)) {
            return false;
        }

        if (!$this->isDue($candidate, $storeId)) {
            return false;
        }

        $minSubtotal = $this->config->getMinSubtotal($storeId);
        if ($minSubtotal > 0 && $candidate['base_grand_total'] < $minSubtotal) {
            return false;
        }

        try {
            /** @var Quote $quote */
            $quote = $this->quoteRepository->get($candidate['entity_id']);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Magenx_AbandonedCheckout: could not load quote '
                . $candidate['entity_id'] . ': ' . $e->getMessage()
            );
            return false;
        }

        // Guard against a race: the cart may have been ordered/emptied between
        // the candidate query and now.
        if (!$quote->getIsActive() || !$quote->getCustomerEmail() || !count($quote->getAllVisibleItems())) {
            return false;
        }

        try {
            $this->sender->send($quote, $store);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Magenx_AbandonedCheckout: failed to send reminder for quote '
                . $candidate['entity_id'] . ': ' . $e->getMessage()
            );
            return false;
        }

        // Only stamp AFTER a successful send, so a transient mail failure is retried.
        $this->reminderResource->markNotified(
            $candidate['entity_id'],
            $this->date->gmtDate()
        );

        return true;
    }

    /**
     * Exact, store-scoped timing check (the DB window in the finder is only coarse).
     *
     * @param array{updated_at:string, notified_at:?string, reminder_count:int} $candidate
     */
    private function isDue(array $candidate, $storeId): bool
    {
        if ($candidate['reminder_count'] >= $this->config->getMaxReminders($storeId)) {
            return false;
        }

        $now = $this->date->gmtTimestamp();
        $age = $now - strtotime($candidate['updated_at']);

        if ($age < $this->config->getDelayMinutes($storeId) * 60) {
            return false;
        }
        if ($age > $this->config->getMaxAgeHours($storeId) * 3600) {
            return false;
        }

        // Space out follow-ups.
        if ($candidate['notified_at'] !== null) {
            $sinceLast = $now - strtotime($candidate['notified_at']);
            if ($sinceLast < $this->config->getRepeatIntervalMinutes($storeId) * 60) {
                return false;
            }
        }

        return true;
    }
}
