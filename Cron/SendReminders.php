<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Cron;

use Magenx\AbandonedCheckout\Model\AbandonedCartFinder;
use Magenx\AbandonedCheckout\Model\ReminderProcessor;
use Psr\Log\LoggerInterface;

/**
 * Cron entry point: fetch a batch of abandoned carts and send reminders.
 *
 * Enablement is per store, so the cron always runs the (single, indexed)
 * candidate query and lets the processor apply the per-store eligibility check
 * — this correctly handles a store view that overrides the default-scope flag
 * on. All decisions live in the finder (candidate SQL) and the processor
 * (per-store timing / min-total / enablement); the cron just iterates.
 */
class SendReminders
{
    public function __construct(
        private readonly AbandonedCartFinder $finder,
        private readonly ReminderProcessor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $candidates = $this->finder->getCandidates();
        if ($candidates === []) {
            return;
        }

        $sent = 0;
        foreach ($candidates as $candidate) {
            if ($this->processor->process($candidate)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->logger->info(
                sprintf(
                    'Magenx_AbandonedCheckout: sent %d reminder(s) out of %d candidate(s).',
                    $sent,
                    count($candidates)
                )
            );
        }
    }
}
