<?php

declare(strict_types=1);

namespace ProcessWire;

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
use Totoglu\WireConsole\ConsoleRuntime;

require_once __DIR__ . '/src/ConsoleRuntime.php';

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
	public const PERMISSION_VIEW = ConsoleRuntime::PERMISSION_VIEW;
	public const PERMISSION_MIGRATIONS = ConsoleRuntime::PERMISSION_MIGRATIONS;
	public const PERMISSION_QUEUES = ConsoleRuntime::PERMISSION_QUEUES;
	public const PERMISSION_SEEDING = ConsoleRuntime::PERMISSION_SEEDING;
	public const PERMISSION_SCHEDULING = ConsoleRuntime::PERMISSION_SCHEDULING;
	public const PERMISSION_BACKUP = ConsoleRuntime::PERMISSION_BACKUP;
	public const PERMISSION_TESTING = ConsoleRuntime::PERMISSION_TESTING;
	public const PERMISSION_BOOST = ConsoleRuntime::PERMISSION_BOOST;
	public const PERMISSION_LOGS = ConsoleRuntime::PERMISSION_LOGS;

	protected bool $componentsBooted = false;
	protected bool $breadcrumbsHooked = false;
	protected ?ConsoleRuntime $runtimeInstance = null;

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

	/**
	 * Register PSR-4 namespaces and HTMX component paths as soon as the module is wired.
	 */
	public function wired(): void
	{
		parent::wired();
		$this->bootComponents();
	}

	/**
	 * Initialize module namespaces and HTMX component bindings without loading
	 * Process CSS/JS assets on non-Console requests.
	 */
	public function init(): void
	{
		$this->bootComponents();
	}

	/**
	 * Register `Totoglu\WireConsole`, `Htmx\Component`, and `Htmx\Ui` namespaces
	 * and expose `ConsoleWorkspace` and its section panels to `Htmx` and `WireKit`.
	 */
	public function bootComponents(): void
	{
		if ($this->componentsBooted) {
			return;
		}

		$modules = $this->wire()->modules;
		if (class_exists(WireKit::class)) {
			try {
				$kit = WireKit::instance($this);
				$kit->bootComponents();
				$kit->registerWorkspace([
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
			} catch (\Throwable) {
				// Fallback if WireKit is still initializing
			}
		}

		$classLoader = $this->wire()->classLoader;
		if ($classLoader) {
			$classLoader->addNamespace('Totoglu\\WireConsole', __DIR__ . '/src/');
			$classLoader->addNamespace('Htmx\\Component', __DIR__ . '/components/');
			$classLoader->addNamespace('Htmx\\Ui', __DIR__ . '/ui/');
		}

		if ($modules && $modules->isInstalled('Htmx')) {
			try {
				/** @var Htmx|null $htmx */
				$htmx = $modules->get('Htmx');
				if ($htmx) {
					$components = [
						'ConsoleWorkspace' => ConsoleWorkspace::class,
						'ConsoleOverviewPanel' => ConsoleOverviewPanel::class,
						'ConsoleMigrationsPanel' => ConsoleMigrationsPanel::class,
						'ConsoleQueuesPanel' => ConsoleQueuesPanel::class,
						'ConsoleSeedingPanel' => ConsoleSeedingPanel::class,
						'ConsoleSchedulingPanel' => ConsoleSchedulingPanel::class,
						'ConsoleBackupPanel' => ConsoleBackupPanel::class,
						'ConsoleTestingPanel' => ConsoleTestingPanel::class,
						'ConsoleBoostPanel' => ConsoleBoostPanel::class,
						'ConsoleLogsPanel' => ConsoleLogsPanel::class,
					];

					foreach ($components as $shortName => $fqcn) {
						$htmx->registerComponent($shortName, $fqcn);
						$htmx->registerComponent($fqcn, $fqcn);
					}

					$allowed = is_array($htmx->get('allowedComponentPaths')) ? $htmx->get('allowedComponentPaths') : [];
					foreach ([__DIR__ . '/components', __DIR__ . '/ui'] as $dir) {
						if (!in_array($dir, $allowed, true)) {
							$allowed[] = $dir;
						}
					}
					$htmx->set('allowedComponentPaths', $allowed);
				}
			} catch (\Throwable) {
				// Htmx module will resolve via PSR-4 fallback if not yet ready
			}
		}

		$this->componentsBooted = true;
	}

	/**
	 * Return the wired ConsoleRuntime domain service.
	 */
	public function runtime(): ConsoleRuntime
	{
		$this->bootComponents();

		return $this->runtimeInstance ??= ConsoleRuntime::make($this->wire());
	}

	/**
	 * Render the main Console workspace (`Setup > Console`).
	 */
	public function ___execute(): string
	{
		$this->bootComponents();
		$this->loadWorkspaceAssets();

		$requestedTab = (string) ($this->wire()->input->get('tab') ?? 'overview');
		$this->resetBreadcrumbsToConsoleRoot($requestedTab);

		/** @var Htmx|null $htmx */
		$htmx = $this->wire()->htmx;
		if ($htmx instanceof Htmx) {
			return $htmx->renderComponent(ConsoleWorkspace::class, [
				'activeTab' => $requestedTab,
			]);
		}

		$workspace = new ConsoleWorkspace();
		$workspace->setWire($this->wire());
		$workspace->fill(['activeTab' => $requestedTab]);
		$workspace->mount($requestedTab);

		return $workspace->renderToString();
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
				'id' => '?tab=' . rawurlencode($key),
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
	 * Load WireKit.css, WireKit.js, ProcessWireConsole.css, ProcessWireConsole.js,
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
		$jsPath = __DIR__ . '/ProcessWireConsole.js';

		if (is_file($cssPath)) {
			$v = (string) (@filemtime($cssPath) ?: '2.0.0');
			$config->styles->add($moduleUrl . 'ProcessWireConsole.css?v=' . $v);
		}
		if (is_file($jsPath)) {
			$v = (string) (@filemtime($jsPath) ?: '2.0.0');
			$config->scripts->add($moduleUrl . 'ProcessWireConsole.js?v=' . $v);
		}

		if ($this->breadcrumbsHooked) {
			return;
		}
		$this->breadcrumbsHooked = true;

		$this->addHookAfter('AdminThemeUikit::renderBreadcrumbs', function (HookEvent $event): void {
			$adminTheme = $event->object;
			$breadcrumbs = $event->wire()->breadcrumbs;
			if ($breadcrumbs === null || count($breadcrumbs) === 0) {
				return;
			}
			if (isset($adminTheme->isModal) && $adminTheme->isModal) {
				return;
			}
			$out = '';
			if (method_exists($adminTheme, 'renderQuickTreeLink') && strpos((string) ($adminTheme->layout ?? ''), 'sidenav') === false) {
				$out .= '<li>' . $adminTheme->renderQuickTreeLink() . '</li>';
			}
			$sanitizer = $event->wire()->sanitizer;
			$index = 0;
			foreach ($breadcrumbs as $breadcrumb) {
				$title = $sanitizer ? $sanitizer->entities1((string) $breadcrumb->title) : htmlspecialchars((string) $breadcrumb->title, ENT_QUOTES, 'UTF-8', false);
				$url = $sanitizer ? $sanitizer->entities((string) $breadcrumb->url) : htmlspecialchars((string) $breadcrumb->url, ENT_QUOTES, 'UTF-8');
				$attr = $index === 0
					? " data-wk-crumb-root='1' data-wc-crumb-root='1'"
					: " data-wk-crumb-tab='1' data-wc-crumb-tab='1'";
				$out .= "<li{$attr}><a href='{$url}'>{$title}</a></li>";
				$index++;
			}
			$event->return = "<ul class='uk-breadcrumb'>{$out}</ul>";
		});
	}

	/**
	 * Reset admin breadcrumbs so they start directly at the Console root.
	 */
	protected function resetBreadcrumbsToConsoleRoot(string $activeTab = 'overview'): void
	{
		$breadcrumbs = $this->wire()->breadcrumbs;
		if ($breadcrumbs !== null) {
			$breadcrumbs->removeAll();
		}

		$rootUrl = $this->rootUrl();
		$this->breadcrumb($rootUrl, $this->_('Console'));

		$cleanTab = strtolower(trim($activeTab));
		if ($cleanTab !== '' && $cleanTab !== 'overview') {
			$tabs = $this->runtime()->getAccessibleTabs();
			if (isset($tabs[$cleanTab])) {
				$this->breadcrumb($rootUrl . '?tab=' . rawurlencode($cleanTab), $tabs[$cleanTab]['label']);
			}
		}
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
