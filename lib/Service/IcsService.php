<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Db\SeriesMapper;

/**
 * RFC5545 calendar output. Every interpolated value passes the escaper:
 * \ → \\, ; → \;, , → \,, newline → \n literal; lines fold at 75 octets.
 * A crafted title/name can never inject a second VEVENT.
 */
class IcsService
{
	public function __construct(
		private readonly OrderLineMapper $lines,
		private readonly OfferingMapper $offerings,
		private readonly EventMapper $events,
		private readonly SlotMapper $slots,
		private readonly SeriesMapper $series,
	) {
	}

	public function forOrder(Order $order): string
	{
		$offering = $this->offerings->find((int)$order->getOfferingId());
		$title = $offering !== null ? $offering->getTitle() : 'Booking ' . $order->getRef();

		$out = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//BookingCheck//EN',
			'CALSCALE:GREGORIAN',
		];
		foreach ($this->lines->findByOrder((int)$order->getId()) as $i => $line) {
			if ($line->getLineKind() !== 'seat') {
				continue;
			}
			foreach ($this->targets($line->getTargetType(), (int)$line->getTargetId()) as [$start, $end]) {
				$out[] = 'BEGIN:VEVENT';
				$out[] = 'UID:bck-' . $order->getRef() . '-' . $line->getId() . '-' . $i . '@bookingcheck';
				$out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
				$out[] = 'DTSTART:' . $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
				$out[] = 'DTEND:' . $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
				$out[] = 'SUMMARY:' . self::esc($title);
				$out[] = 'DESCRIPTION:' . self::esc('Order ' . $order->getRef() . ' — ' . $order->getBuyerName());
				$out[] = 'STATUS:CONFIRMED';
				$out[] = 'END:VEVENT';
			}
		}
		$out[] = 'END:VCALENDAR';
		return implode("\r\n", array_map([self::class, 'fold'], $out)) . "\r\n";
	}

	/** @return list<array{0:\DateTimeInterface,1:\DateTimeInterface}> */
	private function targets(string $type, int $id): array
	{
		$out = [];
		if ($type === 'event') {
			$e = $this->events->find($id);
			if ($e !== null) {
				$out[] = [$e->getStartsAt(), $e->getEndsAt()];
			}
		} elseif ($type === 'slot') {
			$s = $this->slots->find($id);
			if ($s !== null) {
				$out[] = [$s->getStartsAt(), $s->getEndsAt()];
			}
		} elseif ($type === 'series') {
			foreach ($this->events->findAll() as $e) {
				if ($e->getSeriesId() !== null && (int)$e->getSeriesId() === $id) {
					$out[] = [$e->getStartsAt(), $e->getEndsAt()];
				}
			}
		}
		return $out;
	}

	/** RFC5545 TEXT escaping. */
	public static function esc(string $v): string
	{
		$v = str_replace('\\', '\\\\', $v);
		$v = str_replace(["\r\n", "\r", "\n"], '\\n', $v);
		$v = str_replace(';', '\\;', $v);
		return str_replace(',', '\\,', $v);
	}

	/** Fold at 75 octets (continuation = CRLF + single space). */
	public static function fold(string $line): string
	{
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = '';
		while (strlen($line) > 75) {
			$cut = 75;
			// never split inside a UTF-8 sequence — back off to a safe byte
			while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}
			$out .= substr($line, 0, $cut) . "\r\n ";
			$line = substr($line, $cut);
		}
		return $out . $line;
	}
}
