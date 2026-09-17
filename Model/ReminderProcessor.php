<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magenx\AbandonedCheckout\Model\ResourceModel\Reminder as ReminderResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the exact, store-scoped eligibility rules to one candidate quote and,
 * if it passes, sends the reminder and stamps the quote.
 *
 * The attempt is CLAIMED before the send, not stamped after it. Claiming first
 * is what makes a concurrent run (a second app server, a manual `cron:run`
 * overlapping the scheduler) safe, and it is also what stops a cart that can
 * never be sent from being retried on every tick — the claim moves it past the
 * finder's attempt-spacing filter either way. The success counter is written
 * separately, afterwards, so a transient mail failure still retries once the
 * backoff has passed.
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
     * @param array{entity_id:int, store_id:int, updated_at:string, notified_at:?string, reminder_count:int} $candidate
     * @return bool True if a reminder was actually sent.
     */
    public function process(array $candidate): bool
    {
        $quoteId = $candidate['entity_id'];
        $storeId = $candidate['store_id'];

        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable $e) {
            // Orphaned store id — nothing we can do, and no point claiming it.
            $this->logger->debug(
                'Magenx_AbandonedCheckout: could not load store ' . $storeId . ': ' . $e->getMessage()
            );
            return false;
        }

        if (!$this->config->isEnabled($storeId) || !$this->isDue($candidate, $storeId)) {
            return false;
        }

        // Compare-and-swap on the attempt stamp: whoever wins owns this send.
        if (!$this->reminderResource->claimAttempt($quoteId, $candidate['notified_at'], $this->date->gmtDate())) {
            return false;
        }

        try {
            /** @var Quote $quote */
            $quote = $this->quoteRepository->get($quoteId);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Magenx_AbandonedCheckout: could not load quote ' . $quoteId . ': ' . $e->getMessage()
            );
            return false;
        }

        // Guard against a race: the cart may have been ordered/emptied between
        // the candidate query and now.
        if (!$quote->getIsActive() || !$quote->getCustomerEmail() || !count($quote->getAllVisibleItems())) {
            return false;
        }

        // Read the total off the loaded quote, not off the candidate row: the
        // row's base_grand_total is whatever the last totals collection left
        // there and is NULL on a cart whose totals were never collected.
        $minSubtotal = $this->config->getMinSubtotal($storeId);
        if ($minSubtotal > 0 && (float) $quote->getBaseGrandTotal() < $minSubtotal) {
            return false;
        }

        try {
            $this->sender->send($quote, $store);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Magenx_AbandonedCheckout: failed to send reminder for quote '
                . $quoteId . ': ' . $e->getMessage()
            );
            return false;
        }

        return $this->reminderResource->markNotified($quoteId, $candidate['reminder_count'], $this->date->gmtDate());
    }

    /**
     * Exact, store-scoped timing check (the bounds in the finder are only coarse).
     *
     * @param array{updated_at:string, notified_at:?string, reminder_count:int} $candidate
     */
    private function isDue(array $candidate, int $storeId): bool
    {
        if ($candidate['reminder_count'] >= $this->config->getMaxReminders($storeId)) {
            return false;
        }

        $updatedAt = strtotime($candidate['updated_at']);
        if ($updatedAt === false) {
            return false;
        }

        $now = $this->date->gmtTimestamp();
        $age = $now - $updatedAt;

        if ($age < $this->config->getDelayMinutes($storeId) * 60) {
            return false;
        }
        if ($age > $this->config->getMaxAgeHours($storeId) * 3600) {
            return false;
        }

        if ($candidate['notified_at'] === null) {
            return true;
        }

        $lastAttempt = strtotime($candidate['notified_at']);
        if ($lastAttempt === false) {
            return false;
        }

        // A zero count means every attempt so far FAILED (the counter only moves
        // on a successful send), so the short retry backoff applies rather than
        // the much longer spacing meant for genuine follow-up reminders.
        $spacingMinutes = $candidate['reminder_count'] === 0
            ? $this->config->getRetryBackoffMinutes($storeId)
            : $this->config->getRepeatIntervalMinutes($storeId);

        return ($now - $lastAttempt) >= $spacingMinutes * 60;
    }
}
