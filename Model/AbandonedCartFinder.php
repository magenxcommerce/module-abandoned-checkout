<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Finds candidate abandoned quotes with a single, index-friendly query.
 *
 * The DB-level time window uses the DEFAULT-scope delay / max-age as an
 * approximate bound to keep the candidate set small; the exact, store-scoped
 * timing decision is made per quote in the cron (ReminderProcessor) so a store
 * view that overrides the delay still behaves correctly.
 */
class AbandonedCartFinder
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly DateTime $date
    ) {
    }

    /**
     * @return array<int, array{entity_id:int, store_id:int, updated_at:string, notified_at:?string, reminder_count:int, base_grand_total:float}>
     */
    public function getCandidates(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quote');

        $now = $this->date->gmtTimestamp();
        // Coarse default-scope bounds; per-store timing is re-checked later.
        $notBefore = $this->date->gmtDate('Y-m-d H:i:s', $now - $this->config->getDelayMinutes() * 60);
        $notAfter = $this->date->gmtDate('Y-m-d H:i:s', $now - $this->config->getMaxAgeHours() * 3600);

        $select = $connection->select()
            ->from(
                $table,
                [
                    'entity_id',
                    'store_id',
                    'updated_at',
                    'notified_at' => 'magenx_abandoned_notified_at',
                    'reminder_count' => 'magenx_abandoned_reminder_count',
                    'base_grand_total',
                ]
            )
            ->where('is_active = ?', 1)
            ->where('items_count > 0')
            ->where('customer_email IS NOT NULL')
            ->where('customer_email <> ?', '')
            ->where('reserved_order_id IS NULL OR reserved_order_id = ?', '')
            ->where('updated_at <= ?', $notBefore)
            ->where('updated_at >= ?', $notAfter)
            ->where(
                'magenx_abandoned_notified_at IS NULL OR magenx_abandoned_reminder_count < ?',
                $this->config->getMaxReminders()
            )
            ->order('updated_at ASC')
            ->limit($this->config->getBatchSize());

        $rows = $connection->fetchAll($select);

        return array_map(
            static fn (array $row): array => [
                'entity_id' => (int) $row['entity_id'],
                'store_id' => (int) $row['store_id'],
                'updated_at' => (string) $row['updated_at'],
                'notified_at' => $row['notified_at'] !== null ? (string) $row['notified_at'] : null,
                'reminder_count' => (int) $row['reminder_count'],
                'base_grand_total' => (float) $row['base_grand_total'],
            ],
            $rows
        );
    }
}
