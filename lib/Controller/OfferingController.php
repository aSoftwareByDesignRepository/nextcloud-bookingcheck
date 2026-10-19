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
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/offering-detail', [
			'navId' => 'offerings', 'offeringId' => $id,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function edit(int $id): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/offering-edit', [
			'navId' => 'offerings', 'offeringId' => $id,
		]);
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

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		// delete only when nothing was ever sold — otherwise archive
		$this->offerings->delete($o);
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

	private function validateOffering(array $in): array
	{
		$fields = [];
		$title = trim((string)($in['title'] ?? ''));
		$type = (string)($in['type'] ?? '');
		if ($title === '' || mb_strlen($title) > 190) {
			$fields['title'] = 'Title is required.';
		}
		if (!in_array($type, ['event', 'course', 'timeslot'], true)) {
			$fields['type'] = 'Pick a booking type.';
		}
		$price = $in['price_cents'] ?? null;
		if (!is_numeric($price) || (int)$price < 0 || (int)$price > 99999999) {
			$fields['price_cents'] = 'Invalid price.';
		}
		$currency = strtoupper((string)($in['currency'] ?? 'EUR'));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'Invalid currency.';
		}
		$vat = $in['vat_bp'] ?? 1900;
		if (!is_numeric($vat) || (int)$vat < 0 || (int)$vat > 10000) {
			$fields['vat_bp'] = 'Invalid VAT rate.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		return [
			'title' => $title, 'type' => $type,
			'descr' => $this->strOrNull($in['descr'] ?? null),
			'price_cents' => (int)$price, 'currency' => $currency, 'vat_bp' => (int)$vat,
			'approval_required' => $this->bool($in, 'approval_required'),
			'book_series' => $this->bool($in, 'book_series'),
			'waitlist_mode' => in_array($in['waitlist_mode'] ?? '', ['notify', 'allocate'], true)
				? $in['waitlist_mode'] : 'allocate',
			'sharing_enabled' => $this->bool($in, 'sharing_enabled'),
			'review_display' => $this->bool($in, 'review_display'),
			'list_in_directory' => $this->bool($in, 'list_in_directory'),
			'tos_text' => $this->strOrNull($in['tos_text'] ?? null),
		];
	}

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
		$o->setWaitlistMode($d['waitlist_mode']);
		$o->setSharingEnabled($d['sharing_enabled']);
		$o->setReviewDisplay($d['review_display']);
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
