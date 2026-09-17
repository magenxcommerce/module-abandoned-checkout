<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Typed accessor for the abandoned-checkout configuration.
 *
 * Besides the per-store getters, this exposes "across every ENABLED store"
 * aggregates. The candidate query needs a single set of SQL bounds that cannot
 * exclude a cart some store view would still want reminded, so it asks for the
 * most permissive value in use rather than the default-scope one.
 */
class Config
{
    private const XML_ENABLED = 'magenx_abandoned_checkout/general/enabled';
    private const XML_DELAY_MINUTES = 'magenx_abandoned_checkout/general/delay_minutes';
    private const XML_MAX_AGE_HOURS = 'magenx_abandoned_checkout/general/max_age_hours';
    private const XML_MAX_REMINDERS = 'magenx_abandoned_checkout/general/max_reminders';
    private const XML_REPEAT_INTERVAL = 'magenx_abandoned_checkout/general/repeat_interval_minutes';
    private const XML_RETRY_BACKOFF = 'magenx_abandoned_checkout/general/retry_backoff_minutes';
    private const XML_BATCH_SIZE = 'magenx_abandoned_checkout/general/batch_size';
    private const XML_MIN_SUBTOTAL = 'magenx_abandoned_checkout/general/min_subtotal';
    private const XML_MAX_PER_EMAIL = 'magenx_abandoned_checkout/general/max_reminders_per_email';
    private const XML_PER_EMAIL_WINDOW_DAYS = 'magenx_abandoned_checkout/general/per_email_window_days';
    private const XML_TEMPLATE = 'magenx_abandoned_checkout/email/email_template';
    private const XML_SENDER = 'magenx_abandoned_checkout/email/sender_email_identity';
    private const XML_RECOVERY_PATH = 'magenx_abandoned_checkout/email/recovery_path';
    private const XML_RECOVERY_SECRET = 'magenx_abandoned_checkout/email/recovery_secret';
    private const XML_TOKEN_TTL_HOURS = 'magenx_abandoned_checkout/email/token_ttl_hours';
    private const XML_UNSUBSCRIBE_PATH = 'magenx_abandoned_checkout/email/unsubscribe_path';

    /** @var int[]|null Memoised for the lifetime of one cron run. */
    private ?array $enabledStoreIds = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDelayMinutes(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_DELAY_MINUTES, $storeId);
    }

    public function getMaxAgeHours(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_MAX_AGE_HOURS, $storeId);
    }

    public function getMaxReminders(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_MAX_REMINDERS, $storeId);
    }

    public function getRepeatIntervalMinutes(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_REPEAT_INTERVAL, $storeId);
    }

    /**
     * How long to wait before re-attempting a cart whose last send attempt failed
     * (or that was skipped for a reason that will not change on the next tick).
     */
    public function getRetryBackoffMinutes(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_RETRY_BACKOFF, $storeId);
    }

    /**
     * Batch size is read at default scope — the cron processes all stores in one pass.
     */
    public function getBatchSize(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_BATCH_SIZE));
    }

    /**
     * Reminders one address may receive across ALL carts in the window, or 0 for
     * no cap.
     *
     * Read at default scope, like the window below and the batch size: this is
     * an abuse control rather than a marketing knob, and the counts behind it
     * are global, so letting a single store view raise its own cap would just
     * be a way around it.
     */
    public function getMaxRemindersPerEmail(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_MAX_PER_EMAIL));
    }

    public function getPerEmailWindowDays(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_PER_EMAIL_WINDOW_DAYS));
    }

    public function isPerEmailCapEnabled(): bool
    {
        return $this->getMaxRemindersPerEmail() > 0;
    }

    public function getMinSubtotal(?int $storeId = null): float
    {
        return max(0.0, (float) $this->scopeConfig->getValue(
            self::XML_MIN_SUBTOTAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getTemplateId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_TEMPLATE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSenderIdentity(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_SENDER, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getRecoveryPath(?int $storeId = null): string
    {
        $path = $this->scopeConfig->getValue(self::XML_RECOVERY_PATH, ScopeInterface::SCOPE_STORE, $storeId);

        return trim((string) $path, '/') ?: 'cart/recover';
    }

    /**
     * Optional storefront path where a recipient can opt out of these reminders.
     * Empty (the default) renders no unsubscribe link — see README for why you
     * almost certainly want to set it before sending to real shoppers.
     */
    public function getUnsubscribePath(?int $storeId = null): string
    {
        $path = $this->scopeConfig->getValue(self::XML_UNSUBSCRIBE_PATH, ScopeInterface::SCOPE_STORE, $storeId);

        return trim((string) $path, '/');
    }

    /**
     * Shared HMAC secret. Must match the storefront's
     * MAGENX_ABANDONED_RECOVERY_SECRET — the storefront route verifies the token
     * this module signs. Stored encrypted (system.xml uses the obscure backend
     * model), so decrypt on read.
     */
    public function getRecoverySecret(?int $storeId = null): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_RECOVERY_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $raw === '' ? '' : trim((string) $this->encryptor->decrypt($raw));
    }

    /**
     * Lifetime of a recovery token. Capped at the cart's own max age: a token
     * that outlives the window in which we would still remind the cart is pure
     * extra exposure.
     */
    public function getTokenTtlSeconds(?int $storeId = null): int
    {
        $ttlHours = $this->positiveInt(self::XML_TOKEN_TTL_HOURS, $storeId);

        return min($ttlHours, $this->getMaxAgeHours($storeId)) * 3600;
    }

    /**
     * Ids of every active store view with reminders switched on.
     *
     * @return int[]
     */
    public function getEnabledStoreIds(): array
    {
        if ($this->enabledStoreIds !== null) {
            return $this->enabledStoreIds;
        }

        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if ($this->isEnabled($storeId)) {
                $ids[] = $storeId;
            }
        }

        return $this->enabledStoreIds = $ids;
    }

    /**
     * Most permissive value in use across the enabled stores, for the SQL bounds.
     * Using the default-scope value instead would silently starve any store view
     * that overrides a setting in the more permissive direction (a shorter delay,
     * a longer max age, a higher reminder cap).
     */
    public function getMinDelayMinutes(): int
    {
        return $this->aggregate(fn (int $id): int => $this->getDelayMinutes($id), 'min');
    }

    public function getMaxOfMaxAgeHours(): int
    {
        return $this->aggregate(fn (int $id): int => $this->getMaxAgeHours($id), 'max');
    }

    public function getMaxOfMaxReminders(): int
    {
        return $this->aggregate(fn (int $id): int => $this->getMaxReminders($id), 'max');
    }

    /**
     * Shortest "wait before trying this cart again" in use — covers both the
     * repeat-reminder spacing and the failed-attempt backoff, because the
     * candidate query cannot tell the two apart without the row.
     */
    public function getMinAttemptSpacingMinutes(): int
    {
        return $this->aggregate(
            fn (int $id): int => min($this->getRepeatIntervalMinutes($id), $this->getRetryBackoffMinutes($id)),
            'min'
        );
    }

    public function getMinOfMinSubtotal(): float
    {
        $values = array_map(fn (int $id): float => $this->getMinSubtotal($id), $this->getEnabledStoreIds());

        return $values === [] ? 0.0 : min($values);
    }

    /**
     * @param callable(int):int $reader
     */
    private function aggregate(callable $reader, string $mode): int
    {
        $values = array_map($reader, $this->getEnabledStoreIds());
        if ($values === []) {
            return 1;
        }

        return $mode === 'min' ? min($values) : max($values);
    }

    private function positiveInt(string $path, ?int $storeId): int
    {
        return max(1, (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
