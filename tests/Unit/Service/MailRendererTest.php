<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\MailTpl;
use OCA\BookingCheck\Db\MailTplMapper;
use OCA\BookingCheck\Service\MailRenderer;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class MailRendererTest extends TestCase
{
	private function renderer(?MailTpl $tpl): MailRenderer
	{
		$l10nT = $this->createMock(\OCP\IL10N::class);
		$l10nT->method('t')->willReturnCallback(
			fn ($t, $p = []) => $p ? vsprintf(str_replace('%1$', '%', $t), $p) : $t);
		$l10n = $this->createStub(IFactory::class);
		$l10n->method('get')->willReturn($l10nT);
		$mapper = $this->createMock(MailTplMapper::class);
		$mapper->method('findApplicable')->willReturn($tpl);
		return new MailRenderer($l10n, $mapper);
	}

	public function testNoOverrideRendersBuiltIn(): void
	{
		[$subject, $body] = $this->renderer(null)
			->render('order_confirmed', ['order_ref' => 'BCK-1', 'manage_url' => 'https://x'], 'en');
		self::assertStringContainsString('Booking confirmed', $subject);
		self::assertStringContainsString('BCK-1', $body);
	}

	public function testSubjectOverrideReplacesAndStripsCrlf(): void
	{
		$tpl = new MailTpl();
		$tpl->setSubject("My shop says hi\r\nBcc: evil@x");
		$tpl->setExtraText(null);
		[$subject] = $this->renderer($tpl)->render('order_confirmed', ['order_ref' => 'BCK-1'], 'en');
		self::assertSame('My shop says hiBcc: evil@x', $subject);
	}

	public function testExtraTextAppendsVerbatimWithPercent(): void
	{
		$tpl = new MailTpl();
		$tpl->setSubject(null);
		$tpl->setExtraText('We are 100% sure you will love it — see %s terms.');
		[$subject, $body] = $this->renderer($tpl)
			->render('payment_link', ['order_ref' => 'BCK-9', 'pay_url' => 'https://p'], 'en');
		self::assertStringContainsString('approved', $subject);
		self::assertStringContainsString('We are 100% sure you will love it — see %s terms.', $body,
			'seller "%" must survive — extra text is never sprintf\'d');
	}

	public function testEmptyOverrideFieldsKeepDefaults(): void
	{
		$tpl = new MailTpl();
		$tpl->setSubject('   ');
		$tpl->setExtraText('');
		[$subject, $body] = $this->renderer($tpl)->render('otp', ['code' => '123456'], 'en');
		self::assertSame('Your sign-in code', $subject);
		self::assertStringNotContainsString("\n\n\n", $body);
	}

	public function testReminderCarriesTitleAndStartsAt(): void
	{
		[$subject, $body] = $this->renderer(null)->render('reminder', [
			'order_ref' => 'BCK-42',
			'title' => 'Pottery class',
			'starts_at' => '2026-11-02 18:00',
		], 'en');
		self::assertStringContainsString('Pottery class', $subject);
		self::assertStringContainsString('2026-11-02 18:00', $subject);
		self::assertStringContainsString('coming up', $body);
		self::assertStringContainsString('Pottery class', $body);
		self::assertStringContainsString('2026-11-02 18:00', $body);
		self::assertStringContainsString('BCK-42', $body);
		self::assertStringNotContainsString('Notification for order', $body,
			'reminder must not fall through to the generic default arm');
	}

	public function testReminderWithoutTitleFallsBackToRef(): void
	{
		[$subject, $body] = $this->renderer(null)
			->render('reminder', ['order_ref' => 'BCK-7', 'starts_at' => '2026-11-02 18:00'], 'en');
		self::assertStringContainsString('BCK-7', $subject);
		self::assertStringContainsString('2026-11-02 18:00', $body);
	}

	public function testReminderTitleCannotInjectHeaders(): void
	{
		[$subject, $body] = $this->renderer(null)->render('reminder', [
			'order_ref' => 'BCK-1',
			'title' => "Yoga\r\nBcc: evil@x",
			'starts_at' => "now\nCc: a@b",
		], 'en');
		self::assertSame('Reminder: YogaBcc: evil@x — nowCc: a@b', $subject,
			'CR/LF must be stripped from every header-bound value');
		self::assertStringContainsString('YogaBcc: evil@x', $body);
	}
}
