<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\BackgroundJob;

use OCA\BookingCheck\Db\EmailOutboxMapper;
use OCA\BookingCheck\Service\MailRenderer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class EmailQueueJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly EmailOutboxMapper $outbox,
		private readonly MailRenderer $renderer,
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void
	{
		foreach ($this->outbox->pendingBatch() as $mail) {
			try {
				$payload = json_decode((string)$mail->getPayloadJson(), true) ?? [];
				[$subject, $body] = $this->renderer->render(
					$mail->getKind(), $payload, $mail->getLocale());
				$message = $this->mailer->createMessage();
				$message->setTo([$mail->getToEmail() => '']);
				$message->setSubject($subject);
				$message->setBody($body, 'text/plain');
				$this->mailer->send($message);
				$this->outbox->markSent((int)$mail->getId());
			} catch (\Throwable $e) {
				$this->logger->warning('email send failed: ' . $e->getMessage(),
					['kind' => $mail->getKind()]);
				$this->outbox->markAttempt((int)$mail->getId(), (int)$mail->getAttempts() + 1 >= 4);
			}
		}
	}
}
