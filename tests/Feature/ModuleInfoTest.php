<?php

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;

require_once __DIR__ . '/../bootstrap.php';

test('ProcessWireConsole defines the standalone Console process module metadata and permissions', function (): void {
	$info = ProcessWireConsole::getModuleInfo();

	expect($info['title'])->toBe('Console')
		->and($info['singular'])->toBeTrue()
		->and($info['autoload'])->toBeTrue()
		->and($info['useNavJSON'])->toBeTrue()
		->and($info['page'])->toBe([
			'name' => 'wire-console',
			'parent' => 'setup',
			'title' => 'Console',
		])
		->and($info['permission'])->toBe('wire-console')
		->and($info['requires'])->toBe([
			'PHP>=8.4',
			'ProcessWire>=3.0.271',
			'WireKit',
		])
		->and(array_keys($info['permissions']))->toBe([
			'wire-console',
			'wire-console-migrations',
			'wire-console-queues',
			'wire-console-seeding',
			'wire-console-scheduling',
			'wire-console-backup',
			'wire-console-testing',
			'wire-console-boost',
			'wire-console-logs',
		]);
});

test('legacy WireConsole configurable module, UI shims, and monolithic rendering traits are removed', function (): void {
	$moduleRoot = dirname(__DIR__, 2);

	expect(is_file($moduleRoot . '/WireConsole.module.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/WireConsoleDependencyChecker.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ConsolePackageAdapter.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ui/ConsoleStatusBadge.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ui/ConsoleCliBox.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ui/ConsoleEmptyState.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ui/ConsoleNotice.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ui/ConsoleProgressBar.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/components/Concerns/RendersOverviewAndSystemPanels.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/components/Concerns/RendersDatabaseAndQueuePanels.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/components/Concerns/RendersSeedingSchedulingAndBackupPanels.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/components/Concerns/RendersTestingBoostAndLogPanels.php'))->toBeFalse()
		->and(is_file($moduleRoot . '/ProcessWireConsole.module.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/ui/ConsoleVersionStrip.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/ui/ConsoleOnboardingCard.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleOverviewPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleMigrationsPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleQueuesPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleSeedingPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleSchedulingPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleBackupPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleTestingPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleBoostPanel.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/components/ConsoleLogsPanel.php'))->toBeTrue();
});

test('ProcessWireConsole uninstall removes the wire-console-audit log channel, caches, and permissions', function (): void {
	$pw = \ProcessWire\ProcessWire::getCurrentInstance();
	$module = new class extends ProcessWireConsole {
		public function className($options = null): string
		{
			return 'ProcessWireConsole';
		}

		protected function installPage(
			$name = '',
			$parent = null,
			$title = '',
			$template = 'admin',
			$extras = array()
		) {
			return new \ProcessWire\NullPage();
		}

		protected function uninstallPage()
		{
			return 0;
		}
	};
	$module->setWire($pw);

	$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
	expect($composer['version'] ?? null)->toBe('2.0.0')
		->and($composer['require']['trk/processwire-wire-kit'] ?? null)->toBe('^1.0');

	$module->___install();
	expect($pw->permissions->get(ProcessWireConsole::PERMISSION_VIEW)?->id)->toBeGreaterThan(0);

	$module->runtime()->audit('uninstall_check', 'ok');
	$pw->cache->save('wire-console-test-key', '1', 60);
	$channelsBefore = array_column($module->runtime()->getLogChannels(), 'name');
	expect($channelsBefore)->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL);

	try {
		$module->___uninstall();

		$channelsAfter = array_column($module->runtime()->getLogChannels(), 'name');
		expect($channelsAfter)->not->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL)
			->and($pw->cache->get('wire-console-test-key'))->toBeNull()
			->and((int) ($pw->permissions->get(ProcessWireConsole::PERMISSION_VIEW)?->id ?? 0))->toBe(0);
	} finally {
		$module->___install();
	}
});

