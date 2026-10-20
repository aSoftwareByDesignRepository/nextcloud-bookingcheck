<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\AuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Seller-side offering CRUD. */
class OfferingController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly AuditService $audit,
		private readonly IUserSession $userSession,
		private readonly \OCA\BookingCheck\Integration\DeckIntegration $deck,
		private readonly \OCA\BookingCheck\Integration\TalkIntegration $talk,
		private readonly \OCA\BookingCheck\Service\LicenseService $license,
		private readonly \OCP\IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/offerings', [
			'navId' => 'offerings',
			'offerings' => array_map(
				fn (\OCA\BookingCheck\Db\Offering $o) => $o->jsonSerialize(),
				$this->offerings->findAll()),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/offering-edit', [
			'navId' => 'offerings', 'offering' => null,
			'pro' => $this->license->isPro(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/offering-detail', [
			'navId' => 'offerings', 'offeringId' => $id,
			'pro' => $this->license->isPro(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function edit(int $id): TemplateResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		// AC-3: a board deleted in Deck clears its stale ref on render
		if ($o->getDeckBoardId() !== null && $this->deck->boardExists((int)$o->getDeckBoardId()) === false) {
			$o->setDeckBoardId(null);
			$this->offerings->update($o);
		}
		return new TemplateResponse($this->appName, 'admin/offering-edit', [
			'navId' => 'offerings', 'offeringId' => $id,
			'offering' => $o->jsonSerialize(),
			'pro' => $this->license->isPro(),
			'deckAvailable' => $this->deck->isAvailable(),
			'talkAvailable' => $this->talk->isAvailable(),
			'deckBoardId' => $o->getDeckBoardId(),
			'talkRoom' => $o->getTalkRoom(),
		]);
	}

	/** JSON payload for the offering detail page. */
	#[NoAdminRequired]
	public function data(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		return $this->ok(['offering' => $o->jsonSerialize()]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse
	{
		$in = $this->request->getParams();
		$data = $this->validateOffering($in);

		$o = new Offering();
		$o->setSlug($this->slugify($data['title']));
		$this->applyOffering($o, $data);
		$o->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'system');
		$now = new \DateTimeImmutable();
		$o->setCreatedAt($now);
		$o->setUpdatedAt($now);
		$this->offerings->insert($o);
		$this->audit->log(null, (int)$o->getId(), $o->getCreatedBy(), 'offering.created', null, $o->getStatus());
		return $this->ok(['id' => (int)$o->getId(), 'slug' => $o->getSlug()], 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		$data = $this->validateOffering($this->request->getParams());
		$this->applyOffering($o, $data);
		$o->setUpdatedAt(new \DateTimeImmutable());
		$this->offerings->update($o);
		$this->audit->log(null, $id, $this->userSession->getUser()?->getUID() ?? 'system',
			'offering.updated');
		return $this->ok(['id' => $id]);
	}

	/**
	 * US-526/527: per-offering Deck board / Talk room toggles. Both are
	 * guarded — an absent sibling returns the same 'unavailable' result and
	 * the UI never shows the toggle (availability flags render it).
	 */
	#[NoAdminRequired]
	public function toggleIntegration(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		$kind = (string)$this->request->getParam('kind', '');
		$on = in_array($this->request->getParam('on'), ['1', 'true', 'on', 1, true], true);
		$uid = $this->userSession->getUser()?->getUID() ?? '';

		if ($kind === 'deck') {
			if ($on) {
				if (!$this->deck->isAvailable()) {
					return $this->ok(['enabled' => false, 'unavailable' => true]);
				}
				$boardId = $this->deck->createBoard('Offering: ' . $o->getTitle(), $uid);
				if ($boardId === null) {
					throw new ValidationException(['deck' => 'Deck board could not be created.']);
				}
				$o->setDeckBoardId($boardId);
			} else {
				$o->setDeckBoardId(null);
			}
		} elseif ($kind === 'talk') {
			if ($on) {
				if (!$this->talk->isAvailable()) {
					return $this->ok(['enabled' => false, 'unavailable' => true]);
				}
				$url = $this->talk->createRoom($o->getTitle(), $uid);
				if ($url === null) {
					throw new ValidationException(['talk' => 'Talk room could not be created.']);
				}
				$o->setTalkRoom($url);
			} else {
				$o->setTalkRoom(null);
			}
		} else {
			throw new ValidationException(['kind' => 'Unknown integration.']);
		}

		$o->setUpdatedAt(new \DateTimeImmutable());
		$this->offerings->update($o);
		$this->audit->log(null, $id, $uid !== '' ? $uid : 'system',
			'offering.integration', null, null, ['kind' => $kind, 'on' => $on]);
		return $this->ok(['enabled' => $on]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		// delete only when nothing was ever sold — otherwise archive:
		// a sold offering's orders/invoices/audit rows must never dangle.
		if ($this->offerings->hasOrders($id)) {
			throw new ValidationException(['offering' => 'This offering has orders — archive it instead.']);
		}
		$this->db->beginTransaction();
		try {
			$this->offerings->deleteChildren($id);
			$this->offerings->delete($o);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->log(null, $id, $this->userSession->getUser()?->getUID() ?? 'system',
			'offering.deleted');
		return $this->ok(['deleted' => true]);
	}

	#[NoAdminRequired]
	public function transition(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		$to = (string)$this->request->getParam('to', '');
		$legal = [
			'draft' => ['published'],
			'published' => ['archived', 'draft'],
			'archived' => ['draft'],
		];
		if (!in_array($to, $legal[$o->getStatus()] ?? [], true)) {
			throw new ValidationException(['status' => 'Illegal status change.']);
		}
		$from = $o->getStatus();
		$o->setStatus($to);
		$o->setUpdatedAt(new \DateTimeImmutable());
		$this->offerings->update($o);
		$this->audit->log(null, $id, $this->userSession->getUser()?->getUID() ?? 'system',
			'offering.transition', $from, $to);
		return $this->ok(['status' => $to]);
	}

	#[NoAdminRequired]
	public function duplicate(int $id): JSONResponse
	{
		$src = $this->offerings->find($id);
		if ($src === null) {
			throw new NotFoundException();
		}
		$o = clone $src;
		$o->setId(0);
		$o->setSlug($this->slugify($src->getTitle() . ' copy'));
		$o->setStatus('draft');
		$o->setTitle($src->getTitle() . ' (copy)');
		$now = new \DateTimeImmutable();
		$o->setCreatedAt($now);
		$o->setUpdatedAt($now);
		$this->offerings->insert($o);
		return $this->ok(['id' => (int)$o->getId()], 201);
	}

	/** @param array<string,mixed> $in */
	private function validateOffering(array $in): array
	{
		$fields = [];
		$title = trim((string)($in['title'] ?? ''));
		$type = (string)($in['type'] ?? '');
		if ($title === '' || mb_strlen($title) > 190) {
			$fields['title'] = 'Title is required.';
		}
		$allowedTypes = ['event', 'course', 'timeslot'];
		if ($this->license->hasFeature('passes')) {
			$allowedTypes[] = 'pass';
		}
		if (!in_array($type, $allowedTypes, true)) {
			$fields['type'] = 'Pick a booking type.';
		}
		// strict digit check — is_numeric accepts "2.9"/"2e3" and (int)
		// silently truncates money (api-security §7)
		$price = $in['price_cents'] ?? null;
		if (!is_scalar($price) || !preg_match('/^\d+$/', (string)$price)
			|| (int)$price > 99999999) {
			$fields['price_cents'] = 'Invalid price.';
		}
		$currency = strtoupper((string)($in['currency'] ?? 'EUR'));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'Invalid currency.';
		}
		$vat = $in['vat_bp'] ?? '1900';
		if (!is_scalar($vat) || !preg_match('/^\d+$/', (string)$vat)
			|| (int)$vat > 10000) {
			$fields['vat_bp'] = 'Invalid VAT rate.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$d = [
			'title' => $title, 'type' => $type,
			'descr' => $this->strOrNull($in['descr'] ?? null),
			'price_cents' => (int)$price, 'currency' => $currency, 'vat_bp' => (int)$vat,
			'approval_required' => $this->bool($in, 'approval_required'),
			'book_series' => $this->bool($in, 'book_series'),
			'list_in_directory' => $this->bool($in, 'list_in_directory'),
			'tos_text' => $this->strOrNull($in['tos_text'] ?? null),
		];
		// NH-20 attendee reminder (free): single days-before value,
		// 0 = off — consumed by AttendeeReminderJob as an integer.
		if (array_key_exists('reminder_days', $in)) {
			$v = $in['reminder_days'];
			if (!is_scalar($v) || !preg_match('/^\d{1,3}$/', (string)$v) || (int)$v > 365) {
				$fields['reminder_days'] = 'Days must be 0–365.';
			} else {
				$d['reminder_days'] = (int)$v;
			}
		}
		// Pro: dynamic pricing markup in bp + step, deposit bp, invoice pay
		// dyn markup: the form sends percent (dyn_pct), the API may send bp (dyn_bp)
		if (array_key_exists('dyn_pct', $in) || array_key_exists('dyn_bp', $in)) {
			$v = $in['dyn_pct'] ?? $in['dyn_bp'];
			$isPct = array_key_exists('dyn_pct', $in);
			$max = $isPct ? 100 : 10000;
			if (!is_scalar($v) || !preg_match('/^\d{1,5}$/', (string)$v) || (int)$v > $max) {
				$fields['dyn_bp'] = 'Dynamic markup 0–10000 bp.';
			} else {
				$d['dyn_bp'] = $isPct ? (int)$v * 100 : (int)$v;
			}
		}
		if (array_key_exists('dyn_step_pct', $in)) {
			$v = $in['dyn_step_pct'];
			if (!is_scalar($v) || !preg_match('/^\d{1,3}$/', (string)$v) || (int)$v > 100) {
				$fields['dyn_step_pct'] = 'Step must be 0–100 percent.';
			} else {
				$d['dyn_step_pct'] = (int)$v;
			}
		}
		// deposit: form sends percent (deposit_pct), API may send bp (deposit_bp)
		if (array_key_exists('deposit_pct', $in) || array_key_exists('deposit_bp', $in)) {
			$v = $in['deposit_pct'] ?? $in['deposit_bp'];
			$isPct = array_key_exists('deposit_pct', $in);
			$max = $isPct ? 100 : 10000;
			if (!is_scalar($v) || !preg_match('/^\d{1,5}$/', (string)$v) || (int)$v >= $max) {
				$fields['deposit_bp'] = 'Deposit must be 0–9999 bp.';
			} else {
				$d['deposit_bp'] = $isPct ? (int)$v * 100 : (int)$v;
			}
		}
		if (array_key_exists('invoice_pay', $in)) {
			$d['invoice_pay'] = $this->bool($in, 'invoice_pay');
		}
		if (array_key_exists('pass_scope', $in)) {
			$scope = [];
			foreach (preg_split('/[\s,]+/', (string)$in['pass_scope']) ?: [] as $p0) {
				if ($p0 === '') {
					continue;
				}
				if (!preg_match('/^\d+$/', $p0)) {
					$fields['pass_scope'] = 'Offering ids only.';
					break;
				}
				if ($this->offerings->find((int)$p0) === null) {
					$fields['pass_scope'] = 'Unknown offering in scope.';
					break;
				}
				$scope[] = (int)$p0;
			}
			$d['pass_scope'] = $scope === [] ? null : (json_encode(array_values(array_unique($scope))) ?: null);
		}
		// Pro fields are only writable while licensed — an unlicensed save
		// (or a hand-crafted request) leaves the stored values untouched.
		// notify-mode waitlist is free tier; 'allocate' is Pro — a free-tier
		// (or forged) allocate write degrades to notify, never silently kept.
		// Absent key = leave the stored mode alone (partial saves).
		if (array_key_exists('waitlist_mode', $in)) {
			$wlMode = in_array($in['waitlist_mode'], ['none', 'notify', 'allocate'], true)
				? $in['waitlist_mode'] : 'none';
			$d['waitlist_mode'] = ($wlMode === 'allocate' && !$this->license->hasFeature('waitlist'))
				? 'notify' : $wlMode;
		}
		if ($this->license->hasFeature('reviews')) {
			$d['review_display'] = $this->bool($in, 'review_display');
		}
		if ($this->license->hasFeature('uploads')) {
			$d['sharing_enabled'] = $this->bool($in, 'sharing_enabled');
			$d['sharing_scope'] = in_array($in['sharing_scope'] ?? '', ['occurrence', 'offering'], true)
				? $in['sharing_scope'] : 'occurrence';
			$maxMb = is_numeric($in['upload_max_mb'] ?? null) ? (int)$in['upload_max_mb'] : 50;
			$quotaMb = is_numeric($in['upload_quota_mb'] ?? null) ? (int)$in['upload_quota_mb'] : 5120;
			if ($maxMb < 1 || $maxMb > 50) {
				$fields['upload_max_mb'] = 'Between 1 and 50 MB.';
			}
			if ($quotaMb < $maxMb || $quotaMb > 102400) {
				$fields['upload_quota_mb'] = 'Quota must be at least the file size limit.';
			}
			$d['upload_max_mb'] = $maxMb;
			$d['upload_quota_mb'] = $quotaMb;
		}
		if ($this->license->hasFeature('embed')) {
			$d['embed_domains'] = $this->embedDomains($in['embed_domains'] ?? '', $fields);
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		return $d;
	}

	/**
	 * @return string|null JSON array of validated https origins, or null
	 */
	/** @param array<string,string> $fields */
	private function embedDomains(mixed $raw, array &$fields): ?string
	{
		$raw = trim((string)$raw);
		if ($raw === '') {
			return null;
		}
		$origins = [];
		foreach (preg_split('/[\s,]+/', $raw) ?: [] as $cand) {
			$u = parse_url($cand);
			$host = strtolower((string)($u['host'] ?? ''));
			if ($host === '' || !in_array($u['scheme'] ?? '', ['https'], true)) {
				$fields['embed_domains'] = 'Only https:// origins are allowed.';
				return null;
			}
			$origins[] = 'https://' . $host . (isset($u['port']) ? ':' . (int)$u['port'] : '');
		}
		return json_encode(array_values(array_unique($origins))) ?: null;
	}

	/** @param array<string,mixed> $d */
	private function applyOffering(Offering $o, array $d): void
	{
		$o->setTitle($d['title']);
		$o->setType($d['type']);
		$o->setDescr($d['descr']);
		$o->setPriceCents($d['price_cents']);
		$o->setCurrency($d['currency']);
		$o->setVatBp($d['vat_bp']);
		$o->setApprovalRequired($d['approval_required']);
		$o->setBookSeries($d['book_series']);
		// Pro fields absent on the free tier → preserve the stored value
		if (array_key_exists('waitlist_mode', $d)) {
			$o->setWaitlistMode($d['waitlist_mode']);
		}
		if (array_key_exists('sharing_enabled', $d)) {
			$o->setSharingEnabled($d['sharing_enabled']);
		}
		if (array_key_exists('sharing_scope', $d)) {
			$o->setSharingScope($d['sharing_scope']);
		}
		if (array_key_exists('upload_max_mb', $d)) {
			$o->setUploadMaxMb($d['upload_max_mb']);
		}
		if (array_key_exists('upload_quota_mb', $d)) {
			$o->setUploadQuotaMb($d['upload_quota_mb']);
		}
		if (array_key_exists('review_display', $d)) {
			$o->setReviewDisplay($d['review_display']);
		}
		if (array_key_exists('embed_domains', $d)) {
			$o->setEmbedDomains($d['embed_domains']);
		}
		if (array_key_exists('reminder_days', $d)) {
			$o->setReminderDays($d['reminder_days']);
		}
		if (array_key_exists('dyn_bp', $d)) {
			$o->setDynBp($d['dyn_bp']);
		}
		if (array_key_exists('dyn_step_pct', $d)) {
			$o->setDynStepPct($d['dyn_step_pct']);
		}
		if (array_key_exists('deposit_bp', $d)) {
			$o->setDepositBp($d['deposit_bp']);
		}
		if (array_key_exists('invoice_pay', $d)) {
			$o->setInvoicePay($d['invoice_pay']);
		}
		if (array_key_exists('pass_scope', $d)) {
			$o->setPassScope($d['pass_scope']);
		}
		$o->setListInDirectory($d['list_in_directory']);
		$o->setTosText($d['tos_text']);
	}

	private function slugify(string $title): string
	{
		$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
		if ($slug === '') {
			$slug = 'offering';
		}
		$base = $slug;
		for ($i = 2; $this->offerings->findBySlug($slug) !== null; $i++) {
			$slug = $base . '-' . $i;
		}
		return mb_substr($slug, 0, 190);
	}

	/** @param array<string,mixed> $in */
	private function bool(array $in, string $key): int
	{
		// "false" string is truthy — normalize explicitly (api-security §4)
		$v = $in[$key] ?? null;
		return in_array($v, [1, '1', 'true', true, 'on'], true) ? 1 : 0;
	}

	private function strOrNull(mixed $v): ?string
	{
		$v = is_string($v) ? trim($v) : null;
		return $v === '' || $v === null ? null : $v;
	}
}
