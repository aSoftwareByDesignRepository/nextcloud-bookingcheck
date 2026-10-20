<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\UploadMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin moderation for attendee uploads — list, stream, remove.
 * UGC needs a takedown path; removal is soft-delete + file purge.
 */
class UploadController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UploadMapper $uploads,
		private readonly OfferingMapper $offerings,
		private readonly UploadService $service,
		private readonly AuditService $audit,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/** GET /api/offerings/{id}/uploads — live uploads on an offering. */
	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		return $this->ok(['uploads' => array_map(
			fn ($u) => $u->jsonSerialize() + ['uploaderEmail' => $u->getUploaderEmail()],
			$this->uploads->forOffering($id)
		)]);
	}

	/** GET /api/uploads/{id}/file — stream for moderation. */
	#[NoAdminRequired]
	public function file(int $id): \OCP\AppFramework\Http\Response
	{
		$u = $this->uploads->find($id);
		if ($u === null) {
			throw new NotFoundException();
		}
		return $this->service->streamFor($u); // admin path: no viewer order needed
	}

	/** DELETE /api/uploads/{id} — takedown. */
	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$u = $this->uploads->find($id);
		if ($u === null || $u->getDeletedAt() !== null) {
			throw new NotFoundException();
		}
		$this->service->purge($u);
		$this->audit->log(null, (int)$u->getOfferingId(),
			$this->userSession->getUser()?->getUID() ?? 'system',
			'upload.removed', null, (string)$u->getId());
		return $this->ok(['deleted' => true]);
	}
}
