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
			'Htmx',
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

test('ProcessWireConsole uninstall removes the wire-console-audit log channel', function (): void {
	$pw = \ProcessWire\ProcessWire::getCurrentInstance();
	$module = new class extends ProcessWireConsole {
		public function className($options = null): string
		{
			return 'ProcessWireConsole';
		}

		protected function uninstallPage()
		{
			return 0;
		}
	};
	$module->setWire($pw);

	$module->runtime()->audit('uninstall_check', 'ok');
	$channelsBefore = array_column($module->runtime()->getLogChannels(), 'name');
	expect($channelsBefore)->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL);

	$module->___uninstall();

	$channelsAfter = array_column($module->runtime()->getLogChannels(), 'name');
	expect($channelsAfter)->not->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL);
});

