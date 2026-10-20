<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Offering;
use OCP\Files\IAppData;
use OCP\IDBConnection;

/**
 * NH-14 — seller zip-export of an offering's content before EOL purge.
 *
 * Built in a temp file (ZipArchive needs random access; memory stays
 * constant regardless of upload sizes). Caller streams + unlinks.
 */
class ExportZipService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppData $appData,
		private readonly CsvExportService $csv,
	) {
	}

	/** @return string temp zip path — caller unlinks after streaming */
	public function build(Offering $offering): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bckzip');
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('zip open failed');
		}
		$oid = (int)$offering->getId();

		$manifest = [
			'offering' => $offering->jsonSerialize(),
			'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'materials' => [],
			'uploads' => [],
		];

		$zip->addFromString('roster.csv', $this->csv->roster($oid));

		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.title', 'm.file_ref', 'l.file_ref AS lib_ref', 'l.title AS lib_title')
			->from('bc_material', 'm')
			->leftJoin('m', 'bc_material_lib', 'l', $qb->expr()->eq('l.id', 'm.library_id'))
			->where($qb->expr()->eq('m.offering_id', $qb->createNamedParameter($oid)));
		foreach ($qb->executeQuery()->fetchAll() as $m) {
			$ref = $m['file_ref'] ?? $m['lib_ref'];
			$title = $m['file_ref'] !== null ? $m['title'] : ($m['lib_title'] ?? $m['title']);
			if ($ref === null) {
				continue;
			}
			$bytes = $this->read('materials', (string)$ref);
			if ($bytes === null) {
				continue;
			}
			$name = 'materials/' . $this->safe((string)$title) . '_' . (int)$m['id'];
			$zip->addFromString($name, $bytes);
			$manifest['materials'][] = ['id' => (int)$m['id'], 'title' => $title, 'file' => $name];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name', 'file_ref')->from('bc_upload')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($oid)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		foreach ($qb->executeQuery()->fetchAll() as $u) {
			$bytes = $this->read('uploads', (string)$u['file_ref']);
			if ($bytes === null) {
				continue;
			}
			$name = 'uploads/' . $this->safe((string)$u['display_name']) . '_' . (int)$u['id'];
			$zip->addFromString($name, $bytes);
			$manifest['uploads'][] = ['id' => (int)$u['id'], 'name' => $u['display_name'], 'file' => $name];
		}

		$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
		$zip->close();
		return $tmp;
	}

	private function read(string $folder, string $ref): ?string
	{
		try {
			$dir = $this->appData->getFolder($folder);
			if (!$dir->fileExists($ref)) {
				return null;
			}
			$fh = $dir->getFile($ref)->read();
			if ($fh === false) {
				return null;
			}
			$out = stream_get_contents($fh);
			fclose($fh);
			return $out === false ? null : $out;
		} catch (\Throwable) {
			return null;
		}
	}

	private function safe(string $name): string
	{
		$clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
		return $clean === '' ? 'file' : $clean;
	}
}
