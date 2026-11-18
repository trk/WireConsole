<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\ProcessReactWorkspace\Icon\IconManager;
use Totoglu\ProcessReactWorkspace\Inertia\ProcessReact;
use Totoglu\WireConsole\ConsoleRuntime;

/**
 * ProcessWireConsole
 *
 * Inertia + React + Tailwind CSS v4 Process module providing a full-page
 * administrative control plane for ProcessWire Console & Boost (`Setup > Console`,
 * `{admin}/setup/wire-console/`), powered by `ProcessReactWorkspace` and `Inertia`.
 *
 * @method string execute()
 * @method string executeMigrations()
 * @method string executeQueues()
 * @method string executeSeeding()
 * @method string executeScheduling()
 * @method string executeBackup()
 * @method string executeTesting()
 * @method string executeBoost()
 * @method string executeLogs()
 * @method string executeAction()
 * @method string|array<string, mixed> executeNavJSON(array<string, mixed> $options = [])
 * @method void executeDownloadBackup()
 * @method array<string, mixed> translations()
 * @method void install()
 * @method void uninstall()
 */
class ProcessWireConsole extends ProcessReact
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
	public const MAX_JSON_BODY_BYTES = 65536;
	public const MAX_JSON_DEPTH = 16;
	public const MAX_JSON_ELEMENTS = 256;

	protected ?ConsoleRuntime $runtimeInstance = null;

	public function __construct()
	{
		parent::__construct();
		$this->wire()?->classLoader?->addNamespace('Totoglu\\WireConsole', __DIR__ . '/src/');
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo(): array
	{
		return [
			'title' => 'Console',
			'version' => 211,
			'summary' => 'Administrative workspace for system health, schema migrations, queues, seeders, scheduled tasks, database backups, testing, AI skills, and activity logs.',
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
				self::PERMISSION_TESTING => 'Console: View Pest PHP and Playwright E2E test runners',
				self::PERMISSION_BOOST => 'Console: View ProcessWire Boost AI guidelines, skills, and MCP status',
				self::PERMISSION_LOGS => 'Console: View Console audit trail and ProcessWire logs',
			],
			'requires' => [
				'PHP>=8.4',
				'ProcessWire>=3.0.271',
				'Inertia',
				'ProcessReactWorkspace',
			],
		];
	}

	/**
	 * Ensure ProcessWire treats ProcessWireConsole as an autoload module so its
	 * workspace definition is registered with ProcessReactWorkspace on boot.
	 */
	public function isAutoload(): bool
	{
		return true;
	}

	public function init(): void
	{
		IconManager::registerAdminIcons([
			'terminal',
			'layout-dashboard',
			'database',
			'stack-2',
			'plant',
			'clock',
			'server',
			'flask',
			'sparkles',
			'list-details',
		]);
		$this->registerWorkspace();
	}

	/**
	 * Defer the Console workspace and dashboard widget registration until requested.
	 */
	private function registerWorkspace(): void
	{
		ProcessReactWorkspace::deferModuleRegistration(
			$this,
			function (ProcessReactWorkspace $workspace): void {
				$workspace->registerWorkspace([
					'id' => 'console',
					'title' => $this->_('Console'),
					'description' => $this->_('Monitor system health, schema migrations, background queues, scheduled tasks, database backups, tests, AI skills, and activity logs.'),
					'icon' => 'terminal',
					'process' => 'ProcessWireConsole',
					'component' => 'WireConsole/Workspace',
					'category' => $this->_('System'),
					'order' => 60,
					'permission' => self::PERMISSION_VIEW,
					'scripts' => $this->getModuleScriptAssets(),
					'statsCallback' => function (): array {
						$runtime = $this->runtime();
						$maintenance = $runtime->getMaintenanceStatus();
						$isDown = !empty($maintenance['isDown']);
						$scheduledCount = count($runtime->getScheduledTasks());

						return [
							[
								'label' => $this->_('Status'),
								'value' => $isDown ? $this->_('Maintenance') : $this->_('Online'),
								'tone' => $isDown ? 'warning' : 'success',
							],
							[
								'label' => $this->_('Scheduled'),
								'value' => (string) $scheduledCount,
							],
						];
					},
				]);
			},
			function (ProcessReactWorkspace $workspace): void {
				$workspace->registerWidget([
					'id' => 'console-overview',
					'workspaceId' => 'console',
					'title' => $this->_('System Health & Operations'),
					'subtitle' => $this->_('Application status, scheduled tasks, queues, and maintenance'),
					'icon' => 'terminal',
					'size' => 'md',
					'order' => 60,
					'permission' => self::PERMISSION_VIEW,
					'dataCallback' => function () use ($workspace): array {
						$runtime = $this->runtime();
						$baseUrl = rtrim($workspace->workspaces()->resolveUrl('console'), '/') . '/';
						$maintenance = $runtime->getMaintenanceStatus();
						$isDown = !empty($maintenance['isDown']);
						$scheduledCount = count($runtime->getScheduledTasks());
						$migrations = $runtime->getMigrationsStatus();
						$pendingMigrations = (int) ($migrations['pending'] ?? 0);

						return [
							'status' => [
								'label' => $isDown ? $this->_('Maintenance Mode Active (503)') : $this->_('System Online & Healthy'),
								'tone' => $isDown ? 'warning' : 'success',
								'detail' => sprintf('PHP %s', PHP_VERSION),
							],
							'metrics' => [
								['label' => $this->_('Status'), 'value' => $isDown ? $this->_('503 Down') : $this->_('Online'), 'tone' => $isDown ? 'warning' : 'success'],
								['label' => $this->_('Tasks'), 'value' => (string) $scheduledCount, 'tone' => 'primary'],
								['label' => $this->_('Pending Migrations'), 'value' => (string) $pendingMigrations, 'tone' => $pendingMigrations > 0 ? 'warning' : 'muted'],
							],
							'items' => [
								[
									'id' => 'con-migrations',
									'title' => $this->_('Schema Migrations'),
									'subtitle' => $pendingMigrations > 0 ? sprintf($this->_('%d pending migration(s)'), $pendingMigrations) : $this->_('Database schema up to date'),
									'badge' => $pendingMigrations > 0 ? (string) $pendingMigrations : 'OK',
									'badgeVariant' => $pendingMigrations > 0 ? 'warning' : 'success',
									'icon' => 'database',
									'href' => $baseUrl . 'migrations/',
								],
								[
									'id' => 'con-scheduling',
									'title' => $this->_('Scheduled Tasks'),
									'subtitle' => $this->_('Automated cron jobs and background schedules'),
									'badge' => (string) $scheduledCount,
									'badgeVariant' => 'secondary',
									'icon' => 'clock',
									'href' => $baseUrl . 'scheduling/',
								],
								[
									'id' => 'con-logs',
									'title' => $this->_('Activity & System Logs'),
									'subtitle' => $this->_('Audit trail and runtime log channels'),
									'badge' => $this->_('Logs'),
									'badgeVariant' => 'outline',
									'icon' => 'list-details',
									'href' => $baseUrl . 'logs/',
								],
							],
							'actions' => [
								['label' => $this->_('Open Console'), 'href' => $baseUrl, 'icon' => 'terminal', 'variant' => 'primary'],
								['label' => $this->_('System Logs'), 'href' => $baseUrl . 'logs/', 'icon' => 'list-details', 'variant' => 'outline'],
							],
						];
					},
				]);
			}
		);
	}

	/**
	 * Return the module-specific compiled script bundle (`dist/ProcessWireConsole.js`) loaded after `dist/ReactInertia.js`.
	 *
	 * @return list<string>
	 */
	public function getModuleScriptAssets(): array
	{
		$config = $this->wire()->config;
		if (!$config) {
			return [];
		}

		$scriptPath = __DIR__ . '/dist/ProcessWireConsole.js';
		if (!is_file($scriptPath)) {
			return [];
		}

		$ver = (string) (@filemtime($scriptPath) ?: '2.1.1');
		$moduleUrl = rtrim((string) $config->urls->siteModules, '/') . '/' . basename(__DIR__) . '/';
		try {
			$iconAssets = ProcessReactWorkspace::instance($this)->resolveModuleAssetUrl('ReactIcons.js');
		} catch (\Throwable) {
			$iconAssets = [];
		}

		return array_values(array_unique(array_merge(
			$iconAssets,
			[$moduleUrl . 'dist/ProcessWireConsole.js?v=' . $ver],
		)));
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
	 * Dedicated action endpoint (`./action/`) for Inertia POST mutations and file inspections.
	 */
	public function ___executeAction(): string
	{
		$input = $this->wire()->input;
		$tab = trim(self::scalarString($input?->post('tab') ?? $input?->get('tab'), 'overview'));
		if ($tab === '') {
			$tab = 'overview';
		}

		return $this->renderSection($tab);
	}

	/**
	 * Render the Console Inertia workspace for `$section`, executing any posted workspace action first.
	 *
	 * @throws WirePermissionException
	 */
	protected function renderSection(string $section): string
	{
		$runtime = $this->runtime();
		if (!$runtime->userCanView(self::PERMISSION_VIEW)) {
			$runtime->audit('workspace.view', 'denied_rbac', ['section' => $section]);
			throw new WirePermissionException('Access denied.');
		}

		$input = $this->wire()->input;
		$rootUrl = $this->rootUrl();

		$requestPayload = $this->extractRequestPayload();
		$requestedTab = trim(self::scalarString($requestPayload['tab'] ?? $input?->get('tab'), $section));
		if ($requestedTab !== '') {
			$section = $requestedTab;
		}

		$feedback = null;
		$testResult = null;
		$mcpResult = null;
		$inspectedFile = null;

		$action = trim(self::scalarString($requestPayload['action'] ?? null));
		if ($action !== '') {
			if (!$this->verifyActionCsrf($requestPayload)) {
				$feedback = [
					'ok' => false,
					'action' => $action,
					'message' => $this->_('Invalid or missing CSRF token. Please refresh and try again.'),
					'details' => '',
					'timestamp' => date('H:i:s'),
				];
			} else {
				$actionResult = $runtime->executeWorkspaceAction($action, $requestPayload);
				$feedback = [
					'ok' => (bool) ($actionResult['ok'] ?? false),
					'action' => $action,
					'message' => self::scalarString($actionResult['message'] ?? null),
					'details' => self::scalarString($actionResult['details'] ?? $actionResult['output'] ?? null),
					'timestamp' => date('H:i:s'),
				];

				if (isset($actionResult['tab']) && is_string($actionResult['tab']) && $actionResult['tab'] !== '') {
					$section = $actionResult['tab'];
				}

				if ($action === 'runTests' || $action === 'runE2eTests') {
					$testResult = $actionResult;
				} elseif ($action === 'runMcpReadOnlyTool') {
					$mcpResult = $actionResult;
				} elseif ($action === 'inspectFile' && isset($actionResult['data']) && is_array($actionResult['data'])) {
					$inspectedFile = $actionResult['data'];
				}
			}
		}

		$inspectCategory = $action === '' ? trim(self::scalarString($input?->get('inspect_category'))) : '';
		$inspectId = $action === '' ? trim(self::scalarString($input?->get('inspect_file'))) : '';

		$options = [
			'actionUrl' => $rootUrl . 'action/',
			'downloadBackupUrl' => $rootUrl . 'download-backup/',
			'feedback' => $feedback,
			'testResult' => $testResult,
			'mcpResult' => $mcpResult,
			'inspectCategory' => $inspectCategory,
			'inspectId' => $inspectId,
			'logChannel' => trim(self::scalarString($input?->get('channel'))),
			'logSearch' => trim(self::scalarString($input?->get('search'))),
			'logLimit' => self::scalarInt($input?->get('limit'), 50),
		];

		$props = $runtime->buildWorkspacePayload($section, $options);
		$csrf = $this->wire()->session?->CSRF;
		if ($csrf) {
			$props['csrf'] = [
				'name' => (string) $csrf->getTokenName(),
				'value' => (string) $csrf->getTokenValue(),
			];
		}
		if ($inspectedFile !== null) {
			$props['inspectedFile'] = $inspectedFile;
		}

		return (string) $this->inertiaRender('WireConsole/Workspace', $props);
	}

	/**
	 * Verify ProcessWire CSRF token on workspace action submissions.
	 *
	 * @param array<string, mixed> $requestPayload
	 */
	protected function verifyActionCsrf(array $requestPayload): bool
	{
		try {
			$csrf = $this->wire()->session?->CSRF;
			if ($csrf === null) {
				return false;
			}

			$expectedName = (string) $csrf->getTokenName();
			$expectedValue = (string) $csrf->getTokenValue();
			if ($expectedValue === '') {
				return false;
			}

			if (array_key_exists('_token', $requestPayload) || ($expectedName !== '' && array_key_exists($expectedName, $requestPayload))) {
				$supplied = self::scalarString($requestPayload['_token'] ?? ($expectedName !== '' ? ($requestPayload[$expectedName] ?? null) : null));
				return $supplied !== '' && hash_equals($expectedValue, $supplied);
			}

			$headerToken = self::scalarString($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null);
			if ($headerToken !== '') {
				return hash_equals($expectedValue, $headerToken);
			}

			return (bool) $csrf->hasValidToken('', false);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Extract POST or JSON body payload from the current request.
	 *
	 * @return array<string, mixed>
	 */
	protected function extractRequestPayload(): array
	{
		$method = strtoupper(trim(self::scalarString($_SERVER['REQUEST_METHOD'] ?? 'POST', 'POST')));
		if ($method !== 'POST') {
			return [];
		}

		$payload = [];
		$input = $this->wire()->input;
		if ($input && $input->post->count() > 0) {
			$postArray = $input->post->getArray();
			if (count($postArray, COUNT_RECURSIVE) <= self::MAX_JSON_ELEMENTS) {
				foreach ($postArray as $k => $v) {
					$payload[(string) $k] = $v;
				}
			}
		}

		$contentType = self::scalarString($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null);
		if (stripos($contentType, 'application/json') !== false) {
			$rawBody = @file_get_contents('php://input', false, null, 0, self::MAX_JSON_BODY_BYTES + 1);
			if (is_string($rawBody) && $rawBody !== '' && strlen($rawBody) <= self::MAX_JSON_BODY_BYTES) {
				$decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH);
				if (is_array($decoded) && count($decoded, COUNT_RECURSIVE) <= self::MAX_JSON_ELEMENTS) {
					foreach ($decoded as $k => $v) {
						$payload[(string) $k] = $v;
					}
				}
			}
		}

		return $payload;
	}

	/**
	 * Safely convert a scalar request value to string without triggering Array-to-string warnings.
	 */
	private static function scalarString(mixed $value, string $default = ''): string
	{
		if (is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return $default;
	}

	/**
	 * Safely convert a numeric scalar request value to int.
	 */
	private static function scalarInt(mixed $value, int $default = 0): int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && is_numeric(trim($value))) {
			return (int) trim($value);
		}
		if (is_float($value)) {
			return (int) $value;
		}

		return $default;
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
				'icon' => self::toAdminFlyoutIcon($meta['icon']),
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
	 * Map workspace vector icon names to classic ProcessWire admin flyout navigation icon names.
	 */
	private static function toAdminFlyoutIcon(string $icon): string
	{
		return match ($icon) {
			'layout-dashboard' => 'dashboard',
			'stack-2' => 'refresh',
			'plant' => 'leaf',
			'clock' => 'clock-o',
			'server' => 'hdd-o',
			'sparkles' => 'magic',
			'list-details' => 'list-alt',
			default => $icon,
		};
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
		if ($runtime->isDemoMode()) {
			$runtime->audit('backup.download', 'denied_demo');
			throw new WirePermissionException($this->_('SQL backup downloads are disabled in demo mode.'));
		}

		$fileName = self::scalarString($this->wire()->input?->get('file'));
		$resolvedPath = $runtime->resolveBackupFilePath($fileName);
		if ($resolvedPath === null) {
			$runtime->audit('backup.download', 'invalid_file', ['file' => $fileName]);
			throw new Wire404Exception($this->_('Requested SQL backup file was not found.'));
		}

		$runtime->audit('backup.download', 'ok', ['file' => basename($resolvedPath)]);
		$this->wire()->files?->send($resolvedPath, [
			'exit' => true,
			'forceDownload' => true,
			'downloadFilename' => basename($resolvedPath),
			'limitPath' => $runtime->getBackupDirectory(),
		]);
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
	 * Return static UI translations for the Console React workspace.
	 *
	 * @return array<string, mixed>
	 */
	public function ___translations(): array
	{
		$prevEntityEncode = __(true, 'entityEncode');
		__(true, 'entityEncode', false);

		try {
			return $this->buildTranslationsArray();
		} finally {
			__(true, 'entityEncode', $prevEntityEncode);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildTranslationsArray(): array
	{
		return [
			'workspace' => [
				'title' => $this->_('Console'),
				'subtitle' => $this->_('System health, database maintenance, background tasks, and diagnostics.'),
				'maintenancePill' => $this->_('Maintenance Mode (503)'),
				'onlinePill' => $this->_('System Online'),
				'demoPill' => $this->_('Demo Mode'),
				'refreshTelemetry' => $this->_('Refresh Telemetry'),
				'confirmLabel' => $this->_('Confirm'),
				'cliEquivalent' => $this->_('CLI Equivalent'),
				'source' => $this->_('Source'),
				'preview' => $this->_('Preview'),
				'download' => $this->_('Download'),
				'run' => $this->_('Run'),
				'create' => $this->_('Create'),
				'delete' => $this->_('Delete'),
				'retry' => $this->_('Retry'),
				'purge' => $this->_('Purge'),
				'actionsHead' => $this->_('Actions'),
				'inspectHead' => $this->_('Inspect'),
				'statusHead' => $this->_('Status'),
				'idHead' => $this->_('ID'),
				'missing' => $this->_('Missing'),
				'available' => $this->_('Available'),
				'unavailable' => $this->_('Unavailable'),
			],
			'feedback' => [
				'completed' => $this->_('Action Completed ({action})'),
				'failed' => $this->_('Action Failed ({action})'),
			],
			'inspector' => [
				'title' => $this->_('Source Inspector'),
				'subtitle' => $this->_('{category} • {lines} lines • {size}'),
			],
			'overview' => [
				'schemaMigrations' => $this->_('Schema Migrations'),
				'pendingMigrationsCount' => $this->_('{count} pending migration(s)'),
				'allMigrationsApplied' => $this->_('All schema migrations applied'),
				'backgroundQueues' => $this->_('Background Queues'),
				'queuedCount' => $this->_('{count} queued'),
				'failedJobsAttention' => $this->_('{count} failed job(s) require attention'),
				'discoveredHandlersCount' => $this->_('{count} discovered handler(s)'),
				'databaseBackups' => $this->_('Database Backups'),
				'snapshotsCount' => $this->_('{count} snapshots'),
				'latestSnapshot' => $this->_('Latest: {file}'),
				'noSnapshotsYet' => $this->_('No SQL snapshots yet'),
				'boostAndSkills' => $this->_('Boost & AI Skills'),
				'skillsCount' => $this->_('{count} skills'),
				'boostSummaryHint' => $this->_('{agents} agent(s), {enabled}/{total} module(s)'),
				'maintenanceTitle' => $this->_('Maintenance Mode'),
				'maintenanceActiveBadge' => $this->_('Maintenance Active (503)'),
				'applicationOnlineBadge' => $this->_('Application Online'),
				'maintenanceDescription' => $this->_('Temporarily pause public site access with a friendly maintenance screen.'),
				'maintenanceAlertTitle' => $this->_('Application is currently in maintenance mode'),
				'maintenanceSinceSummary' => $this->_('Since: {since} • Status: HTTP {status}'),
				'maintenanceRetrySuffix' => $this->_('Retry-After: {seconds}s'),
				'unknownTime' => $this->_('Unknown'),
				'bringOnline' => $this->_('Bring Application Online'),
				'bringOnlineDesc' => $this->_('Remove site/down.json and restore normal HTTP traffic immediately.'),
				'bypassSecretLabel' => $this->_('Bypass Secret Token (optional)'),
				'bypassSecretPlaceholder' => $this->_('e.g. deploy-preview-token'),
				'retryAfterLabel' => $this->_('Retry-After Seconds'),
				'enableMaintenance' => $this->_('Enable Maintenance Mode'),
				'enableMaintenanceDesc' => $this->_('Create site/down.json and intercept public HTTP traffic with a 503 response.'),
				'cacheFlushTitle' => $this->_('System & WireCache Flush'),
				'cacheControlsBadge' => $this->_('Cache Controls'),
				'cacheFlushDescription' => $this->_('Clear template, module, and database caches when updates do not appear right away.'),
				'cacheTargetScope' => $this->_('Cache Target Scope'),
				'cacheAll' => $this->_('All Caches (Modules + FileCompiler + WireCache)'),
				'cacheCore' => $this->_('Core & FileCompiler Only (cache:clear)'),
				'cacheWire' => $this->_('WireCache Database Entries Only (cache:wire:clear)'),
				'cacheModules' => $this->_('Refresh Module Registry (module:refresh)'),
				'flushSelectedCaches' => $this->_('Flush Selected Caches'),
				'flushSelectedCachesDesc' => $this->_('Clear cache scope "{scope}" across the ProcessWire runtime.'),
				'packagesReadinessTitle' => $this->_('Composer Packages & Subsystem Readiness'),
				'packagesReadinessDesc' => $this->_('Installed CLI and AI tooling status in {path}'),
				'cliBinaryStatus' => $this->_('CLI binary: vendor/bin/wire ({status})'),
				'boostPackageDesc' => $this->_('AI Guidelines, Skills & 28-Tool JSON-RPC MCP Server'),
				'databaseSeedersCard' => $this->_('Database Seeders'),
				'fakerStatus' => $this->_('FakerPHP: {status}'),
				'scheduledTasksCard' => $this->_('Scheduled Tasks'),
				'dueNowCount' => $this->_('Due now: {count}'),
				'testSuitesCard' => $this->_('Automated Tests'),
				'testSuitesBreakdown' => $this->_('{pest} Pest • {e2e} E2E'),
				'runnersStatusSummary' => $this->_('Pest: {pest} • E2E: {e2e}'),
				'securityAuditCard' => $this->_('Security & Audit'),
				'rbacRateLimitLabel' => $this->_('RBAC + Rate Limit'),
				'runtimeVersionsTitle' => $this->_('Runtime Stack Versions'),
				'runtimeVersionsDescription' => $this->_('Active environment version matrix'),
				'phpRuntimeLabel' => $this->_('PHP Runtime'),
				'processwireCoreLabel' => $this->_('ProcessWire Core'),
				'databaseEngineLabel' => $this->_('Database Engine'),
				'inertiaRuntimeLabel' => $this->_('Inertia Runtime'),
				'workspaceRuntimeLabel' => $this->_('React Workspace Runtime'),
				'consolePackageLabel' => $this->_('Console Package'),
				'boostPackageLabel' => $this->_('Boost Package'),
			],
			'migrations' => [
				'unavailableTitle' => $this->_('Migrations Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view schema migrations.'),
				'trackingTable' => $this->_('Tracking Table'),
				'trackingTableHint' => $this->_('wire_migrations table'),
				'installed' => $this->_('Installed'),
				'notInstalled' => $this->_('Not Installed'),
				'appliedMigrations' => $this->_('Applied Migrations'),
				'totalDiscoveredHint' => $this->_('Total discovered: {count}'),
				'pendingMigrations' => $this->_('Pending Migrations'),
				'readyToMigrate' => $this->_('Ready to migrate'),
				'schemaUpToDate' => $this->_('Schema is up to date'),
				'lastBatchNumber' => $this->_('Last Batch Number'),
				'rollbackPreviewHint' => $this->_('{count} in rollback preview'),
				'runAndRollback' => $this->_('Run & Rollback'),
				'runAndRollbackDesc' => $this->_('Execute pending migrations or roll back recent batches.'),
				'tableMissingNotice' => $this->_('Tracking table wire_migrations is not installed.'),
				'installTable' => $this->_('Install Table'),
				'installTableConfirmTitle' => $this->_('Install Migrations Table'),
				'installTableConfirmDesc' => $this->_('Create the wire_migrations tracking table in the database.'),
				'migrateStepsLabel' => $this->_('Migrate Steps (0 = all)'),
				'rollbackStepsLabel' => $this->_('Rollback Steps'),
				'rollback' => $this->_('Rollback'),
				'rollbackConfirmTitle' => $this->_('Rollback Schema Migrations'),
				'rollbackConfirmDesc' => $this->_('Roll back {steps} migration step(s).'),
				'runPending' => $this->_('Run Pending'),
				'runPendingConfirmTitle' => $this->_('Run Pending Migrations'),
				'runPendingConfirmDesc' => $this->_('Execute pending schema migrations in site/migrations/.'),
				'resetRefreshTitle' => $this->_('Reset, Refresh & Fresh'),
				'resetRefreshDesc' => $this->_('Full migration cycle controls with optional SQL safety snapshot.'),
				'operationMode' => $this->_('Operation Mode'),
				'modeRefreshOption' => $this->_('Refresh (Rollback All + Re-run All)'),
				'modeResetOption' => $this->_('Reset (Rollback All Applied Migrations)'),
				'modeFreshOption' => $this->_('Fresh (Drop Tracking Table + Re-run All)'),
				'safetyBackupSwitch' => $this->_('Create SQL safety backup before running'),
				'executeModeButton' => $this->_('Execute {mode}'),
				'executeModeConfirmTitle' => $this->_('Execute migrate:{mode}'),
				'executeModeWithBackupDesc' => $this->_('Run migrate:{mode} across all schema migrations with a pre-backup snapshot.'),
				'executeModeWithoutBackupDesc' => $this->_('Run migrate:{mode} across all schema migrations without a pre-backup snapshot.'),
				'scaffoldMigration' => $this->_('Scaffold Migration'),
				'scaffoldMigrationDesc' => $this->_('Create a new timestamped migration in site/migrations/.'),
				'migrationIdentifier' => $this->_('Migration Identifier'),
				'migrationPlaceholder' => $this->_('e.g. create_product_fields'),
				'migrationStubTypeLabel' => $this->_('Migration Stub Type'),
				'stubBlankOption' => $this->_('Blank Migration (up / down)'),
				'stubCreateFieldOption' => $this->_('Create Field (create-field)'),
				'stubCreateTemplateOption' => $this->_('Create Template (create-template)'),
				'stubAttachFieldOption' => $this->_('Attach Field to Template (attach-field)'),
				'stubCreatePageOption' => $this->_('Create Page (create-page)'),
				'stubCreateRoleOption' => $this->_('Create Role (create-role)'),
				'stubInstallModuleOption' => $this->_('Install Module (install-module)'),
				'createMigration' => $this->_('Create Migration'),
				'createMigrationConfirmTitle' => $this->_('Scaffold New Migration'),
				'createMigrationConfirmDesc' => $this->_('Create a new migration file for "{name}" in site/migrations/.'),
				'discoveredTitle' => $this->_('Discovered Schema Migrations'),
				'discoveredDesc' => $this->_('Files located in site/migrations/'),
				'filesBadgeCount' => $this->_('{count} file(s)'),
				'nameHead' => $this->_('Migration Name'),
				'batchHead' => $this->_('Batch'),
				'appliedAtHead' => $this->_('Applied At'),
				'ranBadge' => $this->_('Ran'),
				'pendingBadge' => $this->_('Pending'),
				'missingBadge' => $this->_('Missing'),
				'emptyTitle' => $this->_('No Schema Migrations Found'),
				'emptyDescription' => $this->_('Scaffold your first migration above or run php vendor/bin/wire make:migration.'),
			],
			'queues' => [
				'unavailableTitle' => $this->_('Queues Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view background queues.'),
				'queueTables' => $this->_('Queue Tables'),
				'queueTablesHint' => $this->_('queue_jobs & failed_jobs'),
				'ready' => $this->_('Ready'),
				'pendingJobs' => $this->_('Pending Jobs'),
				'pendingJobsHint' => $this->_('Waiting or reserved in queue_jobs'),
				'failedJobs' => $this->_('Failed Jobs'),
				'failedJobsAttentionHint' => $this->_('Requires retry or inspection'),
				'zeroFailedJobsHint' => $this->_('Zero failed jobs'),
				'discoveredHandlers' => $this->_('Discovered Handlers'),
				'discoveredHandlersHint' => $this->_('Classes in site/queue/'),
				'tablesRequiredTitle' => $this->_('Queue Database Tables Required'),
				'tablesRequiredDesc' => $this->_('Install queue_jobs and failed_jobs before dispatching or processing jobs.'),
				'installQueueTables' => $this->_('Install Queue Tables'),
				'installQueueTablesDesc' => $this->_('Create the queue_jobs and failed_jobs database tables.'),
				'processBatchTitle' => $this->_('Process Queue Batch'),
				'processBatchDesc' => $this->_('Run a bounded synchronous worker batch (queue:work --once).'),
				'processBatchConfirmDesc' => $this->_('Process up to {max} job(s) on queue "{queue}".'),
				'queueNameLabel' => $this->_('Queue Name'),
				'maxJobsLabel' => $this->_('Max Jobs (1-50)'),
				'maxTriesLabel' => $this->_('Max Tries'),
				'workBatch' => $this->_('Work Batch'),
				'dispatchJobTitle' => $this->_('Dispatch Queue Job'),
				'dispatchJobDesc' => $this->_('Push a discovered queue handler onto queue_jobs.'),
				'dispatchJobConfirmDesc' => $this->_('Push {handler} onto queue "{queue}".'),
				'handlerClassLabel' => $this->_('Queue Handler Class'),
				'selectHandlerPlaceholder' => $this->_('Select queue handler...'),
				'queueShortLabel' => $this->_('Queue'),
				'delayLabel' => $this->_('Delay (sec)'),
				'jsonPayloadLabel' => $this->_('JSON Payload'),
				'dispatchJob' => $this->_('Dispatch Job'),
				'scaffoldCleanupTitle' => $this->_('Scaffold & Queue Cleanup'),
				'scaffoldCleanupDesc' => $this->_('Create queue handlers in site/queue/ or purge queues.'),
				'newQueueClassLabel' => $this->_('New Queue Class Name'),
				'newQueuePlaceholder' => $this->_('e.g. SendWelcomeEmail'),
				'scaffoldConfirmTitle' => $this->_('Scaffold Queue Class'),
				'scaffoldConfirmDesc' => $this->_('Create queue handler "{name}" in site/queue/.'),
				'purgePendingLabel' => $this->_('Purge Pending Queue'),
				'purgeQueuePlaceholder' => $this->_('all or queue name'),
				'purgeConfirmTitle' => $this->_('Purge Pending Jobs'),
				'purgeConfirmDesc' => $this->_('Delete pending jobs from queue "{queue}".'),
				'retryAllFailed' => $this->_('Retry All Failed'),
				'retryAllConfirmTitle' => $this->_('Retry All Failed Jobs'),
				'retryAllConfirmDesc' => $this->_('Push all failed jobs back onto their queues for retry.'),
				'clearFailed' => $this->_('Clear Failed'),
				'clearFailedConfirmTitle' => $this->_('Clear All Failed Jobs'),
				'clearFailedConfirmDesc' => $this->_('Permanently delete all records in the failed_jobs table.'),
				'failedJobsCountTitle' => $this->_('Failed Jobs ({count})'),
				'failedJobsDesc' => $this->_('Failed queue executions recorded in failed_jobs'),
				'failedCountBadge' => $this->_('{count} Failed'),
				'handlerHead' => $this->_('Handler'),
				'exceptionHead' => $this->_('Exception'),
				'failedAtHead' => $this->_('Failed At'),
				'retryOneConfirmTitle' => $this->_('Retry Failed Job #{id}'),
				'retryOneConfirmDesc' => $this->_('Re-queue failed job #{id} ({handler}).'),
				'deleteOneConfirmTitle' => $this->_('Delete Failed Job #{id}'),
				'deleteOneConfirmDesc' => $this->_('Delete failed job #{id} from failed_jobs.'),
				'pendingJobsTitle' => $this->_('Pending Queue Jobs ({count})'),
				'pendingJobsDesc' => $this->_('Active rows in queue_jobs'),
				'availableAtHead' => $this->_('Available At'),
				'jobStatusTries' => $this->_('{status} (tries: {attempts})'),
				'emptyQueueTitle' => $this->_('Queue is Empty'),
				'emptyQueueDescription' => $this->_('No pending or delayed jobs waiting in queue_jobs.'),
				'discoveredHandlersTitle' => $this->_('Discovered Queue Handlers ({count})'),
				'discoveredHandlersDesc' => $this->_('Classes discovered in site/queue/'),
				'handlerNameHead' => $this->_('Handler Name'),
				'noHandlersTitle' => $this->_('No Queue Handlers Discovered'),
				'noHandlersDescription' => $this->_('Scaffold a Queue class above or run php vendor/bin/wire make:queue.'),
			],
			'seeding' => [
				'unavailableTitle' => $this->_('Seeding Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view database seeders.'),
				'discoveredSeeders' => $this->_('Discovered Seeders'),
				'classesInSeedersHint' => $this->_('Classes in site/seeders/'),
				'fakerGenerator' => $this->_('FakerPHP Generator'),
				'fakerFactoryHint' => $this->_('Faker\\Factory availability'),
				'defaultEntrypoint' => $this->_('Default Entrypoint'),
				'allSeedersShort' => $this->_('All Seeders'),
				'allSeedersOption' => $this->_('All Seeders (or DatabaseSeeder)'),
				'executeTitle' => $this->_('Execute Database Seeders'),
				'executeDescription' => $this->_('Run a specific seeder class or execute all discovered seeders.'),
				'targetSeederLabel' => $this->_('Target Seeder'),
				'runSeeder' => $this->_('Run Seeder'),
				'runSeedersConfirmTitle' => $this->_('Run Database Seeders'),
				'runSeedersConfirmDesc' => $this->_('Execute seeder target "{target}" against the active database.'),
				'scaffoldTitle' => $this->_('Scaffold Seeder Class'),
				'scaffoldDesc' => $this->_('Create a new seeder class in site/seeders/.'),
				'seederClassNameLabel' => $this->_('Seeder Class Name'),
				'seederPlaceholder' => $this->_('e.g. ProductCatalogSeeder'),
				'createSeeder' => $this->_('Create Seeder'),
				'scaffoldConfirmDesc' => $this->_('Create seeder "{name}" in site/seeders/.'),
				'discoveredSeedersCount' => $this->_('Discovered Seeders ({count})'),
				'registeredInSeedersDesc' => $this->_('Seeder classes registered in site/seeders/'),
				'seederNameHead' => $this->_('Seeder Name'),
				'fqcnHead' => $this->_('FQCN'),
				'runOneConfirmTitle' => $this->_('Run {name}'),
				'runOneConfirmDesc' => $this->_('Execute seeder "{name}" now.'),
				'emptyTitle' => $this->_('No Database Seeders Found'),
				'emptyDescription' => $this->_('Scaffold a Seeder class above to populate test or initial data.'),
			],
			'scheduling' => [
				'unavailableTitle' => $this->_('Scheduling Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view scheduled tasks.'),
				'scheduledTasks' => $this->_('Scheduled Tasks'),
				'classesInScheduleHint' => $this->_('Classes in site/schedule/'),
				'dueRightNow' => $this->_('Due Right Now'),
				'dueReadyHint' => $this->_('Ready to execute on schedule:run'),
				'noneDueHint' => $this->_('No tasks due at this minute'),
				'cronRunner' => $this->_('Cron Runner'),
				'allDueOption' => $this->_('All Currently Due Tasks (schedule:run)'),
				'runTitle' => $this->_('Run Scheduled Tasks'),
				'runDescription' => $this->_('Trigger due cron tasks or run a specific task class immediately.'),
				'targetScopeLabel' => $this->_('Target Scope'),
				'cliCronEntryLabel' => $this->_('CLI Cron Entry'),
				'runScheduler' => $this->_('Run Scheduler'),
				'runConfirmDesc' => $this->_('Execute scheduled task target "{target}".'),
				'scaffoldTitle' => $this->_('Scaffold Scheduled Task'),
				'scaffoldDesc' => $this->_('Create a new scheduled task class in site/schedule/.'),
				'taskClassNameLabel' => $this->_('Task Class Name'),
				'taskPlaceholder' => $this->_('e.g. PruneExpiredSessionsTask'),
				'createTask' => $this->_('Create Task'),
				'scaffoldConfirmDesc' => $this->_('Create task class "{name}" in site/schedule/.'),
				'registeredTasksCount' => $this->_('Registered Cron Tasks ({count})'),
				'registeredTasksDesc' => $this->_('Task schedules discovered in site/schedule/'),
				'taskNameHead' => $this->_('Task Name'),
				'cronExpressionHead' => $this->_('Cron Expression'),
				'nextRunHead' => $this->_('Next Run'),
				'lastTriggeredHead' => $this->_('Last Triggered'),
				'dueNowBadge' => $this->_('Due Now'),
				'scheduledBadge' => $this->_('Scheduled'),
				'emptyTitle' => $this->_('No Scheduled Tasks Found'),
				'emptyDescription' => $this->_('Scaffold a Task class above to automate recurring jobs.'),
			],
			'backup' => [
				'unavailableTitle' => $this->_('Backups Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view SQL database backups.'),
				'sqlSnapshots' => $this->_('SQL Snapshots'),
				'storedInHint' => $this->_('Stored in site/assets/backups/database/'),
				'storageDirectory' => $this->_('Storage Directory'),
				'writable' => $this->_('Writable'),
				'readOnly' => $this->_('Read-Only'),
				'latestSnapshot' => $this->_('Latest Snapshot'),
				'noBackupsYetHint' => $this->_('No backups created yet'),
				'createTitle' => $this->_('Create Database Snapshot'),
				'createDesc' => $this->_('Generate a full SQL dump via WireDatabaseBackup.'),
				'descriptionLabel' => $this->_('Snapshot Description (optional)'),
				'descriptionPlaceholder' => $this->_('e.g. Pre-release schema snapshot'),
				'createSnapshot' => $this->_('Create Snapshot'),
				'createConfirmTitle' => $this->_('Create SQL Database Backup'),
				'createConfirmDesc' => $this->_('Dump all database tables to a new .sql snapshot in site/assets/backups/database/.'),
				'retentionTitle' => $this->_('Retention & Restore Policy'),
				'retentionDescription' => $this->_('Purge aged SQL dumps and configure pre-restore safety snapshots.'),
				'purgeDaysLabel' => $this->_('Purge Backups Older Than (Days)'),
				'purgeConfirmTitle' => $this->_('Purge Old SQL Backups'),
				'purgeConfirmDesc' => $this->_('Delete SQL backup files older than {days} day(s).'),
				'safetyRestoreSwitch' => $this->_('Automatically create a safety backup before restoring any SQL file'),
				'backupFilesCount' => $this->_('SQL Backup Files ({count})'),
				'availableSnapshotsDesc' => $this->_('Available snapshots in site/assets/backups/database/'),
				'filenameHead' => $this->_('Filename'),
				'sizeHead' => $this->_('Size'),
				'createdAtHead' => $this->_('Created At'),
				'restore' => $this->_('Restore'),
				'restoreConfirmTitle' => $this->_('Restore {file}'),
				'restoreWithBackupDesc' => $this->_('Overwrite the current database with "{file}" (a safety snapshot will be created first).'),
				'restoreWithoutBackupDesc' => $this->_('Overwrite the current database with "{file}" without a pre-restore safety snapshot.'),
				'emptyTitle' => $this->_('No SQL Backups Found'),
				'emptyDescription' => $this->_('Create your first database snapshot above.'),
			],
			'testing' => [
				'unavailableTitle' => $this->_('Testing Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view the automated test runners.'),
				'pestRunner' => $this->_('Pest Test Runner'),
				'pestFilesHint' => $this->_('{count} *Test.php file(s) • vendor/bin/pest'),
				'e2eRunner' => $this->_('Playwright E2E Runner'),
				'e2eFilesHint' => $this->_('{count} *.spec.ts file(s) • @playwright/test'),
				'runnerConfigs' => $this->_('Runner Configurations'),
				'configsSummaryValue' => $this->_('Pest: {phpunit} • E2E: {playwright}'),
				'detected' => $this->_('Detected'),
				'defaultLabel' => $this->_('Default'),
				'runnerConfigsHint' => $this->_('phpunit.xml • playwright.config.ts'),
				'lastRunStatus' => $this->_('Last Run Status'),
				'passed' => $this->_('Passed'),
				'exitCodeStatus' => $this->_('Exit {code}'),
				'notRun' => $this->_('Not Run'),
				'allTestSuites' => $this->_('All Pest Test Suites'),
				'executeTitle' => $this->_('Execute Pest Test Suite'),
				'executeDesc' => $this->_('Run allowlisted Pest PHP suites or filter by test name via isolated proc_open.'),
				'targetPathLabel' => $this->_('Target File / Suite Path'),
				'filterPatternLabel' => $this->_('Filter Pattern (--filter)'),
				'filterPlaceholder' => $this->_('e.g. InertiaTest'),
				'groupLabel' => $this->_('Group (--group)'),
				'groupPlaceholder' => $this->_('e.g. unit'),
				'stopOnFailureSwitch' => $this->_('Stop immediately on first failure (--stop-on-failure)'),
				'runTestSuite' => $this->_('Run Pest Suite'),
				'runConfirmDesc' => $this->_('Run vendor/bin/wire test {target} with a 60s timeout.'),
				'executeE2eTitle' => $this->_('Execute Playwright E2E Suite'),
				'executeE2eDesc' => $this->_('Run headless browser and WCAG accessibility specs via vendor/bin/wire test:e2e.'),
				'allE2eSpecs' => $this->_('All Discovered E2E Specs'),
				'e2eTargetPathLabel' => $this->_('Target E2E Spec Path'),
				'e2eModuleLabel' => $this->_('Module Filter (--module)'),
				'e2eModulePlaceholder' => $this->_('e.g. WireConsole'),
				'e2eGrepLabel' => $this->_('Title Filter (--grep)'),
				'e2eGrepPlaceholder' => $this->_('e.g. accessibility'),
				'initE2eButton' => $this->_('Init E2E (--init)'),
				'initE2eConfirmTitle' => $this->_('Initialize Playwright E2E Configuration'),
				'initE2eConfirmDesc' => $this->_('Non-destructively scaffold playwright.config.ts and tests/e2e/ helpers if missing.'),
				'runE2eSuite' => $this->_('Run E2E Suite'),
				'runE2eConfirmDesc' => $this->_('Run vendor/bin/wire test:e2e {target} with a 120s timeout.'),
				'scaffoldTitle' => $this->_('Scaffold Test or E2E Spec'),
				'scaffoldDesc' => $this->_('Create a Pest PHP test or Playwright E2E spec in site/tests/ or an installed module.'),
				'testKindLabel' => $this->_('Test Kind'),
				'kindFeatureOption' => $this->_('Pest Feature Test (Feature/)'),
				'kindUnitOption' => $this->_('Pest Unit Test (--unit)'),
				'kindE2eOption' => $this->_('Playwright E2E Spec (--e2e)'),
				'testClassNameLabel' => $this->_('Test / Spec Name'),
				'testPlaceholder' => $this->_('e.g. PageRoutingTest'),
				'e2eSpecPlaceholder' => $this->_('e.g. checkout-flow'),
				'testTargetModuleLabel' => $this->_('Target Module (optional --module)'),
				'createTest' => $this->_('Create Test'),
				'scaffoldConfirmTitle' => $this->_('Scaffold Test File'),
				'scaffoldConfirmDesc' => $this->_('Create {kind} test "{name}" via make:test.'),
				'outputPreviewTitle' => $this->_('Test Runner Output ({command})'),
				'noOutput' => $this->_('(no output)'),
				'discoveredFilesCount' => $this->_('Discovered Test & E2E Files ({count})'),
				'discoveredFilesDesc' => $this->_('All *Test.php and *.spec.ts files across project, site, and active modules'),
				'testNameHead' => $this->_('Test Name'),
				'runnerHead' => $this->_('Runner'),
				'pestBadge' => $this->_('Pest'),
				'playwrightBadge' => $this->_('Playwright'),
				'suiteHead' => $this->_('Suite'),
				'relativePathHead' => $this->_('Relative Path'),
				'runSingleConfirmTitle' => $this->_('Run {name}'),
				'runSingleConfirmDesc' => $this->_('Execute {path} via vendor/bin/wire test.'),
				'runSingleE2eConfirmDesc' => $this->_('Execute {path} via vendor/bin/wire test:e2e.'),
				'emptyTitle' => $this->_('No Test Files Discovered'),
				'emptyDescription' => $this->_('Scaffold your first Pest test or Playwright E2E spec above.'),
			],
			'boost' => [
				'unavailableTitle' => $this->_('Boost & AI Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view ProcessWire Boost & MCP settings.'),
				'boostEngine' => $this->_('Boost Engine'),
				'boostConfigActiveHint' => $this->_('.agents/boost.json active'),
				'boostDefaultConfigHint' => $this->_('Default configuration'),
				'installedSkills' => $this->_('Installed AI Skills'),
				'mcpServer' => $this->_('MCP JSON-RPC Server'),
				'toolsCountValue' => $this->_('{count} tools'),
				'toolsBreakdownHint' => $this->_('{readOnly} read-only • {mutation} mutation'),
				'discoverableModules' => $this->_('Discoverable Modules'),
				'discoverableModulesHint' => $this->_('Modules with AI guidelines or skills'),
				'syncConfigureTitle' => $this->_('Synchronize & Configure Boost'),
				'syncConfigureDesc' => $this->_('Regenerate AGENTS.md, skills, and schema map.'),
				'autoDiscoverSwitch' => $this->_('Auto-discover newly installed ProcessWire modules'),
				'syncNow' => $this->_('Sync Boost Now'),
				'syncConfirmTitle' => $this->_('Synchronize ProcessWire Boost'),
				'syncConfirmDesc' => $this->_('Regenerate AI guidelines, skills, and .agents/map.json.'),
				'enabledFeaturesLabel' => $this->_('Enabled Features (CSV)'),
				'targetAgentsLabel' => $this->_('Target Agents (CSV)'),
				'targetAgentsPlaceholder' => $this->_('claude_code,cursor,codex'),
				'enabledModulesLabel' => $this->_('Enabled Modules (CSV)'),
				'saveConfiguration' => $this->_('Save Configuration'),
				'saveConfirmTitle' => $this->_('Save Boost Configuration'),
				'saveConfirmDesc' => $this->_('Persist .agents/boost.json and reinstall selected features.'),
				'mcpInspectorTitle' => $this->_('MCP JSON-RPC Tool Inspector'),
				'mcpInspectorDescription' => $this->_('Execute allowlisted read-only MCP tools in-process.'),
				'readOnlyToolLabel' => $this->_('Read-Only MCP Tool'),
				'jsonRpcArgsLabel' => $this->_('JSON-RPC Arguments'),
				'mcpServerEntryLabel' => $this->_('MCP Server Entry'),
				'runMcpTool' => $this->_('Run MCP Tool'),
				'runMcpConfirmTitle' => $this->_('Execute MCP Tool {tool}'),
				'runMcpConfirmDesc' => $this->_('Call {tool} via JSON-RPC 2.0 in-process.'),
				'remoteSkillsTitle' => $this->_('Remote Skills & Module Scaffold'),
				'remoteSkillsDescription' => $this->_('Audit & install GitHub skills or scaffold a new ProcessWire module.'),
				'githubRepoLabel' => $this->_('GitHub Repository (owner/repo)'),
				'githubRepoPlaceholder' => $this->_('e.g. trk/processwire-skills'),
				'overwriteSkillsSwitch' => $this->_('Overwrite existing skill directories'),
				'installSkills' => $this->_('Install Skills'),
				'installSkillsConfirmTitle' => $this->_('Audit & Install Remote Skills'),
				'installSkillsConfirmDesc' => $this->_('Fetch and security-audit skills from "{repo}".'),
				'scaffoldModuleLabel' => $this->_('Scaffold ProcessWire Module'),
				'moduleStubTypeLabel' => $this->_('Module Stub Type'),
				'moduleTypeStandardOption' => $this->_('Standard Module (module)'),
				'moduleTypeProcessOption' => $this->_('Admin Process Module (process)'),
				'moduleTypeFieldtypeOption' => $this->_('Database Fieldtype (fieldtype)'),
				'moduleTypeInputfieldOption' => $this->_('Admin Inputfield (inputfield)'),
				'scaffoldModulePlaceholder' => $this->_('e.g. ProcessCustomDashboard'),
				'scaffoldModuleConfirmDesc' => $this->_('Create {type} module at site/modules/{name}/{name}.module.php.'),
				'mcpResponseTitle' => $this->_('MCP JSON-RPC Response ({tool})'),
				'installedSkillsCount' => $this->_('Installed Skills ({count})'),
				'installedSkillsDesc' => $this->_('Select any skill to inspect its SKILL.md playbook'),
				'mcpCatalogCount' => $this->_('MCP Tool Catalog ({count})'),
				'mcpProtocolSummary' => $this->_('Protocol {protocol} • {server} v{version}'),
				'mcpExecuteGuardBadge' => $this->_('Execute: {state}'),
				'mcpRestoreGuardBadge' => $this->_('Restore: {state}'),
				'guardEnabled' => $this->_('Enabled'),
				'guardLocked' => $this->_('Locked'),
				'toolNameHead' => $this->_('Tool Name'),
				'modeHead' => $this->_('Mode'),
				'descriptionHead' => $this->_('Description'),
				'readOnlyBadge' => $this->_('Read-Only'),
				'mutationBadge' => $this->_('Mutation'),
			],
			'logs' => [
				'unavailableTitle' => $this->_('Logs & Audit Section Unavailable'),
				'unavailableDescription' => $this->_('You do not have permission to view Console audit trails or ProcessWire logs.'),
				'title' => $this->_('Log Channels & Audit Trail'),
				'description' => $this->_('Inspect structured wire-console-audit entries and ProcessWire runtime logs.'),
				'clearChannel' => $this->_('Clear Channel'),
				'clearChannelConfirmTitle' => $this->_('Clear Log Channel "{channel}"'),
				'clearChannelConfirmDesc' => $this->_('Truncate all entries in site/assets/logs/{channel}.txt.'),
				'clearAllLogs' => $this->_('Clear All Logs'),
				'clearAllConfirmTitle' => $this->_('Clear All Log Channels'),
				'clearAllConfirmDesc' => $this->_('Truncate all .txt log files in site/assets/logs/.'),
				'channelLabel' => $this->_('Channel'),
				'searchLabel' => $this->_('Substring Filter'),
				'searchPlaceholder' => $this->_('Search by user, IP, action, or message...'),
				'limitLabel' => $this->_('Limit'),
				'rowsOption' => $this->_('{count} rows'),
				'filter' => $this->_('Filter'),
				'channelEntriesTitle' => $this->_('Channel Entries: {channel}'),
				'entriesCount' => $this->_('{count} entry(s)'),
				'emptyTitle' => $this->_('No Log Entries Found'),
				'emptyDescription' => $this->_('No matching log entries in channel "{channel}".'),
				'timestampHead' => $this->_('Timestamp'),
				'userHead' => $this->_('User'),
				'messageHead' => $this->_('Event / Message'),
				'ipPrefix' => $this->_('IP: {ip}'),
			],
		];
	}

	/**
	 * Ensure all Console permissions exist idempotently on install.
	 */
	public function ___install(): void
	{
		parent::___install();

		$permissions = $this->wire()?->permissions;
		$sanitizer = $this->wire()?->sanitizer;
		if ($permissions !== null) {
			$info = self::getModuleInfo();
			$declared = is_array($info['permissions'] ?? null) ? $info['permissions'] : [];
			foreach ($declared as $permName => $permTitle) {
				$rawName = trim((string) $permName);
				$cleanName = $sanitizer ? $sanitizer->pageNameUTF8($rawName) : $rawName;
				if ($cleanName === '' || ctype_digit($cleanName)) {
					continue;
				}

				$existing = $permissions->get($cleanName);
				if (!$existing || !$existing->id || (string) $existing->name !== $cleanName) {
					try {
						$perm = $permissions->add($cleanName);
						$perm->title = (string) $permTitle;
						$permissions->save($perm);
					} catch (\Throwable $e) {
						$this->wire()?->log?->error(sprintf('ProcessWireConsole install permission "%s" failed: %s', $cleanName, $e->getMessage()));
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
		$sanitizer = $this->wire()?->sanitizer;
		if ($permissions !== null) {
			$info = self::getModuleInfo();
			$declared = is_array($info['permissions'] ?? null) ? $info['permissions'] : [];
			foreach (array_keys($declared) as $permName) {
				$rawName = trim((string) $permName);
				$cleanName = $sanitizer ? $sanitizer->pageNameUTF8($rawName) : $rawName;
				if ($cleanName === '' || ctype_digit($cleanName)) {
					continue;
				}

				$safePermName = $sanitizer ? $sanitizer->selectorValue($cleanName) : $cleanName;
				foreach ($permissions->find("name={$safePermName}, include=all") as $perm) {
					if ($perm && $perm->id && (string) $perm->name === $cleanName) {
						try {
							$permissions->delete($perm);
						} catch (\Throwable $e) {
							$this->wire()?->log?->error(sprintf('ProcessWireConsole uninstall permission "%s" failed: %s', $cleanName, $e->getMessage()));
						}
					}
				}
			}
			$this->wire()?->pages?->uncacheAll();
		}

		parent::___uninstall();
	}
}
