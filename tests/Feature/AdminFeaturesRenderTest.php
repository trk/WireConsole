<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\React;
use ProcessWire\ProcessWire;
use ProcessWire\WirePermissionException;
use Totoglu\React\Icon\IconManager;

require_once __DIR__ . '/../bootstrap.php';

beforeEach(function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$pw?->input?->post->removeAll();
	$pw?->input?->get->removeAll();
});

afterEach(function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$homePage = $pw?->pages?->get(1);
	if ($homePage && $homePage->id) {
		$pw?->fuel?->set('page', $homePage);
	}
});

test('ProcessWireConsole executes and renders the Inertia WireConsole/Workspace application with React runtime assets', function (): void {
	$module = wcTestModule();
	$assets = $module->getModuleScriptAssets();
	$html = $module->execute();

	expect($assets)->toBeArray()
		->and(implode(' ', $assets))->toContain('ReactIcons.js')
		->and(implode(' ', $assets))->toContain('ProcessWireConsole.js')
		->and($html)->toContain('id="app"')
		->and($html)->toContain('data-page=')
		->and($html)->toContain('WireConsole/Workspace')
		->and($html)->toContain('ReactInertia.css')
		->and($html)->toContain('ReactInertia.js')
		->and($html)->toContain('ReactIcons.js')
		->and($html)->toContain('ProcessWireConsole.js')
		->and($html)->toContain('processwire-console')
		->and($html)->toContain('processwire-boost');
});

test('ProcessWireConsole switches between all 9 workspace sections and builds structured Inertia props', function (): void {
	$module = wcTestModule();

	$sectionMethods = [
		'overview' => 'execute',
		'migrations' => 'executeMigrations',
		'queues' => 'executeQueues',
		'seeding' => 'executeSeeding',
		'scheduling' => 'executeScheduling',
		'backup' => 'executeBackup',
		'testing' => 'executeTesting',
		'boost' => 'executeBoost',
		'logs' => 'executeLogs',
	];

	foreach ($sectionMethods as $tab => $method) {
		$html = $module->{$method}();
		expect($html)->toContain('WireConsole/Workspace')
			->and($html)->toContain('activeTab')
			->and($html)->toContain($tab);
	}

	$payload = $module->runtime()->buildWorkspacePayload('boost');
	expect($payload)->toBeArray()
		->and($payload['activeTab'])->toBe('boost')
		->and($payload['isSuperuser'])->toBeTrue()
		->and($payload['tabs'])->toHaveKeys(['overview', 'migrations', 'queues', 'seeding', 'scheduling', 'backup', 'testing', 'boost', 'logs'])
		->and($payload['boost']['mcp']['toolCount'])->toBe(28)
		->and($payload['boost']['readOnlyTools'])->toContain('pw_schema_read');
});

test('ProcessWireConsole executeAction processes posted actions and file inspections via ConsoleRuntime and enforces CSRF', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();

	$pw->input->post->set('action', 'inspectFile');
	$pw->input->post->set('inspect_category', 'boost-doc');
	$pw->input->post->set('inspect_file', 'agents-md');
	$pw->input->post->set('tab', 'boost');
	$pw->input->post->set('_token', (string) $pw->session->CSRF->getTokenValue());

	$html = $module->executeAction();

	expect($html)->toContain('WireConsole/Workspace')
		->and($html)->toContain('AGENTS.md')
		->and($html)->toContain('boost');

	$pw->input->post->set('_token', 'invalid-csrf-token');
	$rejectedHtml = $module->executeAction();
	expect($rejectedHtml)->toContain('Invalid or missing CSRF token');
	$pw->input->post->remove('_token');
});

test('ProcessWireConsole registers the console workspace in React WorkspaceRegistry', function (): void {
	$module = wcTestModule();
	$workspace = React::instance($module);
	$registry = $workspace->workspaces();
	$def = $registry->get('console');

	expect($def)->not->toBeNull()
		->and($def->id)->toBe('console')
		->and($def->process)->toBe('ProcessWireConsole')
		->and($def->component)->toBe('WireConsole/Workspace');

	$widget = $workspace->widgets()->get('console-overview');
	expect($widget)->not->toBeNull()
		->and($widget->id)->toBe('console-overview')
		->and($widget->workspaceId)->toBe('console');

	$resolvedData = $workspace->widgets()->resolveData($widget);
	$catalog = IconManager::loadCatalog()['outline'] ?? [];
	expect($resolvedData)->toBeArray()
		->and($resolvedData)->toHaveKeys(['status', 'metrics', 'items', 'actions'])
		->and($resolvedData['metrics'])->toBeArray()->not->toBeEmpty()
		->and($resolvedData['items'])->toBeArray()->not->toBeEmpty()
		->and($def->icon)->toBe('terminal')
		->and($widget->icon)->toBe('terminal');

	foreach ($resolvedData['items'] as $item) {
		expect(array_key_exists($item['icon'], $catalog))->toBeTrue("Widget item icon {$item['icon']} must exist in vector outline catalog");
	}
	foreach ($resolvedData['actions'] as $action) {
		expect(array_key_exists($action['icon'], $catalog))->toBeTrue("Widget action icon {$action['icon']} must exist in vector outline catalog");
	}
	foreach ($module->runtime()->getTabDefinitions() as $tabKey => $tabMeta) {
		expect(array_key_exists($tabMeta['icon'], $catalog))->toBeTrue("Tab {$tabKey} icon {$tabMeta['icon']} must exist in vector outline catalog");
	}
});

test('ProcessWireConsole executeNavJSON returns accessible tabs with classic admin flyout compatible icons', function (): void {
	$module = wcTestModule();
	$json = $module->executeNavJSON();
	$decoded = json_decode((string) $json, true);

	expect($decoded)->toBeArray()
		->and($decoded)->toHaveKeys(['url', 'label', 'icon', 'add', 'list'])
		->and($decoded['url'])->not->toBeEmpty()
		->and($decoded['add'])->toBeNull()
		->and(count($decoded['list']))->toBe(9)
		->and($decoded['list'][0]['url'])->toBe('')
		->and($decoded['list'][0]['icon'])->toBe('dashboard')
		->and($decoded['list'][1]['url'])->toBe('migrations/')
		->and($decoded['list'][1]['icon'])->toBe('database')
		->and($decoded['list'][2]['icon'])->toBe('refresh')
		->and($decoded['list'][3]['icon'])->toBe('leaf')
		->and($decoded['list'][4]['icon'])->toBe('clock-o')
		->and($decoded['list'][5]['icon'])->toBe('hdd-o')
		->and($decoded['list'][6]['icon'])->toBe('flask')
		->and($decoded['list'][7]['icon'])->toBe('magic')
		->and($decoded['list'][8]['icon'])->toBe('list-alt');
});

test('ProcessWireConsole blocks unprivileged guest access across renderSection and executeAction', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$guest = $pw->users->get('name=guest');
	expect($guest)->not->toBeNull();
	$pw->users->setCurrentUser($guest);

	expect(fn () => $module->execute())->toThrow(WirePermissionException::class)
		->and(fn () => $module->executeMigrations())->toThrow(WirePermissionException::class)
		->and(fn () => $module->executeAction())->toThrow(WirePermissionException::class);
});
