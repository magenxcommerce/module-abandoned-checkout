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
 * Two things this query has to get right, both of which it previously did not:
 *
 * 1. It must not exclude a cart some store view would still want reminded, so
 *    the SQL bounds are the MOST PERMISSIVE value in use across the enabled
 *    stores (Config's aggregate getters), not the default-scope value. The
 *    exact, store-scoped decision stays in ReminderProcessor.
 *
 * 2. It must not hand the same ineligible rows back on every run. The batch is
 *    `ORDER BY updated_at ASC LIMIT n`, and a row is only ever moved by a
 *    write, so anything that is a candidate in SQL but rejected downstream sits
 *    at the head of the batch until it ages out — one cart with an unsendable
 *    address used to be able to occupy a slot every 15 minutes for a week.
 *    Hence the store filter, the reminder-count filter and the attempt-spacing
 *    filter below: rejections are expressed in SQL wherever possible, and
 *    ReminderProcessor stamps an attempt on the rest.
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
     * @return array<int, array{entity_id:int, store_id:int, updated_at:string,
     *     notified_at:?string, reminder_count:int}>
     */
    public function getCandidates(): array
    {
        $enabledStoreIds = $this->config->getEnabledStoreIds();
        if ($enabledStoreIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $now = $this->date->gmtTimestamp();
        $newestUpdatedAt = $this->date->gmtDate('Y-m-d H:i:s', $now - $this->config->getMinDelayMinutes() * 60);
        $oldestUpdatedAt = $this->date->gmtDate('Y-m-d H:i:s', $now - $this->config->getMaxOfMaxAgeHours() * 3600);
        $attemptCutoff = $this->date->gmtDate(
            'Y-m-d H:i:s',
            $now - $this->config->getMinAttemptSpacingMinutes() * 60
        );

        $select = $connection->select()
            ->from(
                $this->resource->getTableName('quote'),
                [
                    'entity_id',
                    'store_id',
                    'updated_at',
                    'notified_at' => 'magenx_abandoned_notified_at',
                    'reminder_count' => 'magenx_abandoned_reminder_count',
                ]
            )
            // is_active + reminder_count + updated_at are the index columns, in
            // order. With the usual max_reminders = 1 the count filter is a
            // single-value IN, so the optimizer gets equality / equality / range
            // and can serve the ORDER BY ... LIMIT from the index instead of
            // scanning the whole time window on every run.
            ->where('is_active = ?', 1)
            ->where('magenx_abandoned_reminder_count IN (?)', range(0, $this->config->getMaxOfMaxReminders() - 1))
            ->where('updated_at <= ?', $newestUpdatedAt)
            ->where('updated_at >= ?', $oldestUpdatedAt)
            ->where('store_id IN (?)', $enabledStoreIds)
            ->where('items_count > 0')
            // `<> ''` already excludes NULL (NULL <> '' is NULL, not true), so
            // there is no separate IS NOT NULL test to make.
            ->where('customer_email <> ?', '')
            ->where('reserved_order_id IS NULL OR reserved_order_id = ?', '')
            // Space out repeat reminders AND back off carts whose last attempt
            // failed. One clause covers both because the row alone cannot tell
            // them apart; ReminderProcessor applies the exact rule.
            ->where('magenx_abandoned_notified_at IS NULL OR magenx_abandoned_notified_at <= ?', $attemptCutoff)
            ->order('updated_at ASC')
            ->limit($this->config->getBatchSize());

        // Coarse bound only, and never selected: the exact check runs against the
        // LOADED quote, because a cart whose totals were never collected has a
        // NULL base_grand_total here and would otherwise be dropped silently.
        $minSubtotal = $this->config->getMinOfMinSubtotal();
        if ($minSubtotal > 0) {
            $select->where('base_grand_total IS NULL OR base_grand_total >= ?', $minSubtotal);
        }

        return array_map(
            static fn (array $row): array => [
                'entity_id' => (int) $row['entity_id'],
                'store_id' => (int) $row['store_id'],
                'updated_at' => (string) $row['updated_at'],
                'notified_at' => $row['notified_at'] !== null ? (string) $row['notified_at'] : null,
                'reminder_count' => (int) $row['reminder_count'],
            ],
            $connection->fetchAll($select)
        );
    }
}
