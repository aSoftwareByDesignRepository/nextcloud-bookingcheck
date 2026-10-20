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
}
