<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessor for the abandoned-checkout configuration.
 */
class Config
{
    private const XML_ENABLED = 'magenx_abandoned_checkout/general/enabled';
    private const XML_DELAY_MINUTES = 'magenx_abandoned_checkout/general/delay_minutes';
    private const XML_MAX_AGE_HOURS = 'magenx_abandoned_checkout/general/max_age_hours';
    private const XML_MAX_REMINDERS = 'magenx_abandoned_checkout/general/max_reminders';
    private const XML_REPEAT_INTERVAL = 'magenx_abandoned_checkout/general/repeat_interval_minutes';
    private const XML_BATCH_SIZE = 'magenx_abandoned_checkout/general/batch_size';
    private const XML_MIN_SUBTOTAL = 'magenx_abandoned_checkout/general/min_subtotal';
    private const XML_TEMPLATE = 'magenx_abandoned_checkout/email/email_template';
    private const XML_SENDER = 'magenx_abandoned_checkout/email/sender_email_identity';
    private const XML_RECOVERY_PATH = 'magenx_abandoned_checkout/email/recovery_path';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDelayMinutes($storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_DELAY_MINUTES, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getMaxAgeHours($storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_MAX_AGE_HOURS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getMaxReminders($storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_MAX_REMINDERS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getRepeatIntervalMinutes($storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_REPEAT_INTERVAL, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Batch size is read at default scope — the cron processes all stores in one pass.
     */
    public function getBatchSize(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_BATCH_SIZE));
    }

    public function getMinSubtotal($storeId = null): float
    {
        return max(0.0, (float) $this->scopeConfig->getValue(self::XML_MIN_SUBTOTAL, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getTemplateId($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_TEMPLATE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSenderIdentity($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_SENDER, ScopeInterface::SCOPE_STORE, $storeId) ?: 'sales';
    }

    public function getRecoveryPath($storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_RECOVERY_PATH, ScopeInterface::SCOPE_STORE, $storeId), '/')
            ?: 'cart/recover';
    }
}
