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
 */
class Reminder
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Stamp the last-notified time and bump the reminder counter.
     */
    public function markNotified(int $quoteId, string $notifiedAtGmt): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quote');

        $connection->update(
            $table,
            [
                'magenx_abandoned_notified_at' => $notifiedAtGmt,
                'magenx_abandoned_reminder_count' => new \Zend_Db_Expr('magenx_abandoned_reminder_count + 1'),
            ],
            ['entity_id = ?' => $quoteId]
        );
    }
}
