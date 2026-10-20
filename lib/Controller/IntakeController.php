<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Question;
use OCA\BookingCheck\Db\QuestionMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Per-attendee intake questions (wording_rev freezes consent text). */
class IntakeController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly QuestionMapper $items,
		private readonly OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		return $this->ok(['questions' => array_map(
			fn ($q) => $q->jsonSerialize(), $this->items->findByOffering($id))]);
	}

	#[NoAdminRequired]
	public function store(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		$label = trim((string)$this->request->getParam('label', ''));
		$kind = (string)$this->request->getParam('kind', '');
		if ($label === '' || mb_strlen($label) > 500) {
			throw new ValidationException(['label' => 'Question text required.']);
		}
		if (!in_array($kind, ['text', 'select', 'checkbox', 'date'], true)) {
			throw new ValidationException(['kind' => 'Unknown kind.']);
		}
		$visibleTo = (string)$this->request->getParam('visible_to', 'admin');
		if (!in_array($visibleTo, ['admin', 'roster', 'door'], true)) {
			$visibleTo = 'admin';
		}
		$q = new Question();
		$q->setOfferingId($id);
		$q->setLabel($label);
		$q->setKind($kind);
		$q->setOptionsJson($this->options($kind));
		$q->setRequired($this->request->getParam('required') === '1' ? 1 : 0);
		$q->setVisibleTo($visibleTo);
		$q->setWordingRev(1);
		$q->setActive(1);
		$this->items->insert($q);
		return $this->ok(['id' => (int)$q->getId()], 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$q = $this->items->find($id);
		if ($q === null) {
			throw new NotFoundException();
		}
		$label = trim((string)$this->request->getParam('label', $q->getLabel()));
		if ($label === '' || mb_strlen($label) > 500) {
			throw new ValidationException(['label' => 'Question text required.']);
		}
		if ($label !== $q->getLabel()) {
			// consent wording changed → bump the revision so future answers
			// record which text the attendee actually saw
			$q->setLabel($label);
			$q->setWordingRev((int)$q->getWordingRev() + 1);
		}
		$this->items->update($q);
		return $this->ok(['wording_rev' => (int)$q->getWordingRev()]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$q = $this->items->find($id);
		if ($q === null) {
			throw new NotFoundException();
		}
		$q->setActive(0); // deactivate — historical answers keep their wording_rev
		$this->items->update($q);
		return $this->ok(['deleted' => true]);
	}

	private function options(string $kind): ?string
	{
		if ($kind !== 'select') {
			return null;
		}
		$raw = $this->request->getParam('options', []);
		$list = array_values(array_filter(array_map('strval', is_array($raw) ? $raw : explode("\n", (string)$raw))));
		if ($list === []) {
			throw new ValidationException(['options' => 'Select needs at least one option.']);
		}
		try {
			return json_encode($list, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ValidationException(['options' => 'Options contain invalid characters.']);
		}
	}
}
