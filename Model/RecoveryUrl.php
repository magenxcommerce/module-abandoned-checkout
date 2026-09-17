<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\UrlInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the "return to your cart" recovery link.
 *
 * GUEST CARTS get a link carrying a signed, expiring token (see RecoveryToken);
 * the storefront exchanges it server-side for the masked cart id and restores
 * the cart.
 *
 * CUSTOMER CARTS get a plain link to the same storefront route with NO token.
 * We deliberately do not mint a `quote_id_mask` for them: masks exist for guest
 * carts, and Magento's REST guest-cart endpoints resolve a mask to a quote
 * without checking that the quote has no customer attached — so mailing a mask
 * for a logged-in customer's cart can hand whoever holds the link that
 * customer's cart, including the name, email and addresses saved on it. A
 * signed-in shopper does not need one anyway: `customerCart` resolves their
 * cart from their session once they land on the storefront.
 */
class RecoveryUrl
{
    public function __construct(
        private readonly Config $config,
        private readonly RecoveryToken $token,
        private readonly QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
    ) {
    }

    public function build(int $quoteId, StoreInterface $store): string
    {
        $storeId = (int) $store->getId();
        $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);

        $query = $maskedId === '' ? [] : ['t' => $this->token->issue($maskedId, $storeId)];

        return $this->url($store, $this->config->getRecoveryPath($storeId), $query);
    }

    /**
     * Opt-out link, or null when the merchant has not configured one.
     */
    public function buildUnsubscribe(StoreInterface $store, string $email): ?string
    {
        $storeId = (int) $store->getId();
        $path = $this->config->getUnsubscribePath($storeId);

        return $path === '' ? null : $this->url($store, $path, ['email' => $email]);
    }

    /**
     * @param array<string, string> $query
     */
    private function url(StoreInterface $store, string $path, array $query): string
    {
        // URL_TYPE_WEB, and $secure pinned to true: the default (URL_TYPE_LINK)
        // runs the path through Magento's own rewrite config and can inject
        // `index.php`, which is meaningless to a headless storefront, and it
        // resolves the scheme from `web/secure/use_in_frontend` — a flag that is
        // commonly left off behind a TLS-terminating proxy, which would mail a
        // credential-bearing link over plain http.
        $base = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');

        // The path is admin-supplied and may already carry a query string
        // (e.g. "cart/recover?ref=email"), so pick the right separator rather
        // than always appending "?" — that produced a malformed
        // "…?ref=email?t=…" that no router would parse.
        $url = $base . '/' . $path;
        $separator = str_contains($path, '?') ? '&' : '?';

        return $query === [] ? $url : $url . $separator . http_build_query($query);
    }
}
