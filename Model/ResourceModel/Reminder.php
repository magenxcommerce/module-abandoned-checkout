<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Writes reminder bookkeeping directly on the quote row.
 *
 * A targeted UPDATE (not a quote model save) is used on purpose so the quote's
 * own updated_at is NOT bumped — otherwise sending a reminder would reset the
 * abandoned clock and the cart could never satisfy the max-age / repeat rules.
 *
 * The two columns mean different things and are written separately:
 *   magenx_abandoned_notified_at    — last ATTEMPT (successful or not)
 *   magenx_abandoned_reminder_count — successful sends only
 * so a cart whose send keeps failing has count 0 with a recent attempt, which
 * is what lets the finder back it off instead of retrying it every tick.
 */
class Reminder
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Record a successful send: stamp the attempt time and bump the counter.
     *
     * The counter is set to an expected value rather than incremented in SQL so
     * the UPDATE doubles as an optimistic claim — if a concurrent cron run (a
     * second app server, or a manual `cron:run` overlapping the scheduler)
     * already sent this reminder, the row no longer matches and we affect zero
     * rows instead of counting the same send twice.
     *
     * @return bool False when another process got there first.
     */
    public function markNotified(int $quoteId, int $expectedCount, string $nowGmt): bool
    {
        $connection = $this->resource->getConnection();

        $affected = $connection->update(
            $this->resource->getTableName('quote'),
            [
                'magenx_abandoned_notified_at' => $nowGmt,
                'magenx_abandoned_reminder_count' => $expectedCount + 1,
            ],
            [
                'entity_id = ?' => $quoteId,
                'magenx_abandoned_reminder_count = ?' => $expectedCount,
            ]
        );

        return $affected > 0;
    }

    /**
     * Claim this quote's next send attempt, atomically.
     *
     * Compare-and-swap on the attempt stamp: the UPDATE only lands if the row
     * still carries the value the candidate query read, so exactly one process
     * can own a given attempt. Winning also moves the row past the finder's
     * attempt-spacing filter, which is what keeps a cart that can never be sent
     * (a malformed customer_email, say) from sitting at the head of every batch
     * — the finder orders by updated_at, and only a write moves a row out of
     * the way.
     *
     * @param string|null $expectedNotifiedAt The value read by the candidate query.
     * @return bool False when another process claimed it first.
     */
    public function claimAttempt(int $quoteId, ?string $expectedNotifiedAt, string $nowGmt): bool
    {
        $connection = $this->resource->getConnection();

        // `<=>` rather than `=` so the very first claim, where the stored value
        // is NULL, compares equal instead of evaluating to NULL.
        $affected = $connection->update(
            $this->resource->getTableName('quote'),
            ['magenx_abandoned_notified_at' => $nowGmt],
            [
                'entity_id = ?' => $quoteId,
                'magenx_abandoned_notified_at <=> ?' => $expectedNotifiedAt,
            ]
        );

        return $affected > 0;
    }
}
