<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Mints the signed, expiring token that the recovery email carries.
 *
 * WHY NOT THE MASKED CART ID DIRECTLY: `quote_id_mask.masked_id` is a bearer
 * credential for the cart — anyone holding it can read and modify it through
 * the guest-cart API. Putting it in an email link means it ends up in browser
 * history, storefront and CDN access logs, and any analytics or session-replay
 * tool that records `location.href` — and it never expires, because rotating it
 * would break the shopper's own live session.
 *
 * So the link carries a token instead: HMAC-SHA256 over "<maskedId>.<expiry>",
 * signed with a secret shared with the storefront. The storefront's
 * /api/cart/recover route verifies it SERVER-SIDE and hands the browser the
 * masked id only then, so the credential itself never appears in a URL and a
 * leaked link stops working once it expires.
 *
 * Format: base64url(payload) "." base64url(hmac), where payload is the literal
 * string "<maskedId>.<unixExpiry>". Deliberately self-contained — verifying it
 * needs no database round-trip and no extra table.
 *
 * NOT single-use, on purpose: corporate link scanners (Microsoft Safe Links and
 * friends) fetch links before the recipient ever sees them, so a one-shot token
 * would be burnt on arrival for a large share of real inboxes. Expiry is the
 * control that matters here.
 */
class RecoveryToken
{
    private const ALGO = 'sha256';

    public function __construct(
        private readonly Config $config,
        private readonly DateTime $date
    ) {
    }

    /**
     * @throws LocalizedException when no shared secret is configured.
     */
    public function issue(string $maskedId, ?int $storeId = null): string
    {
        $secret = $this->config->getRecoverySecret($storeId);
        if ($secret === '') {
            throw new LocalizedException(
                __(
                    'Abandoned Checkout: no recovery secret is configured. Set one under '
                    . 'Stores > Configuration > Magenx > Abandoned Checkout Reminder > Email, and give the '
                    . 'storefront the same value as MAGENX_ABANDONED_RECOVERY_SECRET.'
                )
            );
        }

        $payload = $maskedId . '.' . ($this->date->gmtTimestamp() + $this->config->getTokenTtlSeconds($storeId));
        $signature = hash_hmac(self::ALGO, $payload, $secret, true);

        return $this->base64UrlEncode($payload) . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
