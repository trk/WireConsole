<?php

declare(strict_types=1);

use Htmx\Component\ConsoleBackupPanel;
use Htmx\Component\ConsoleBoostPanel;
use Htmx\Component\ConsoleLogsPanel;
use Htmx\Component\ConsoleMigrationsPanel;
use Htmx\Component\ConsoleOverviewPanel;
use Htmx\Component\ConsoleQueuesPanel;
use Htmx\Component\ConsoleSchedulingPanel;
use Htmx\Component\ConsoleSeedingPanel;
use Htmx\Component\ConsoleTestingPanel;
use Htmx\Component\ConsoleWorkspace;
use Htmx\Ui\ConsoleOnboardingCard;
use Htmx\Ui\ConsoleVersionStrip;
use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCliBox;
use Htmx\Ui\KitCodePreview;
use Htmx\Ui\KitConfirmModal;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitNotice;
use Htmx\Ui\KitProgressBar;
use ProcessWire\ProcessWire;

require_once __DIR__ . '/../bootstrap.php';

beforeEach(function (): void {
	wcSetSuperuser();
});

test('ProcessWireConsole executes and renders the HTMX v4 ConsoleWorkspace component with WireKit primitives', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$html = $module->execute();

	$breadcrumbs = $pw->breadcrumbs;
	expect($breadcrumbs)->not->toBeNull()
		->and($breadcrumbs->count())->toBe(1)
		->and($breadcrumbs->first()->title)->toBe('Console')
		->and($breadcrumbs->first()->url)->toContain('/setup/wire-console/');

	expect($html)->toContain('id="hxc_wc_workspace"')
		->and($html)->toContain('hx-action="/hx/req"')
		->and($html)->toContain('hx-method="post"')
		->and($html)->toContain('hx-swap="outerMorph"')
		->and($html)->toContain('hx__state__hxc_wc_workspace')
		->and($html)->toContain('ProcessWire Console')
		->and($html)->toContain('processwire-console')
		->and($html)->toContain('processwire-boost')
		->and($html)->toContain('<span>Boost &amp; AI</span>')
		->and($html)->toContain('<span>Logs &amp; Audit</span>')
		->and($html)->not->toContain('&amp;amp;')
		->and($html)->toContain('wk-progress--indeterminate')
		->and($html)->toContain('name="cache_clear_mode"')
		->and($html)->toContain('WireCache Only (cache:wire:clear)')
		->and($html)->toContain('Modules Registry Only (module:refresh)');
});

test('ConsoleWorkspace switches between all 9 dedicated Console*Panel components via querySwitchTab and selects log channels', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();

	$tabs = ['overview', 'migrations', 'queues', 'seeding', 'scheduling', 'backup', 'testing', 'boost', 'logs'];
	foreach ($tabs as $tab) {
		$workspace = new ConsoleWorkspace();
		$workspace->setWire($pw);
		$workspace->fill(['activeTab' => 'overview']);
		$workspace->querySwitchTab($tab);

		$html = $workspace->render();
		$headerPos = strpos($html, 'class="wc-header"');
		$versionPos = strpos($html, 'wk-version-strip');
		$tabsPos = strpos($html, 'wk-tabs');
		$deckPos = strpos($html, 'class="wk-action-deck"');
		$panelPos = strpos($html, 'class="wk-panel"');

		expect($workspace->activeTab)->toBe($tab)
			->and($html)->toContain('data-wc-active-tab="' . $tab . '"')
			->and($headerPos)->toBeInt()
			->and($versionPos)->toBeInt()
			->and($tabsPos)->toBeInt()
			->and($deckPos)->toBeInt()
			->and($panelPos)->toBeInt()
			->and($headerPos)->toBeLessThan($versionPos)
			->and($versionPos)->toBeLessThan($tabsPos)
			->and($tabsPos)->toBeLessThan($deckPos)
			->and($deckPos)->toBeLessThan($panelPos)
			->and($html)->toContain('<dialog id="wk-confirm-dialog"')
			->and($html)->toContain('wk-confirm-dialog')
			->and($html)->toContain('data-wk-confirm-word=');

		if ($tab === 'queues') {
			expect($html)->toContain('Pending Jobs (queue_jobs)')
				->and($html)->toContain('value="purgePendingJobs"');
		}
		if ($tab === 'boost') {
			expect($html)->toContain('MCP Server Diagnostics')
				->and($html)->toContain('pw_schema_read')
				->and($html)->toContain('value="runMcpReadOnlyTool"')
				->and($html)->toContain('fa-cube')
				->and($html)->toContain('modules synced');
		}
	}

	$module->runtime()->audit('test.phase5_render', 'ok', ['sample' => 'value']);
	$logWorkspace = new ConsoleWorkspace();
	$logWorkspace->setWire($pw);
	$logWorkspace->fill(['activeTab' => 'logs']);
	$logWorkspace->querySelectLogChannel('wire-console-audit');
	$logHtml = $logWorkspace->render();

	expect($logWorkspace->activeLogChannel)->toBe('wire-console-audit')
		->and($logHtml)->toContain('wire-console-audit')
		->and($logHtml)->toContain('test.phase5_render')
		->and($logHtml)->toContain('<span class="wk-badge__text">ok</span>')
		->and($logHtml)->toContain('Context JSON');

	$inspectWorkspace = new ConsoleWorkspace();
	$inspectWorkspace->setWire($pw);
	$inspectWorkspace->fill(['activeTab' => 'boost']);
	$inspectWorkspace->queryInspectFile('skill', 'pw-htmx');
	$inspectHtml = $inspectWorkspace->render();

	expect($inspectHtml)->toContain('wk-inspector-card')
		->and($inspectHtml)->toContain('.agents/skills/pw-htmx/SKILL.md');

	$inspectWorkspace->queryCloseInspect();
	expect($inspectWorkspace->render())->not->toContain('wk-inspector-card');

	// Verify boost-doc and test file inspection in ConsoleWorkspace
	$inspectWorkspace->queryInspectFile('boost-doc', 'agents-md');
	$boostDocHtml = $inspectWorkspace->render();
	expect($boostDocHtml)->toContain('wk-inspector-card')
		->and($boostDocHtml)->toContain('AGENTS.md');

	$inspectWorkspace->fill(['activeTab' => 'testing']);
	$inspectWorkspace->queryInspectFile('test', 'ActionGuardsTest');
	$testFileHtml = $inspectWorkspace->render();
	expect($testFileHtml)->toContain('wk-inspector-card')
		->and($testFileHtml)->toContain('ActionGuardsTest.php');

	// Verify queryFilterLogs updates state and renders filtered log view (including live HTMX search attributes & reset)
	$pw->input->post->set('log_search', 'verified');
	$pw->input->post->set('log_limit', 25);
	$logWorkspace->queryFilterLogs();
	$filteredLogHtml = $logWorkspace->render();
	expect($logWorkspace->logSearch)->toBe('verified')
		->and($logWorkspace->logLimit)->toBe(25)
		->and($filteredLogHtml)->toContain('hx-trigger="input changed delay:280ms, search"')
		->and($filteredLogHtml)->toContain('queryResetLogFilter');

	$logWorkspace->queryResetLogFilter();
	expect($logWorkspace->logSearch)->toBe('');

	// Verify queryRefresh sets notice and renders HTMX refresh trigger
	$logWorkspace->queryRefresh();
	$refreshedHtml = $logWorkspace->render();
	expect($refreshedHtml)->toContain('Refreshed')
		->and($refreshedHtml)->toContain('Telemetry and runtime state updated at')
		->and($refreshedHtml)->toContain('hx-vals="{&quot;__htmx_action&quot;:&quot;queryRefresh&quot;}"');
});

test('all 9 Console*Panel components can be instantiated and rendered independently', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$runtime = $module->runtime();

	$panelClasses = [
		ConsoleOverviewPanel::class,
		ConsoleMigrationsPanel::class,
		ConsoleQueuesPanel::class,
		ConsoleSeedingPanel::class,
		ConsoleSchedulingPanel::class,
		ConsoleBackupPanel::class,
		ConsoleTestingPanel::class,
		ConsoleBoostPanel::class,
		ConsoleLogsPanel::class,
	];

	foreach ($panelClasses as $panelClass) {
		$panel = new $panelClass();
		$panel->setWire($pw);
		$panel->setRuntime($runtime);
		$parts = $panel->renderSection();
		$fullHtml = $panel->render();

		expect($parts)->toBeArray()
			->and($parts)->toHaveKeys(['actions', 'panel'])
			->and($parts['panel'])->not->toBeEmpty()
			->and($fullHtml)->toContain('class="wk-panel"');
	}
});

test('ProcessWireConsole executeNavJSON returns accessible tabs for AdminThemeUikit flyout menu', function (): void {
	$module = wcTestModule();
	$json = $module->executeNavJSON();
	$decoded = json_decode((string) $json, true);

	expect($decoded)->toBeArray()
		->and($decoded)->toHaveKeys(['url', 'label', 'icon', 'add', 'list'])
		->and($decoded['url'])->not->toBeEmpty()
		->and($decoded['add'])->toBeNull()
		->and(count($decoded['list']))->toBe(9)
		->and($decoded['list'][0]['url'])->toBe('?tab=overview');
});

test('WireKit and Console-specific Htmx Ui elements render escaped semantic HTML and support make() factory', function (): void {
	$module = wcTestModule();
	$runtime = $module->runtime();

	$badge = KitBadge::make(['label' => 'Healthy <script>', 'variant' => 'success'])->render();
	expect($badge)->toContain('wk-badge--success')
		->and($badge)->toContain('Healthy &lt;script&gt;');

	$cli = KitCliBox::make([
		'command' => 'composer require trk/processwire-console',
		'caption' => 'Install command',
	])->render();
	expect($cli)->toContain('wk-cli-box')
		->and($cli)->toContain('data-wk-copy="composer require trk/processwire-console"');

	$empty = KitEmptyState::make([
		'title' => 'Empty Title',
		'description' => 'Empty Desc',
		'icon' => 'database',
		'cliCommand' => 'php vendor/bin/wire migrate',
	])->render();
	expect($empty)->toContain('wk-empty-state')
		->and($empty)->toContain('php vendor/bin/wire migrate');

	$notice = KitNotice::make([
		'message' => 'Operation succeeded',
		'variant' => 'success',
		'title' => 'Done',
		'details' => 'detail line',
	])->render();
	expect($notice)->toContain('wk-notice--success')
		->and($notice)->toContain('Operation succeeded')
		->and($notice)->toContain('detail line');

	$progress = KitProgressBar::make([
		'value' => 5,
		'max' => 10,
		'variant' => 'success',
		'label' => 'Migrations & Seeds',
		'meta' => '5 / 10 (50%)',
		'size' => 'md',
	])->render();
	expect($progress)->toContain('role="progressbar"')
		->and($progress)->toContain('aria-valuenow="50"')
		->and($progress)->toContain('width: 50%;')
		->and($progress)->toContain('Migrations &amp; Seeds')
		->and($progress)->not->toContain('&amp;amp;');

	$indeterminate = KitProgressBar::make()->indeterminate(true)->render();
	expect($indeterminate)->toContain('wk-progress--indeterminate');

	$actionCard = KitActionCard::make()
		->icon('play')
		->title('Run Migrations')
		->cliCommand('migrate')
		->confirm('confirm_migrations_run', 'MIGRATE')
		->actionMethod('runMigrations')
		->button('Run Pending', 'play')
		->disabled(true)
		->render();
	expect($actionCard)->toContain('wk-action-card')
		->and($actionCard)->toContain('wk-action-card--disabled')
		->and($actionCard)->toContain('data-wk-confirm-word="MIGRATE"');

	$confirmModal = KitConfirmModal::make()->render();
	expect($confirmModal)->toContain('id="wk-confirm-dialog"')
		->and($confirmModal)->toContain('data-wk-confirm-dialog="1"');

	$codePreview = KitCodePreview::make()->code('{"ok":true}')->collapsible(true, 'Payload')->render();
	expect($codePreview)->toContain('wk-inline-details')
		->and($codePreview)->toContain('wk-code-preview');

	$versionStrip = ConsoleVersionStrip::make(['versions' => $runtime->getVersionMatrix()])->render();
	expect($versionStrip)->toContain('wk-version-strip')
		->and($versionStrip)->toContain('WireKit')
		->and($versionStrip)->toContain('processwire-console')
		->and(strpos($versionStrip, 'Htmx'))->toBeLessThan(strpos($versionStrip, 'WireKit'))
		->and(strpos($versionStrip, 'WireKit'))->toBeLessThan(strpos($versionStrip, 'processwire-console'));

	$onboardingCard = ConsoleOnboardingCard::make(['packages' => $runtime->getPackagesStatus(), 'prominent' => true])->render();
	expect($onboardingCard)->toContain('wk-card--onboarding-alert')
		->and($onboardingCard)->toContain('trk/processwire-console');
});
