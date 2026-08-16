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
        private readonly StateInterface $inlineTranslation
    ) {
    }

    /**
     * Send the reminder for a loaded quote. Throws on failure so the cron can
     * count it and NOT mark the quote as notified.
     */
    public function send(Quote $quote, StoreInterface $store): void
    {
        $storeId = (int) $store->getId();
        $templateId = $this->config->getTemplateId($storeId);
        $email = (string) $quote->getCustomerEmail();

        if ($templateId === '' || $email === '') {
            throw new \RuntimeException('Missing template or customer email for quote ' . $quote->getId());
        }

        $customerName = trim(
            (string) $quote->getCustomerFirstname() . ' ' . (string) $quote->getCustomerLastname()
        );
        if ($customerName === '') {
            $customerName = (string) $store->getFrontendName();
        }

        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'customer_name' => $this->escaper->escapeHtml($customerName),
                    'store_name' => $store->getFrontendName(),
                    'recovery_url' => $this->recoveryUrl->build((int) $quote->getId(), $store),
                    'items_html' => $this->buildItemsHtml($quote, $store),
                    'grand_total' => $this->priceCurrency->format(
                        (float) $quote->getGrandTotal(),
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $store
                    ),
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
     * Build a small, email-safe HTML table of the cart's visible line items.
     */
    private function buildItemsHtml(Quote $quote, StoreInterface $store): string
    {
        $rows = '';
        foreach ($quote->getAllVisibleItems() as $item) {
            $name = $this->escaper->escapeHtml((string) $item->getName());
            $qty = (float) $item->getQty();
            $qtyLabel = $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim((string) $qty, '0'), '.');
            $rowTotal = $this->priceCurrency->format(
                (float) $item->getRowTotalInclTax() ?: (float) $item->getRowTotal(),
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
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
