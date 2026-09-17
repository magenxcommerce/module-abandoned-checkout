<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Caps how many reminders one address can receive, whatever cart they came from.
 *
 * A quote's customer_email is never verified — a guest types it, and on a
 * headless storefront `setGuestEmailOnCart` is a public mutation. So anyone can
 * mint carts addressed to someone else and have the store mail them. Per cart
 * that is bounded (one reminder, after the delay), but the number of CARTS is
 * not, which makes a single address unboundedly spammable. This is the control
 * for that: the cap is per recipient, so minting more carts stops helping.
 *
 * Counts are deliberately global rather than per store view — otherwise the
 * same attacker just spreads the carts across store views.
 *
 * Primed once per cron run with the batch's addresses (one grouped query rather
 * than one per candidate), then kept up to date in memory as the run sends, so
 * a batch that is ALL the same address — the obvious shape of the abuse — is
 * caught within the run and not only on the next one.
 *
 * That in-run tally means the cron and the processor MUST share one instance.
 * They do: Magento's DI treats a concrete type as shared unless told otherwise,
 * so do not mark this `shared="false"` in a di.xml.
 */
class RecipientQuota
{
    /** @var array<string, int> Lowercased address => reminders already sent in the window. */
    private array $counts = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly DateTime $date
    ) {
    }

    /**
     * Load the recent send counts for the addresses in this batch.
     *
     * @param string[] $emails
     */
    public function prime(array $emails): void
    {
        $this->counts = [];

        if (!$this->config->isPerEmailCapEnabled()) {
            return;
        }

        $emails = array_values(array_unique(array_filter($emails)));
        if ($emails === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $windowStart = $this->date->gmtDate(
            'Y-m-d H:i:s',
            $this->date->gmtTimestamp() - $this->config->getPerEmailWindowDays() * 86400
        );

        $select = $connection->select()
            ->from($this->resource->getTableName('quote'), [
                'customer_email',
                // SUM, not COUNT: a quote may carry several reminders. It can
                // over-count when max_reminders > 1, because the stamp records
                // only the LAST attempt and an older reminder on the same quote
                // is counted as if it were inside the window. That errs toward
                // withholding a reminder, which is the right direction for a
                // cap whose job is to stop someone being mailed repeatedly.
                'sent' => new Expression('SUM(magenx_abandoned_reminder_count)'),
            ])
            ->where('customer_email IN (?)', $emails)
            ->where('magenx_abandoned_reminder_count > 0')
            ->where('magenx_abandoned_notified_at >= ?', $windowStart)
            ->group('customer_email');

        foreach ($connection->fetchAll($select) as $row) {
            $this->counts[$this->key((string) $row['customer_email'])] = (int) $row['sent'];
        }
    }

    /**
     * Whether this address may still be sent to.
     */
    public function allows(string $email): bool
    {
        if (!$this->config->isPerEmailCapEnabled()) {
            return true;
        }

        return ($this->counts[$this->key($email)] ?? 0) < $this->config->getMaxRemindersPerEmail();
    }

    /**
     * Count a reminder this run just sent, so the rest of the batch sees it.
     */
    public function record(string $email): void
    {
        $key = $this->key($email);
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    /**
     * MySQL's default collation compares addresses case-insensitively, so the
     * in-memory tally has to agree with the query that filled it.
     */
    private function key(string $email): string
    {
        return strtolower(trim($email));
    }
}
