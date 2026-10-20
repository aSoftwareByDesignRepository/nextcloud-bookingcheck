<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Invoice;
use OCA\BookingCheck\Db\InvoiceMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLineMapper;

/**
 * NH-38 — XRechnung e-invoice (EN 16931, CII syntax, profile xrechnung 3.0).
 *
 * The PDF stays the human-facing artifact; the CII XML is the structured
 * legal document (DE e-invoicing mandate phases in 2027/2028). Output is
 * a standalone download + mail attachment — ZUGFeRD-embedded PDF/A-3 is
 * intentionally out (no PDF/A toolchain vendored; the XML alone already
 * satisfies XRechnung).
 *
 * Credit notes render with the same profile (DocumentTypeCode 381);
 * reverse-charge orders map to category AE.
 */
class EInvoiceService
{
	private const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

	public function __construct(
		private readonly InvoiceMapper $invoices,
		private readonly OrderLineMapper $lines,
		private readonly OfferingMapper $offerings,
		private readonly SettingsService $settings,
	) {
	}

	public function isAvailable(): bool
	{
		// seller legal identity is mandatory for a valid XRechnung
		return trim($this->settings->get('seller_name')) !== '';
	}

	/** Build the CII XML for an issued invoice — deterministic, UTF-8. */
	public function ciiXml(Invoice $invoice, Order $order): string
	{
		$seller = $this->sellerParty();
		$isCredit = $invoice->getType() === 'credit';
		$docType = $isCredit ? '381' : '380'; // credit note | invoice
		$reverseCharge = (int)$order->getReverseCharge() === 1;

		$w = new \XMLWriter();
		$w->openMemory();
		$w->startDocument('1.0', 'UTF-8');
		$w->setIndent(true);
		$w->startElementNs('rsm', 'CrossIndustryInvoice', self::NS_RSM);
		$w->writeAttribute('xmlns:ram', self::NS_RAM);
		$w->writeAttribute('xmlns:udt', self::NS_UDT);

		// ---- exchanged document context -----------------------------
		$w->startElementNs('rsm', 'ExchangedDocumentContext', null);
		$w->startElementNs('ram', 'GuidelineSpecifiedDocumentContextParameter', null);
		$w->writeElementNs('ram', 'ID', null,
			'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0');
		$w->endElement(); // Guideline…
		$w->endElement(); // Context

		// ---- exchanged document -------------------------------------
		$w->startElementNs('rsm', 'ExchangedDocument', null);
		$w->writeElementNs('ram', 'ID', null, $this->x($invoice->getNumber()));
		$w->writeElementNs('ram', 'TypeCode', null, $docType);
		$w->startElementNs('ram', 'IssueDateTime', null);
		$w->startElementNs('udt', 'DateTimeString', null);
		$w->writeAttribute('format', '102');
		$issued = $invoice->getIssuedAt();
		$w->text($issued instanceof \DateTimeInterface ? $issued->format('Ymd') : date('Ymd'));
		$w->endElement(); // DateTimeString
		$w->endElement(); // IssueDateTime
		$w->endElement(); // ExchangedDocument

		// ---- supply chain trade transaction -------------------------
		$w->startElementNs('rsm', 'SupplyChainTradeTransaction', null);

		foreach ($this->lines->findByOrder((int)$order->getId()) as $i => $line) {
			$w->startElementNs('ram', 'IncludedSupplyChainTradeLineItem', null);
			$w->startElementNs('ram', 'AssociatedDocumentLineDocument', null);
			$w->writeElementNs('ram', 'LineID', null, (string)($i + 1));
			$w->endElement();
			$w->startElementNs('ram', 'SpecifiedTradeProduct', null);
			$w->writeElementNs('ram', 'Name', null, $this->x($this->lineName($line)));
			$w->endElement();
			$w->startElementNs('ram', 'SpecifiedLineTradeAgreement', null);
			$w->startElementNs('ram', 'NetPriceProductTradePrice', null);
			$w->writeElementNs('ram', 'ChargeAmount', null, $this->amt((int)$line->getUnitCents()));
			$w->endElement();
			$w->endElement();
			$w->startElementNs('ram', 'SpecifiedLineTradeDelivery', null);
			$w->startElementNs('ram', 'BilledQuantity', null);
			$w->writeAttribute('unitCode', 'C62'); // unit
			$w->text((string)$line->getSeats());
			$w->endElement();
			$w->endElement();
			$w->startElementNs('ram', 'SpecifiedLineTradeSettlement', null);
			$w->startElementNs('ram', 'ApplicableTradeTax', null);
			$w->writeElementNs('ram', 'TypeCode', null, 'VAT');
			$w->writeElementNs('ram', 'CategoryCode', null, $reverseCharge ? 'AE' : 'S');
			$w->writeElementNs('ram', 'RateApplicablePercent', null,
				$reverseCharge ? '0' : $this->bp((int)$order->getVatBp()));
			$w->endElement();
			$w->startElementNs('ram', 'SpecifiedTradeSettlementLineMonetarySummation', null);
			$w->writeElementNs('ram', 'LineTotalAmount', null, $this->amt((int)$line->getLineCents()));
			$w->endElement();
			$w->endElement(); // SpecifiedLineTradeSettlement
			$w->endElement(); // LineItem
		}

		// ---- parties -------------------------------------------------
		$w->startElementNs('ram', 'ApplicableHeaderTradeAgreement', null);
		$this->party($w, 'SellerTradeParty', $seller);
		$buyer = [
			'name' => $order->getBuyerCompany() !== null && $order->getBuyerCompany() !== ''
				? $order->getBuyerCompany() : $order->getBuyerName(),
			'vat_id' => $order->getVatId(),
			'email' => $order->getBuyerEmail(),
			'country' => $order->getBillingCountry(),
		];
		$this->party($w, 'BuyerTradeParty', $buyer);
		$w->startElementNs('ram', 'BuyerOrderReferencedDocument', null);
		$w->writeElementNs('ram', 'IssuerAssignedID', null, $this->x($order->getRef()));
		$w->endElement();
		if ($isCredit && $invoice->getCreditsInvoiceId() !== null) {
			$orig = $this->invoices->find((int)$invoice->getCreditsInvoiceId());
			if ($orig !== null) {
				$w->startElementNs('ram', 'InvoiceReferencedDocument', null);
				$w->writeElementNs('ram', 'IssuerAssignedID', null, $this->x($orig->getNumber()));
				$w->endElement();
			}
		}
		$w->endElement(); // TradeAgreement

		$w->startElementNs('ram', 'ApplicableHeaderTradeDelivery', null);
		$w->endElement();

		// ---- settlement ----------------------------------------------
		$w->startElementNs('ram', 'ApplicableHeaderTradeSettlement', null);
		$w->writeElementNs('ram', 'InvoiceCurrencyCode', null, $this->x($order->getCurrency()));
		$w->startElementNs('ram', 'ApplicableTradeTax', null);
		$vatCents = (int)$invoice->getVatCents();
		$netCents = (int)$invoice->getNetCents();
		$w->writeElementNs('ram', 'CalculatedAmount', null, $this->amt($vatCents));
		$w->writeElementNs('ram', 'TypeCode', null, 'VAT');
		$w->writeElementNs('ram', 'BasisAmount', null, $this->amt($netCents));
		$w->writeElementNs('ram', 'CategoryCode', null, $reverseCharge ? 'AE' : 'S');
		if ($reverseCharge) {
			// BT-120 legal note required for AE
			$w->writeElementNs('ram', 'ExemptionReason', null, 'Reverse charge');
		}
		$w->writeElementNs('ram', 'RateApplicablePercent', null,
			$reverseCharge ? '0' : $this->bp((int)$order->getVatBp()));
		$w->endElement();
		$w->startElementNs('ram', 'SpecifiedTradeSettlementHeaderMonetarySummation', null);
		$w->writeElementNs('ram', 'LineTotalAmount', null, $this->amt($netCents));
		$w->writeElementNs('ram', 'TaxBasisTotalAmount', null, $this->amt($netCents));
		$w->startElementNs('ram', 'TaxTotalAmount', null);
		$w->writeAttribute('currencyID', $this->x($order->getCurrency()));
		$w->text($this->amt($vatCents));
		$w->endElement(); // TaxTotalAmount
		$w->writeElementNs('ram', 'GrandTotalAmount', null, $this->amt($netCents + $vatCents));
		$w->writeElementNs('ram', 'DuePayableAmount', null, $this->amt($netCents + $vatCents));
		$w->endElement();
		$w->endElement(); // TradeSettlement
		$w->endElement(); // SupplyChainTradeTransaction
		$w->endElement(); // CrossIndustryInvoice
		$w->endDocument();
		return $w->outputMemory();
	}

	/** @return array{name:string,vat_id:?string,email:?string,country:?string} */
	private function sellerParty(): array
	{
		return [
			'name' => $this->settings->get('seller_name'),
			'vat_id' => $this->settings->get('seller_vat_id') ?: null,
			'email' => $this->settings->get('seller_email') ?: null,
			'country' => $this->settings->get('seller_country') ?: 'DE',
		];
	}

	/** @param array{name:string,vat_id:?string,email:?string,country:?string} $p */
	private function party(\XMLWriter $w, string $tag, array $p): void
	{
		$w->startElementNs('ram', $tag, null);
		$w->writeElementNs('ram', 'Name', null, $this->x($p['name'] ?? ''));
		if (!empty($p['email'])) {
			$w->startElementNs('ram', 'URIUniversalCommunication', null);
			$w->startElementNs('ram', 'URIID', null);
			$w->writeAttribute('schemeID', 'EM');
			$w->text($this->x((string)$p['email']));
			$w->endElement();
			$w->endElement();
		}
		if (!empty($p['country'])) {
			$w->startElementNs('ram', 'PostalTradeAddress', null);
			$w->writeElementNs('ram', 'CountryID', null, strtoupper(substr($this->x((string)$p['country']), 0, 2)));
			$w->endElement();
		}
		if (!empty($p['vat_id'])) {
			$w->startElementNs('ram', 'SpecifiedTaxRegistration', null);
			$w->startElementNs('ram', 'ID', null);
			$w->writeAttribute('schemeID', 'VA');
			$w->text($this->x((string)$p['vat_id']));
			$w->endElement();
			$w->endElement();
		}
		$w->endElement();
	}

	private function lineName($line): string
	{
		$cat = $line->getCatName();
		$kind = $line->getLineKind();
		$base = $kind === 'seat' ? 'Seat' : ucfirst((string)$kind);
		return $cat !== null && $cat !== '' ? $base . ' — ' . $cat : $base;
	}

	/** cents → EN 16931 decimal (2dp) */
	private function amt(int $cents): string
	{
		return number_format($cents / 100, 2, '.', '');
	}

	/** basis points → percent decimal */
	private function bp(int $bp): string
	{
		return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') ?: '0';
	}

	/** XML text — element content escaping only, no header surface */
	private function x(?string $s): string
	{
		return trim((string)$s);
	}
}
