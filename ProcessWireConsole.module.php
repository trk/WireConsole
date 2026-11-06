<?php

declare(strict_types=1);

namespace ProcessWire;

use Htmx\Component\ConsoleWorkspace;
use Totoglu\WireConsole\ConsoleRuntime;

/**
 * ProcessWireConsole
 *
 * HTMX v4-powered Process module providing an administrative control plane for
 * ProcessWire Console & Boost (`Setup > Console`, `/processwire/setup/wire-console/`),
 * built on top of `WireKit` UI primitives and dedicated per-section `Console*Panel` components.
 *
 * @method string execute()
 * @method string executeNavJSON(array $options = [])
 * @method void executeDownloadBackup()
 * @method void uninstall()
 */
class ProcessWireConsole extends Process
{
	public const PERMISSION_VIEW = 'wire-console';
	public const PERMISSION_MIGRATIONS = 'wire-console-migrations';
	public const PERMISSION_QUEUES = 'wire-console-queues';
	public const PERMISSION_SEEDING = 'wire-console-seeding';
	public const PERMISSION_SCHEDULING = 'wire-console-scheduling';
	public const PERMISSION_BACKUP = 'wire-console-backup';
	public const PERMISSION_TESTING = 'wire-console-testing';
	public const PERMISSION_BOOST = 'wire-console-boost';
	public const PERMISSION_LOGS = 'wire-console-logs';

	protected ?ConsoleRuntime $runtimeInstance = null;

	public function __construct()
	{
		parent::__construct();
		$this->wire()->classLoader->addNamespace('Totoglu\\WireConsole', __DIR__ . '/src/');
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo(): array
	{
		return [
			'title' => 'Console',
			'version' => '2.0.0',
			'summary' => 'HTMX v4 administrative workspace for ProcessWire Console and Boost (migrations, queues, seeders, scheduling, backups, Pest testing, Boost AI sync, and logs).',
			'author' => 'Iskender TOTOGLU',
			'href' => 'https://github.com/trk/WireConsole',
			'icon' => 'terminal',
			'singular' => true,
			'autoload' => true,
			'useNavJSON' => true,
			'page' => [
				'name' => 'wire-console',
				'parent' => 'setup',
				'title' => 'Console',
			],
			'permission' => self::PERMISSION_VIEW,
			'permissions' => [
				self::PERMISSION_VIEW => 'Access Console workspace (Setup > Console)',
				self::PERMISSION_MIGRATIONS => 'Console: View schema migrations',
				self::PERMISSION_QUEUES => 'Console: View background queues and failed jobs',
				self::PERMISSION_SEEDING => 'Console: View database seeders',
				self::PERMISSION_SCHEDULING => 'Console: View scheduled cron tasks',
				self::PERMISSION_BACKUP => 'Console: View SQL database backups',
				self::PERMISSION_TESTING => 'Console: View Pest PHP test runner',
				self::PERMISSION_BOOST => 'Console: View ProcessWire Boost AI guidelines, skills, and MCP status',
				self::PERMISSION_LOGS => 'Console: View Console audit trail and ProcessWire logs',
			],
			'requires' => [
				'PHP>=8.4',
				'ProcessWire>=3.0.271',
				'WireKit',
			],
		];
	}

	/**
	 * Ensure ProcessWire treats ProcessWireConsole as an autoload module so its
	 * HTMX v4 components and UI classes are registered before `/hx/req` requests run.
	 */
	public function isAutoload(): bool
	{
		return true;
	}

	public function init(): void
	{
		$this->registerWorkspace();
	}

	/**
	 * Register `ConsoleWorkspace` with `WireKit`.
	 */
	private function registerWorkspace(): void
	{
		WireKit::instance($this)->registerWorkspace([
			'id' => 'console',
			'title' => $this->_('Console'),
			'description' => $this->_('Monitor system health, background tasks, scheduled jobs, database backups, and activity logs.'),
			'icon' => 'terminal',
			'process' => 'ProcessWireConsole',
			'component' => ConsoleWorkspace::class,
			'category' => $this->_('System'),
			'order' => 60,
			'permission' => self::PERMISSION_VIEW,
			'statsCallback' => function (): array {
				$overview = $this->runtime()->getOverviewTelemetry();
				$isDown = !empty($overview['maintenance']['isDown']);
				$scheduledCount = (int) ($overview['scheduling']['totalCount'] ?? 0);

				return [
					[
						'label' => $this->_('Status'),
						'value' => $isDown ? $this->_('Maintenance') : $this->_('Online'),
						'tone' => $isDown ? 'warning' : 'success',
					],
					['label' => $this->_('Scheduled'), 'value' => (string) $scheduledCount],
				];
			},
		]);
	}

	/**
	 * Return the wired ConsoleRuntime domain service.
	 */
	public function runtime(): ConsoleRuntime
	{
		return $this->runtimeInstance ??= ConsoleRuntime::make($this->wire());
	}

	/**
	 * Render the main Console workspace (`Setup > Console`).
	 */
	public function ___execute(): string
	{
		return $this->renderSection('overview');
	}

	/**
	 * Migrations section (`./migrations/`).
	 */
	public function ___executeMigrations(): string
	{
		return $this->renderSection('migrations');
	}

	/**
	 * Queues section (`./queues/`).
	 */
	public function ___executeQueues(): string
	{
		return $this->renderSection('queues');
	}

	/**
	 * Seeding section (`./seeding/`).
	 */
	public function ___executeSeeding(): string
	{
		return $this->renderSection('seeding');
	}

	/**
	 * Scheduling section (`./scheduling/`).
	 */
	public function ___executeScheduling(): string
	{
		return $this->renderSection('scheduling');
	}

	/**
	 * Backups section (`./backup/`).
	 */
	public function ___executeBackup(): string
	{
		return $this->renderSection('backup');
	}

	/**
	 * Testing section (`./testing/`).
	 */
	public function ___executeTesting(): string
	{
		return $this->renderSection('testing');
	}

	/**
	 * Boost & AI section (`./boost/`).
	 */
	public function ___executeBoost(): string
	{
		return $this->renderSection('boost');
	}

	/**
	 * Logs & Audit section (`./logs/`).
	 */
	public function ___executeLogs(): string
	{
		return $this->renderSection('logs');
	}

	/**
	 * Render the Console workspace for `$section`; HTMX section requests receive only the workspace markup.
	 */
	private function renderSection(string $section): string
	{
		$this->loadWorkspaceAssets();
		$this->resetBreadcrumbsToConsoleRoot($section);

		$kit = WireKit::instance($this);
		$html = $kit->renderComponent(ConsoleWorkspace::class, [
			'activeTab' => $section,
		]);

		return $kit->workspaceResponse($html);
	}

	/**
	 * Render the AdminThemeUikit flyout navigation JSON for `Setup > Console`.
	 *
	 * @param array<string, mixed> $options
	 * @return string|array<string, mixed>
	 */
	public function ___executeNavJSON(array $options = []): string|array
	{
		$tabs = $this->runtime()->getAccessibleTabs();
		$items = [];

		foreach ($tabs as $key => $meta) {
			$items[] = [
				'id' => $key === 'overview' ? '' : rawurlencode((string) $key) . '/',
				'name' => html_entity_decode($meta['label'], ENT_QUOTES, 'UTF-8'),
				'icon' => $meta['icon'],
				'_class' => '',
			];
		}

		$options['items'] = $items;
		$options['itemLabel'] = 'name';
		$options['edit'] = '{id}';
		$options['add'] = null;
		$options['iconKey'] = 'icon';
		$options['classKey'] = '_class';
		$options['sort'] = false;

		return parent::___executeNavJSON($options);
	}

	/**
	 * Stream a verified `.sql` database backup file to a Superuser with `wire-console-backup` permission.
	 *
	 * @throws WirePermissionException
	 * @throws Wire404Exception
	 */
	public function ___executeDownloadBackup(): void
	{
		$runtime = $this->runtime();
		if (!$runtime->userCanView(self::PERMISSION_BACKUP) || !$runtime->isSuperuser()) {
			$runtime->audit('backup.download', 'denied_rbac');
			throw new WirePermissionException($this->_('Superuser permission is required to download SQL backups.'));
		}

		$fileName = (string) ($this->wire()->input->get('file') ?? '');
		$resolvedPath = $runtime->resolveBackupFilePath($fileName);
		if ($resolvedPath === null) {
			$runtime->audit('backup.download', 'invalid_file', ['file' => $fileName]);
			throw new Wire404Exception($this->_('Requested SQL backup file was not found.'));
		}

		$runtime->audit('backup.download', 'ok', ['file' => basename($resolvedPath)]);
		wireSendFile($resolvedPath, [
			'exit' => true,
			'forceDownload' => true,
			'downloadFilename' => basename($resolvedPath),
		]);
	}

	/**
	 * Load WireKit.css, WireKit.js (section URL & breadcrumb sync), ProcessWireConsole.css,
	 * and HTMX frontend script when rendering the Console admin page.
	 */
	protected function loadWorkspaceAssets(): void
	{
		$config = $this->wire()->config;
		if (!$config) {
			return;
		}

		$modules = $this->wire()->modules;
		if ($modules && $modules->isInstalled('WireKit')) {
			/** @var WireKit|null $kit */
			$kit = $modules->get('WireKit');
			$kit?->loadAssets();
		}

		$moduleDir = basename(__DIR__);
		$moduleUrl = rtrim((string) $config->urls->siteModules, '/') . '/' . $moduleDir . '/';
		$cssPath = __DIR__ . '/ProcessWireConsole.css';

		if (is_file($cssPath)) {
			$v = (string) (@filemtime($cssPath) ?: '2.0.0');
			$config->styles->add($moduleUrl . 'ProcessWireConsole.css?v=' . $v);
		}
	}

	/**
	 * Reset admin breadcrumbs so they start directly at the Console root.
	 */
	protected function resetBreadcrumbsToConsoleRoot(string $activeTab = 'overview'): void
	{
		$rootUrl = $this->rootUrl();
		$cleanTab = strtolower(trim($activeTab));
		$sectionLabel = '';
		$sectionUrl = '';

		if ($cleanTab !== '' && $cleanTab !== 'overview') {
			$tabs = $this->runtime()->getAccessibleTabs();
			if (isset($tabs[$cleanTab])) {
				$sectionLabel = $tabs[$cleanTab]['label'];
				$sectionUrl = $rootUrl . rawurlencode($cleanTab) . '/';
			}
		}

		WireKit::instance($this)->resetBreadcrumbs(
			$this,
			$rootUrl,
			$this->_('Console'),
			$sectionLabel,
			$sectionUrl
		);
	}

	/**
	 * Ensure breadcrumb labels are never double-encoded by AdminThemeUikit.
	 *
	 * @param string $href
	 * @param string $label
	 */
	public function ___breadcrumb($href, $label = ''): self
	{
		if ($this->wire()->breadcrumbs === null) {
			$this->wire('breadcrumbs', $this->wire(new Breadcrumbs()));
		}

		return parent::___breadcrumb($href, html_entity_decode((string) $label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Resolve the admin base URL for the Console process page.
	 */
	protected function rootUrl(): string
	{
		$page = $this->wire()->page;
		if ($page && (string) $page->process === 'ProcessWireConsole' && (string) $page->url !== '') {
			return rtrim((string) $page->url, '/') . '/';
		}

		$adminUrl = (string) ($this->wire()->config?->urls?->admin ?? '/processwire/');

		return rtrim($adminUrl, '/') . '/setup/wire-console/';
	}

	/**
	 * Ensure all Console permissions exist idempotently on install.
	 */
	public function ___install(): void
	{
		parent::___install();

		$permissions = $this->wire()?->permissions;
		if ($permissions !== null) {
			$info = self::getModuleInfo();
			$declared = is_array($info['permissions'] ?? null) ? $info['permissions'] : [];
			foreach ($declared as $permName => $permTitle) {
				$existing = $permissions->get((string) $permName);
				if (!$existing || !$existing->id) {
					try {
						$perm = $permissions->add((string) $permName);
						$perm->title = (string) $permTitle;
						$permissions->save($perm);
					} catch (\Throwable) {
					}
				}
			}
		}
	}

	/**
	 * Remove the Console audit log channel, cached schedule timestamps, and permissions on module uninstall.
	 */
	public function ___uninstall(): void
	{
		$this->wire()?->log?->delete(ConsoleRuntime::LOG_CHANNEL);
		$this->wire()?->cache?->delete('wire-console*');

		$permissions = $this->wire()?->permissions;
		if ($permissions !== null) {
			$info = self::getModuleInfo();
			$declared = is_array($info['permissions'] ?? null) ? $info['permissions'] : [];
			foreach (array_keys($declared) as $permName) {
				foreach ($permissions->find("name={$permName}, include=all") as $perm) {
					if ($perm && $perm->id) {
						try {
							$permissions->delete($perm);
						} catch (\Throwable) {
						}
					}
				}
			}
			$this->wire()?->pages?->uncacheAll();
		}

		parent::___uninstall();
	}
}
