<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AbandonedCheckout\Model;

use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;

/**
 * Renders and sends one abandoned-cart reminder email.
 */
class ReminderSender
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly RecoveryUrl $recoveryUrl,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Escaper $escaper,
        private readonly StateInterface $inlineTranslation,
        private readonly Emulation $emulation
    ) {
    }

    /**
     * Send the reminder for a loaded quote. Throws on failure so the caller can
     * log it and leave the quote to be retried after the backoff.
     */
    public function send(Quote $quote, StoreInterface $store): void
    {
        $storeId = (int) $store->getId();
        $templateId = $this->config->getTemplateId($storeId);
        $email = (string) $quote->getCustomerEmail();

        if ($templateId === '' || $email === '') {
            throw new \RuntimeException('Missing template or customer email for quote ' . $quote->getId());
        }

        // Raw, for the RFC 5322 display name; the template gets an escaped copy.
        // These are two different encodings and must not share a variable — an
        // HTML-escaped name reaches the recipient's mail client as a literal
        // "O&#039;Brien". Newlines are stripped because a display name is a
        // header value.
        $customerName = $this->recipientName($quote);

        // Render the parts we build ourselves in the RECIPIENT's store view.
        // Without this the column headings and the number formatting come from
        // whatever locale the cron process happens to be in, so a German store
        // view's reminder went out with English headings and English number
        // formatting — TransportBuilder's `store` option only covers the
        // template itself.
        //
        // Deliberately stopped BEFORE the transport work rather than wrapped
        // around it: Magento's own email rendering emulates the store too, and
        // Emulation keeps a single slot for the environment it has to restore.
        // Nesting means the inner stop empties that slot and the outer one
        // becomes a no-op, which would leave the whole cron process running in
        // this shopper's store view.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $itemsHtml = $this->buildItemsHtml($quote, $store);
            $grandTotal = $this->formatPrice((float) $quote->getGrandTotal(), $store);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'customer_name' => $this->escaper->escapeHtml($customerName),
                    'store_name' => $this->escaper->escapeHtml((string) $store->getFrontendName()),
                    'recovery_url' => $this->recoveryUrl->build((int) $quote->getId(), $store),
                    'unsubscribe_url' => $this->recoveryUrl->buildUnsubscribe($store, $email),
                    'items_html' => $itemsHtml,
                    'grand_total' => $grandTotal,
                    'store' => $store,
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($email, $customerName);

            $this->transportBuilder->getTransport()->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * Display name for the To: header, or '' when the quote carries no name.
     *
     * Falling back to the store's own name (as this used to) produces mail
     * addressed to "Acme Store <shopper@example.com>" and a greeting reading
     * "Hi Acme Store," — the template branches on an empty value instead.
     */
    private function recipientName(Quote $quote): string
    {
        $name = trim((string) $quote->getCustomerFirstname() . ' ' . (string) $quote->getCustomerLastname());

        return trim(str_replace(["\r", "\n"], ' ', $name));
    }

    private function formatPrice(float $amount, StoreInterface $store): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store
        );
    }

    /**
     * Build a small, email-safe HTML table of the cart's visible line items.
     * Callers must have store emulation active — see send().
     */
    private function buildItemsHtml(Quote $quote, StoreInterface $store): string
    {
        $rows = '';
        foreach ($quote->getAllVisibleItems() as $item) {
            $name = $this->escaper->escapeHtml((string) $item->getName());
            $qty = (float) $item->getQty();
            $qtyLabel = $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim((string) $qty, '0'), '.');
            $rowTotal = $this->formatPrice(
                (float) ($item->getRowTotalInclTax() ?: $item->getRowTotal()),
                $store
            );

            $rows .= '<tr>'
                . '<td style="padding:8px 12px 8px 0;border-bottom:1px solid #eee;">' . $name . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:center;">' . $qtyLabel . '</td>'
                . '<td style="padding:8px 0 8px 12px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">'
                . $rowTotal . '</td>'
                . '</tr>';
        }

        return '<table border="0" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
            . '<thead><tr>'
            . '<th style="padding:0 12px 8px 0;text-align:left;border-bottom:2px solid #ddd;">'
            . $this->escaper->escapeHtml(__('Item')->render()) . '</th>'
            . '<th style="padding:0 12px 8px;text-align:center;border-bottom:2px solid #ddd;">'
            . $this->escaper->escapeHtml(__('Qty')->render()) . '</th>'
            . '<th style="padding:0 0 8px 12px;text-align:right;border-bottom:2px solid #ddd;">'
            . $this->escaper->escapeHtml(__('Price')->render()) . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }
}
