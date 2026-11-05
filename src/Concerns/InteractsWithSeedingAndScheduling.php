<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Commands\DbSeedCommand;
use Totoglu\Console\Commands\ScheduleRunCommand;
use Totoglu\Console\Database\Seeder;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;

/**
 * Seeding & Scheduling operations for ConsoleRuntime.
 *
 * Encapsulates FakerPHP availability checks, database seeder discovery & timed execution
 * (`Totoglu\Console\Database\Seeder`), and scheduled cron task inspection & execution
 * (`Totoglu\Console\Scheduling\Task` & `Event`) with `WireCache` trigger tracking.
 */
trait InteractsWithSeedingAndScheduling
{
	/**
	 * Check whether FakerPHP is available in the Composer runtime.
	 */
	public function hasFaker(): bool
	{
		$this->ensureAutoload();

		return class_exists('Faker\\Factory');
	}

	/**
	 * Discover all database seeders in site/seeders/ and modules.
	 *
	 * @return list<array{name: string, class: string, path: string}>
	 */
	public function getDiscoveredSeeders(): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null || !class_exists(Seeder::class)) {
			return [];
		}

		try {
			$classes = $discoverer->discoverClasses('seeders', '*Seeder.php', Seeder::class);
			$files = $discoverer->discoverFiles('seeders', '*Seeder.php');
			$seeders = [];

			foreach ($classes as $name => $fqcn) {
				$seeders[] = [
					'name' => (string) $name,
					'class' => (string) $fqcn,
					'path' => (string) ($files[$name] ?? ''),
				];
			}

			return $seeders;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Execute a specific seeder by name or all discovered seeders ('all') by delegating to `DbSeedCommand`.
	 *
	 * @return array{ok: bool, executed: list<string>, details: string, message: string}
	 */
	public function runSeeders(string $seederNameOrAll): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null || !class_exists(Seeder::class) || !class_exists(DbSeedCommand::class)) {
			return [
				'ok' => false,
				'executed' => [],
				'details' => '',
				'message' => $this->_('Seeder runtime is unavailable because trk/processwire-console is not installed.'),
			];
		}

		try {
			$classes = $discoverer->discoverClasses('seeders', '*Seeder.php', Seeder::class);
			if ($classes === []) {
				return [
					'ok' => false,
					'executed' => [],
					'details' => '',
					'message' => $this->_('No seeder classes found in site/seeders/.'),
				];
			}

			$target = trim($seederNameOrAll);
			$toRun = [];

			if (strtolower($target) === 'all' || $target === '') {
				if (isset($classes['DatabaseSeeder'])) {
					$toRun[] = 'DatabaseSeeder';
				} else {
					$toRun = array_values(array_map('strval', array_keys($classes)));
				}
			} else {
				$matchedKey = null;
				if (isset($classes[$target])) {
					$matchedKey = (string) $target;
				} elseif (isset($classes[$target . 'Seeder'])) {
					$matchedKey = $target . 'Seeder';
				}
				if ($matchedKey === null) {
					return [
						'ok' => false,
						'executed' => [],
						'details' => '',
						'message' => sprintf($this->_('Seeder "%s" was not found.'), $target),
					];
				}
				$toRun[] = $matchedKey;
			}

			$executed = [];
			$detailLines = [];
			foreach ($toRun as $seederName) {
				$startNs = hrtime(true);
				$res = $this->runConsoleCommandInProcess(DbSeedCommand::class, [
					'--class' => $seederName,
					'--force' => true,
				]);
				$elapsedMs = (int) round((hrtime(true) - $startNs) / 1_000_000);

				if (!$res['ok']) {
					return [
						'ok' => false,
						'executed' => $executed,
						'details' => implode("\n", $detailLines),
						'message' => (string) ($res['error']['message'] ?? $this->_('Seeder execution failed.')),
					];
				}

				$seededName = (string) ($res['data']['seeder'] ?? $seederName);
				$executed[] = $seededName;
				$line = sprintf('+ %s (%d ms)', $seededName, $elapsedMs);
				if ($res['stdout'] !== '') {
					$line .= "\n  " . str_replace("\n", "\n  ", mb_substr($res['stdout'], 0, 2000));
				}
				$detailLines[] = $line;
			}

			return [
				'ok' => true,
				'executed' => $executed,
				'details' => implode("\n", $detailLines),
				'message' => sprintf($this->_('Successfully executed %d seeder(s): %s'), count($executed), implode(', ', $executed)),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'executed' => [],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Discover all scheduled Task classes and inspect their cron expressions, due status, and last trigger time.
	 *
	 * @return list<array{
	 *   name: string,
	 *   class: string,
	 *   path: string,
	 *   expression: string,
	 *   description: string,
	 *   isDue: bool,
	 *   nextRun: string,
	 *   lastTriggered: string
	 * }>
	 */
	public function getScheduledTasks(): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null || !class_exists(Task::class)) {
			return [];
		}

		try {
			$classes = $discoverer->discoverClasses('schedule', '*Task.php', Task::class);
			$files = $discoverer->discoverFiles('schedule', '*Task.php');
			$cache = $this->wire()->cache;
			$now = new \DateTime('now');
			$tasks = [];

			foreach ($classes as $name => $fqcn) {
				/** @var Task $task */
				$task = new $fqcn($this->wire());
				$event = new Event();
				$task->schedule($event);

				$expression = $event->getExpression();
				$isDue = false;
				$nextRun = $this->_('Unknown');

				try {
					$isDue = $event->isDue($now);
					if (class_exists('Cron\\CronExpression')) {
						$cron = new \Cron\CronExpression($expression);
						$nextRun = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
					}
				} catch (\Throwable) {
					// Keep fallback if cron expression is invalid
				}

				$lastRaw = $cache ? $cache->get('wire-console:schedule:last-run:' . $name) : null;
				$lastTriggered = is_string($lastRaw) && $lastRaw !== '' ? $lastRaw : '—';

				$tasks[] = [
					'name' => (string) $name,
					'class' => (string) $fqcn,
					'path' => (string) ($files[$name] ?? ''),
					'expression' => $expression,
					'description' => (string) $fqcn,
					'isDue' => $isDue,
					'nextRun' => $nextRun,
					'lastTriggered' => $lastTriggered,
				];
			}

			return $tasks;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Run due scheduled tasks by delegating to `ScheduleRunCommand`.
	 *
	 * @return array{ok: bool, executed: list<string>, errors: array<string, string>, details: string, message: string}
	 */
	public function runScheduledTasks(string $taskNameOrDue = 'due'): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null || !class_exists(Task::class) || !class_exists(ScheduleRunCommand::class)) {
			return [
				'ok' => false,
				'executed' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Scheduler runtime is unavailable because trk/processwire-console is not installed.'),
			];
		}

		try {
			$classes = $discoverer->discoverClasses('schedule', '*Task.php', Task::class);
			if ($classes === []) {
				return [
					'ok' => true,
					'executed' => [],
					'errors' => [],
					'details' => '',
					'message' => $this->_('No scheduled tasks discovered in site/schedule/.'),
				];
			}

			$target = trim($taskNameOrDue);
			$onlyDue = strtolower($target) === 'due' || $target === '';
			if (!$onlyDue && !isset($classes[$target])) {
				return [
					'ok' => false,
					'executed' => [],
					'errors' => [],
					'details' => '',
					'message' => sprintf($this->_('Scheduled task "%s" was not found.'), $target),
				];
			}

			$res = $this->runConsoleCommandInProcess(ScheduleRunCommand::class);
			$executedRaw = isset($res['data']['executed']) && is_array($res['data']['executed'])
				? array_values(array_map('strval', $res['data']['executed']))
				: [];
			$errorsRaw = isset($res['data']['errors']) && is_array($res['data']['errors'])
				? $res['data']['errors']
				: [];

			$cache = $this->wire()->cache;
			$executed = [];
			$errors = [];
			$detailLines = [];

			foreach ($executedRaw as $name) {
				if (!$onlyDue && $name !== $target) {
					continue;
				}
				$executed[] = $name;
				if ($cache) {
					$cache->save('wire-console:schedule:last-run:' . $name, date('Y-m-d H:i:s'), 86400 * 30);
				}
				$detailLines[] = sprintf('+ %s', $name);
			}

			foreach ($errorsRaw as $name => $errMsg) {
				$taskName = (string) $name;
				if (!$onlyDue && $taskName !== $target) {
					continue;
				}
				$errors[$taskName] = (string) $errMsg;
				$detailLines[] = sprintf('! %s: %s', $taskName, (string) $errMsg);
			}

			if (!$res['ok'] && $errors === [] && $res['error'] !== null) {
				$errors['schedule:run'] = $res['error']['message'];
				$detailLines[] = '! ' . $res['error']['message'];
			}

			$details = implode("\n", $detailLines);

			if ($errors !== []) {
				return [
					'ok' => false,
					'executed' => $executed,
					'errors' => $errors,
					'details' => $details,
					'message' => sprintf(
						$this->_('Executed %d task(s) with %d error(s).'),
						count($executed),
						count($errors)
					),
				];
			}

			if ($executed === []) {
				return [
					'ok' => true,
					'executed' => [],
					'errors' => [],
					'details' => '',
					'message' => $this->_('No scheduled tasks are due to run right now.'),
				];
			}

			return [
				'ok' => true,
				'executed' => $executed,
				'errors' => [],
				'details' => $details,
				'message' => sprintf($this->_('Successfully ran %d scheduled task(s): %s'), count($executed), implode(', ', $executed)),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'executed' => [],
				'errors' => [],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}
	}
}
