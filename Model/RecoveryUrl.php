<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the "return to your cart" recovery link.
 *
 * The headless storefront keys its cart on the MASKED quote id (the same value
 * createEmptyCart / customerCart return), stored in the browser's
 * localStorage["magenx_cart_id"]. So the recovery link carries the masked id;
 * the storefront /cart/recover route writes it to localStorage and redirects to
 * the cart. A mask is created on demand for customer carts that don't have one
 * yet (guest carts always do).
 */
class RecoveryUrl
{
    public function __construct(
        private readonly Config $config,
        private readonly QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly QuoteIdMaskResource $quoteIdMaskResource
    ) {
    }

    public function build(int $quoteId, StoreInterface $store): string
    {
        $maskedId = $this->resolveMaskedId($quoteId);

        $base = rtrim($store->getBaseUrl(), '/');
        $path = $this->config->getRecoveryPath((int) $store->getId());

        return $base . '/' . $path . '?id=' . rawurlencode($maskedId);
    }

    private function resolveMaskedId(int $quoteId): string
    {
        $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);
        if ($maskedId !== '') {
            return $maskedId;
        }

        // Customer carts may not have a mask yet — create one (QuoteIdMask
        // generates the random masked id on save).
        $quoteIdMask = $this->quoteIdMaskFactory->create();
        $quoteIdMask->setQuoteId($quoteId);
        $this->quoteIdMaskResource->save($quoteIdMask);

        return (string) $quoteIdMask->getMaskedId();
    }
}
