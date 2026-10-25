<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Command;

use OCA\BookingCheck\Service\DemoSeedService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed demo data for BookingCheck Manager / Scan reviewers, Play
 * screenshots and sales demos — no web admin UI required.
 */
final class SeedDemoCommand extends Command
{
	public function __construct(
		private readonly DemoSeedService $seed,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('bookingcheck:seed-demo')
			->setDescription('Create demo offering, events, series, paid orders, check-in, and door/manager device tokens.')
			->setHelp(
				<<<'HELP'
Creates everything a BookingCheck companion reviewer needs:

  • Published offering <comment>Demo Night — Riverside Hall</comment> (15.00 EUR)
  • Three single events + one weekly series (4 occurrences)
  • Three paid orders with fictional attendees; one already checked in
  • <comment>door</comment> + <comment>manager</comment> companion device tokens (plaintext shown once)
  • Printable BCK1 ticket QR payloads for scan testing

Local Docker:

  <comment>cd nextcloud && ./scripts/seed-bookingcheck-demo.sh</comment>

Or directly:

  <comment>occ bookingcheck:seed-demo</comment>

Re-run safe: idempotent on dev/staging. Do not use on production instances.
HELP
			)
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Admin uid that owns the seeded content', 'admin')
			->addOption('rotate-tokens', null, InputOption::VALUE_NONE, 'Mint fresh device tokens even if review tokens exist')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable result only');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		try {
			$r = $this->seed->run(
				(string)$input->getOption('user'),
				(bool)$input->getOption('rotate-tokens'),
			);
		} catch (\Throwable $e) {
			if ((bool)$input->getOption('json')) {
				$output->writeln((string)json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
			} else {
				$io->error($e->getMessage());
			}
			return Command::FAILURE;
		}

		if ((bool)$input->getOption('json')) {
			$output->writeln((string)json_encode($r, JSON_THROW_ON_ERROR));
			return Command::SUCCESS;
		}

		$io->success('BookingCheck demo data is ready (no web UI needed).');
		$io->section('Offering & schedule');
		$io->listing([
			'Offering #' . $r['offeringId'] . ' — Demo Night — Riverside Hall',
			'Events: ' . implode(', ', array_map(static fn ($id) => '#' . $id, $r['eventIds'])),
			'Series #' . $r['seriesId'] . ' — Weekly Demo Session (4 occurrences)',
		]);
		$io->section('Orders (paid)');
		$io->listing($r['orderRefs']);
		if ($r['checkedIn'] !== '') {
			$io->text('Checked in already: ' . $r['checkedIn']);
		}
		$io->section('Device tokens (shown once — store now)');
		$io->listing([
			'door    → ' . $r['doorToken'],
			'manager → ' . $r['managerToken'],
		]);
		$io->section('Test ticket QR payloads (scan these)');
		$io->listing($r['qrPayloads']);
		$io->note([
			'Paste URL + tokens into Play Console confidential notes; never commit them.',
			'Emulator URL: http://10.0.2.2:8081 — staging review needs public HTTPS.',
		]);

		return Command::SUCCESS;
	}
}
