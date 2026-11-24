<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Cron\CronExpression;
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
		return is_dir($this->getProjectRootPath() . 'vendor/fakerphp/faker');
	}

	/**
	 * Discover all database seeders in site/seeders/ and modules.
	 *
	 * @return list<array{name: string, class: string, path: string}>
	 */
	public function getDiscoveredSeeders(): array
	{
		if ($this->createDiscoverer() === null) {
			return [];
		}

		try {
			$seeders = [];
			foreach ($this->discoverVerifiedClasses('seeders', '*Seeder.php', Seeder::class) as $name => $info) {
				$seeders[] = [
					'name' => $name,
					'class' => $info['class'],
					'path' => $info['path'],
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
		if ($this->createDiscoverer() === null) {
			return [
				'ok' => false,
				'executed' => [],
				'details' => '',
				'message' => $this->_('Seeder runtime is unavailable because trk/processwire-console is not installed.'),
			];
		}

		if ($this->hasUnsafeFeatureFiles('seeders', '*Seeder.php')) {
			return [
				'ok' => false,
				'executed' => [],
				'details' => '',
				'message' => $this->_('Refusing to execute seeders while symbolic link or out-of-root seeder files exist.'),
			];
		}

		try {
			$classes = [];
			foreach ($this->discoverVerifiedClasses('seeders', '*Seeder.php', Seeder::class) as $name => $info) {
				$classes[$name] = $info['class'];
			}
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

				$seededList = is_array($res['data']['seeded'] ?? null)
					? array_values(array_filter(array_map('strval', $res['data']['seeded']), static fn(string $item): bool => $item !== ''))
					: [];
				if ($seededList === []) {
					$seededList = [$seederName];
				}
				foreach ($seededList as $seededItem) {
					$executed[] = $seededItem;
				}
				$line = sprintf('+ %s (%d ms)', implode(', ', $seededList), $elapsedMs);
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
		if ($this->createDiscoverer() === null) {
			return [];
		}

		try {
			$cache = $this->wire()->cache;
			$now = new \DateTime('now');
			$tasks = [];

			foreach ($this->discoverVerifiedClasses('schedule', '*Task.php', Task::class) as $name => $info) {
				$fqcn = $info['class'];
				$path = $info['path'];

				try {
					/** @var Task $task */
					$task = new $fqcn($this->wire());
					$event = new Event();
					$task->schedule($event);
				} catch (\Throwable) {
					continue;
				}

				$expression = $event->getExpression();
				$isDue = false;
				$nextRun = $this->_('Unknown');

				try {
					$isDue = $event->isDue($now);
					$cron = new CronExpression($expression);
					$nextRun = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
				} catch (\Throwable) {
					// Keep fallback if cron expression is invalid
				}

				$lastRaw = $cache->get('wire-console:schedule:last-run:' . $name);
				$lastTriggered = is_string($lastRaw) && $lastRaw !== '' ? $lastRaw : '—';

				$tasks[] = [
					'name' => $name,
					'class' => $fqcn,
					'path' => $path,
					'expression' => $expression,
					'description' => $fqcn,
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
		if ($this->createDiscoverer() === null) {
			return [
				'ok' => false,
				'executed' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Scheduler runtime is unavailable because trk/processwire-console is not installed.'),
			];
		}

		if ($this->hasUnsafeFeatureFiles('schedule', '*Task.php')) {
			$msg = $this->_('Refusing to execute scheduled tasks while symbolic link or out-of-root task files exist.');

			return [
				'ok' => false,
				'executed' => [],
				'errors' => ['schedule' => $msg],
				'details' => '',
				'message' => $msg,
			];
		}

		try {
			$classes = [];
			foreach ($this->discoverVerifiedClasses('schedule', '*Task.php', Task::class) as $name => $info) {
				$classes[$name] = $info['class'];
			}
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
			$cache = $this->wire()->cache;
			$executed = [];
			$errors = [];
			$detailLines = [];

			if (!$onlyDue) {
				$matchedTask = null;
				if (isset($classes[$target])) {
					$matchedTask = $target;
				} elseif (isset($classes[$target . 'Task'])) {
					$matchedTask = $target . 'Task';
				}
				if ($matchedTask === null) {
					return [
						'ok' => false,
						'executed' => [],
						'errors' => [],
						'details' => '',
						'message' => sprintf($this->_('Scheduled task "%s" was not found.'), $target),
					];
				}

				$fqcn = $classes[$matchedTask];
				/** @var Task $taskInstance */
				$taskInstance = new $fqcn($this->wire());
				try {
					/** @var callable $handler */
					$handler = [$taskInstance, 'handle'];
					$handler();
					$executed[] = $matchedTask;
					$cache->save('wire-console:schedule:last-run:' . $matchedTask, date('Y-m-d H:i:s'), 86400 * 30);
					$detailLines[] = sprintf('+ %s', $matchedTask);
				} catch (\Throwable $taskErr) {
					$errMsg = $taskErr->getMessage();
					$errors[$matchedTask] = $errMsg;
					$detailLines[] = sprintf('! %s: %s', $matchedTask, $errMsg);
				}
			} else {
				$res = $this->runConsoleCommandInProcess(ScheduleRunCommand::class);
				$executedRaw = isset($res['data']['executed']) && is_array($res['data']['executed'])
					? array_values(array_map('strval', $res['data']['executed']))
					: [];
				$errorsRaw = isset($res['data']['errors']) && is_array($res['data']['errors'])
					? $res['data']['errors']
					: [];

				foreach ($executedRaw as $name) {
					$executed[] = $name;
					$cache->save('wire-console:schedule:last-run:' . $name, date('Y-m-d H:i:s'), 86400 * 30);
					$detailLines[] = sprintf('+ %s', $name);
				}

				foreach ($errorsRaw as $name => $errMsg) {
					$taskName = (string) $name;
					$errors[$taskName] = (string) $errMsg;
					$detailLines[] = sprintf('! %s: %s', $taskName, (string) $errMsg);
				}

				if (!$res['ok'] && $errors === [] && $res['error'] !== null) {
					$errors['schedule:run'] = $res['error']['message'];
					$detailLines[] = '! ' . $res['error']['message'];
				}
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
