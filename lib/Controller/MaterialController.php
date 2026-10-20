<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Material;
use OCA\BookingCheck\Db\MaterialMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\MaterialService;
use OCA\BookingCheck\Service\UploadGateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MaterialController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MaterialMapper $materials,
		private readonly OfferingMapper $offerings,
		private readonly MaterialService $storage,
		private readonly UploadGateService $gate,
		private readonly \OCP\IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		$this->mustOffering($id);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('bc_material')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($id)))
			->orderBy('sort', 'ASC');
		return $this->ok(['materials' => $qb->executeQuery()->fetchAll()]);
	}

	/** Admin upload — still runs the gate chain (admins upload bad files too). */
	#[NoAdminRequired]
	public function upload(int $id): JSONResponse
	{
		$this->mustOffering($id);
		$file = $this->request->getUploadedFile('file');
		if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new ValidationException(['file' => 'Choose a file.']);
		}
		$safe = $this->gate->inspect($file); // magic bytes, macros, image re-encode
		$stream = fopen((string)$file['tmp_name'], 'rb');
		if ($stream === false) {
			throw new ValidationException(['file' => 'Upload could not be read.']);
		}
		$ref = $this->storage->store($safe['name'], $stream);

		$m = new Material();
		$m->setOfferingId($id);
		$m->setKind('file');
		$m->setTitle(trim((string)$this->request->getParam('title', $safe['name'])));
		$m->setFileRef($ref);
		$this->materials->insert($m);
		return $this->ok(['id' => (int)$m->getId()], 201);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$m = $this->materials->find($id);
		if ($m === null) {
			throw new NotFoundException();
		}
		if ($m->getFileRef() !== null) {
			$this->storage->delete($m->getFileRef());
		}
		$this->materials->delete($m);
		return $this->ok(['deleted' => true]);
	}

	private function mustOffering(int $id): void
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
	}
}
