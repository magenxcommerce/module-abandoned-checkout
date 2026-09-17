<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Cron;

use Magenx\AbandonedCheckout\Model\AbandonedCartFinder;
use Magenx\AbandonedCheckout\Model\RecipientQuota;
use Magenx\AbandonedCheckout\Model\ReminderProcessor;
use Psr\Log\LoggerInterface;

/**
 * Cron entry point: fetch a batch of abandoned carts and send reminders.
 *
 * All decisions live in the finder (candidate SQL) and the processor (per-store
 * timing / min-total / enablement); the cron just iterates and reports.
 */
class SendReminders
{
    public function __construct(
        private readonly AbandonedCartFinder $finder,
        private readonly ReminderProcessor $processor,
        private readonly RecipientQuota $quota,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $candidates = $this->finder->getCandidates();
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_AbandonedCheckout: candidate query failed: ' . $e->getMessage());
            return;
        }

        if ($candidates === []) {
            return;
        }

        // One grouped query for the whole batch rather than one per candidate.
        $this->quota->prime(array_column($candidates, 'customer_email'));

        $sent = 0;
        foreach ($candidates as $candidate) {
            if ($this->processor->process($candidate)) {
                $sent++;
            }
        }

        // Logged whenever there were candidates, including when NONE of them
        // were sent. A run that finds work and does nothing with it is the shape
        // a stalled batch takes, and logging only the happy path made it
        // indistinguishable from an idle store.
        $this->logger->info(
            sprintf(
                'Magenx_AbandonedCheckout: sent %d reminder(s), skipped %d, out of %d candidate(s).',
                $sent,
                count($candidates) - $sent,
                count($candidates)
            )
        );
    }
}
