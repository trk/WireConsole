<?php

declare(strict_types=1);

namespace ProcessWire;

require_once __DIR__ . '/WireConsoleDependencyChecker.php';
require_once __DIR__ . '/ConsolePackageAdapter.php';

final class ProcessWireConsole extends Process implements Module
{
	private const PACKAGE_CONSOLE = 'trk/processwire-console';
	private const PACKAGE_BOOST = 'trk/processwire-boost';
	private const REPO_MODULE = 'https://github.com/trk/WireConsole';
	private const REPO_CONSOLE = 'https://github.com/trk/processwire-console';
	private const REPO_BOOST = 'https://github.com/trk/processwire-boost';

	private ?ConsolePackageAdapter $consoleAdapter = null;

	// Permissions
	private const PERM_ACCESS = 'wire-console';
	private const PERM_MIGRATIONS = 'wire-console-migrations';
	private const PERM_QUEUES = 'wire-console-queues';
	private const PERM_SEEDING = 'wire-console-seeding';
	private const PERM_SCHEDULING = 'wire-console-scheduling';
	private const PERM_BACKUP = 'wire-console-backup';
	private const PERM_TESTING = 'wire-console-testing';

	public static function getModuleInfo(): array
	{
		return [
			'title' => 'ProcessWire Console',
			'version' => 1,
			'summary' => 'Admin UI to operate the ProcessWire Console package.',
			'href' => self::REPO_MODULE,
			'author' => 'Iskender TOTOGLU @trk',
			'icon' => 'terminal',
			'requires' => [
				'ProcessWire>=3.0.210',
				'PHP>=8.2.0',
			],
			// Access control
			'permissions' => [
				self::PERM_ACCESS => 'Access ProcessWire Console (admin UI)',
				self::PERM_MIGRATIONS => 'Use migrations (view)',
				self::PERM_QUEUES => 'Use queues (view)',
				self::PERM_SEEDING => 'Use database seeding (view)',
				self::PERM_SCHEDULING => 'Use task scheduling (view)',
				self::PERM_BACKUP => 'Use database backup/restore (view)',
				self::PERM_TESTING => 'Use testing (view)',
			],
			'permission' => self::PERM_ACCESS,
			// Optional: create an admin page (if supported by PW version)
			'page' => [
				'name' => 'wire-console',
				'parent' => 'setup',
				'title' => 'ProcessWire Console',
			],
		];
	}

	public function ___execute(): string
	{
		$config = $this->wire()->config;

		$hasConsole = WireConsoleDependencyChecker::isPackageInstalled($config, self::PACKAGE_CONSOLE);
		$hasBoost = WireConsoleDependencyChecker::isPackageInstalled($config, self::PACKAGE_BOOST);

		if (!$hasConsole) {
			$configureUrl = $this->config->urls->admin . 'module/edit?name=WireConsole';

			return sprintf(
				'
				<div class="pw-wrap WireConsoleAdmin">
					<div class="uk-card uk-card-default uk-card-body">
						<div class="uk-alert uk-alert-warning" uk-alert>
							<p>%s</p>
						</div>
						<p class="uk-text-small uk-text-muted">%s</p>
						<pre><code>%s</code></pre>
						<ul class="uk-list uk-list-divider">
							<li><a class="uk-link-text" href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>
							<li><a class="uk-link-text" href="%s">%s</a></li>
						</ul>
					</div>
				</div>
				',
				$this->_('The Console package is not installed. You must install it before using this page.'),
				sprintf($this->_('From your project root (the folder that contains composer.json) run: %s'), htmlspecialchars(self::PACKAGE_CONSOLE, ENT_QUOTES, 'UTF-8')),
				htmlspecialchars('composer require ' . self::PACKAGE_CONSOLE, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
				$this->_('Console repository'),
				htmlspecialchars($configureUrl, ENT_QUOTES, 'UTF-8'),
				$this->_('Open WireConsole configuration (status & guidance)')
			);
		}

		return $this->renderDashboard($hasConsole, $hasBoost);
	}

	private function console(): ConsolePackageAdapter
	{
		if ($this->consoleAdapter === null) {
			$this->consoleAdapter = new ConsolePackageAdapter($this->wire());
		}
		return $this->consoleAdapter;
	}

	private function renderConsoleSupportDetails(string $feature): string
	{
		$support = $this->console()->getSupportMatrix();
		$missing = $support['features'][$feature]['missing'] ?? [];

		if (empty($missing)) {
			return '';
		}

		$items = '';
		foreach ($missing as $fqcn) {
			$items .= '<li><code>' . htmlspecialchars((string) $fqcn, ENT_QUOTES, 'UTF-8') . '</code></li>';
		}

		return sprintf(
			'<div class="uk-alert uk-alert-warning" uk-alert>
				<p>%s</p>
				<ul class="uk-list uk-list-bullet uk-text-small uk-margin-small-top">%s</ul>
			</div>',
			htmlspecialchars($this->_('Console package PHP API classes are missing (or autoload is unavailable):'), ENT_QUOTES, 'UTF-8'),
			$items
		);
	}

	private function renderDashboard(bool $hasConsole, bool $hasBoost): string
	{
		$rootPath = WireConsoleDependencyChecker::getProjectRootPath($this->wire()->config);
		$hasPest = is_file($rootPath . '/vendor/bin/pest');
		$hasPhpunit = is_file($rootPath . '/vendor/bin/phpunit');

		$boostStatus = $hasBoost
			? $this->_('Installed (optional)')
			: $this->_('Not installed (optional)');

		$testStatus = ($hasPest || $hasPhpunit)
			? $this->_('Test tools found (optional)')
			: $this->_('No test tool found (optional)');

		$tabsAll = [
			[
				'id' => 'overview',
				'title' => $this->_('Overview'),
				'icon' => 'info',
				'permission' => self::PERM_ACCESS,
			],
			[
				'id' => 'migrations',
				'title' => $this->_('Migrations'),
				'icon' => 'database',
				'permission' => self::PERM_MIGRATIONS,
			],
			[
				'id' => 'queues',
				'title' => $this->_('Queues'),
				'icon' => 'bolt',
				'permission' => self::PERM_QUEUES,
			],
			[
				'id' => 'seeding',
				'title' => $this->_('Database Seeding'),
				'icon' => 'plus',
				'permission' => self::PERM_SEEDING,
			],
			[
				'id' => 'scheduling',
				'title' => $this->_('Task Scheduling'),
				'icon' => 'clock',
				'permission' => self::PERM_SCHEDULING,
			],
			[
				'id' => 'backup',
				'title' => $this->_('Database & Backup'),
				'icon' => 'download',
				'permission' => self::PERM_BACKUP,
			],
			[
				'id' => 'testing',
				'title' => $this->_('Testing'),
				'icon' => 'check',
				'permission' => self::PERM_TESTING,
			],
		];

		// Only show tabs user is allowed to view.
		$tabs = [];
		foreach ($tabsAll as $tab) {
			$perm = (string) ($tab['permission'] ?? self::PERM_ACCESS);
			$id = (string) ($tab['id'] ?? '');
			if ($id === 'overview' || $this->userCanViewPermission($perm)) {
				$tabs[] = $tab;
			}
		}

		// Persist active tab after POST/redirect by reading ?tab=
		$activeTabId = $this->wire()->sanitizer->name((string) $this->input->get->text('tab'));
		$activeIndex = 0;
		foreach ($tabs as $i => $tab) {
			if ($activeTabId !== '' && $tab['id'] === $activeTabId) {
				$activeIndex = (int) $i;
				break;
			}
		}

		$tabLinks = '';
		foreach ($tabs as $tab) {
			$tabLinks .= sprintf(
				'<li><a href="#%s"><span uk-icon="icon:%s; ratio:0.9" class="uk-margin-small-right"></span>%s</a></li>',
				htmlspecialchars((string) $tab['id'], ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string) $tab['icon'], ENT_QUOTES, 'UTF-8'),
				// Title strings are static module strings; do not double-encode ampersands.
				(string) $tab['title']
			);
		}

		// Keep switcher items aligned with visible tabs.
		$cardsByTab = [
			'overview' => fn() => $this->renderOverviewCard($boostStatus, $testStatus),
			'migrations' => fn() => $this->renderMigrationsCard(),
			'queues' => fn() => $this->renderQueuesCard(),
			'seeding' => fn() => $this->renderSeedingCard(),
			'scheduling' => fn() => $this->renderSchedulingCard(),
			'backup' => fn() => $this->renderBackupCard(),
			'testing' => fn() => $this->renderTestingCard(),
		];

		$cards = [];
		foreach ($tabs as $tab) {
			$id = (string) $tab['id'];
			if (isset($cardsByTab[$id])) {
				$cards[] = $cardsByTab[$id]();
			}
		}

		$switcherItems = '';
		foreach ($cards as $cardHtml) {
			$switcherItems .= '<li>' . $cardHtml . '</li>';
		}

		$tabAttr = sprintf(
			'connect: #wireconsole-switcher; animation: uk-animation-fade; active: %d',
			$activeIndex
		);

		return sprintf(
			'
			<div class="pw-wrap WireConsoleAdmin">
				<div class="uk-card uk-card-default uk-card-body uk-margin-bottom">
					<p class="uk-text-lead uk-margin-remove">%s</p>
					<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
				</div>

				<ul class="uk-tab" uk-tab="%s">%s</ul>
				<ul id="wireconsole-switcher" class="uk-switcher uk-margin">%s</ul>
			</div>
			<script>
				(function() {
					var tabEl = document.querySelector(".WireConsoleAdmin > .uk-tab");
					if(!tabEl) return;
					if(typeof UIkit === "undefined" || !UIkit.util || !UIkit.util.on) return;

					function setTabParam(tabId) {
						try {
							var url = new URL(window.location.href);
							if(tabId) url.searchParams.set("tab", tabId);
							else url.searchParams.delete("tab");
							window.history.replaceState({}, "", url.toString());
						} catch(e) {}
					}

					UIkit.util.on(tabEl, "shown", function(e) {
						// e.target is <li> element for the active tab
						var a = e && e.target ? e.target.querySelector("a") : null;
						if(!a) return;
						var href = a.getAttribute("href") || "";
						if(href.indexOf("#") !== 0) return;
						var tabId = href.substring(1);
						setTabParam(tabId);
					});
				})();
			</script>
			<style>
				.WireConsoleAdmin .uk-card { background: var(--pw-blocks-background); }
				.WireConsoleAdmin .uk-card-default { border: 1px solid var(--pw-border-color); }
				.WireConsoleAdmin pre { background: var(--pw-code-background); color: var(--pw-code-color); border: 1px solid var(--pw-border-color); }
				.WireConsoleAdmin code { color: var(--pw-code-color); }
			</style>
			',
			$this->_('ProcessWire Console'),
			sprintf(
				$this->_('This page is a bridge UI. All actions are performed by the %s package; if it is not installed, no action can be executed.'),
				htmlspecialchars(self::PACKAGE_CONSOLE, ENT_QUOTES, 'UTF-8')
			),
			htmlspecialchars($tabAttr, ENT_QUOTES, 'UTF-8'),
			$tabLinks,
			$switcherItems
		);
	}

	/**
	 * Flash UI messages inside the active card (in addition to global notices).
	 *
	 * @param string $tabId
	 */
	private function renderPanelMessages(string $tabId): string
	{
		$session = $this->wire()->session;
		/** @var array<string, array<int, array{type: string, text: string}>> $all */
		$all = $session->get('wireconsole_panel_messages') ?: [];

		$list = $all[$tabId] ?? [];
		if (!$list) {
			return '';
		}

		unset($all[$tabId]);
		$session->set('wireconsole_panel_messages', $all);

		$html = '';
		foreach ($list as $item) {
			$type = $item['type'] ?? 'primary';
			$text = $item['text'] ?? '';
			$class = match ($type) {
				'success' => 'uk-alert-success',
				'warning' => 'uk-alert-warning',
				'danger' => 'uk-alert-danger',
				default => 'uk-alert-primary',
			};
			$html .= '<div class="uk-alert ' . $class . '" uk-alert><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></div>';
		}

		return $html;
	}

	private function addPanelMessage(string $tabId, string $type, string $text): void
	{
		$session = $this->wire()->session;
		/** @var array<string, array<int, array{type: string, text: string}>> $all */
		$all = $session->get('wireconsole_panel_messages') ?: [];
		$all[$tabId] ??= [];
		$all[$tabId][] = ['type' => $type, 'text' => $text];
		$session->set('wireconsole_panel_messages', $all);
	}

	/**
	 * Audit log for admin actions.
	 *
	 * Writes to /site/assets/logs/wire-console.txt
	 */
	private function audit(string $event, string $status, array $context = []): void
	{
		try {
			$log = $this->wire()->log;
			$user = $this->wire()->user;
			$ip = $this->wire()->session ? (string) $this->wire()->session->getIP() : '';

			$payload = [
				'event' => $event,
				'status' => $status,
				'ip' => $ip,
				'user' => $user ? $user->name : null,
				'context' => $context,
			];

			$log->save('wire-console', $payload, [
				'showUser' => true,
				'showURL' => true,
				'allowNewlines' => false,
			]);
		} catch (\Throwable) {
			// Never block admin UI due to logging errors
		}
	}

	private function isDebugMode(): bool
	{
		return (bool) ($this->wire()->config->debug ?? false);
	}

	private function safeExceptionMessage(\Throwable $e): string
	{
		if ($this->isDebugMode()) {
			return $e->getMessage();
		}
		return $this->_('An unexpected error occurred. Please check the wire-console log.');
	}

	private function reportException(string $tabId, string $event, \Throwable $e, array $context = []): void
	{
		$messageForUser = $this->safeExceptionMessage($e);
		$this->error($messageForUser);
		$this->addPanelMessage($tabId, 'danger', $messageForUser);
		$this->audit($event, 'exception', array_merge($context, [
			'message' => $e->getMessage(),
		]));
	}

	private function userCanViewPermission(string $permission): bool
	{
		$user = $this->wire()->user;
		if (!$user) {
			return false;
		}
		if (method_exists($user, 'isSuperuser') && $user->isSuperuser()) {
			return true;
		}
		return $user->hasPermission($permission);
	}

	private function renderPermissionRequiredCard(string $title, string $permission): string
	{
		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<div class="uk-alert uk-alert-warning" uk-alert>
					<p>%s</p>
				</div>
				<p class="uk-text-small uk-text-muted">%s</p>
			</div>
			',
			htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
			$this->_('Permission required to view this section.'),
			htmlspecialchars(sprintf($this->_('Required permission: %s'), $permission), ENT_QUOTES, 'UTF-8')
		);
	}

	private function renderMigrationsCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_MIGRATIONS)) {
			return $this->renderPermissionRequiredCard($this->_('Migrations'), self::PERM_MIGRATIONS);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleMigrationsPost();
		}

		$panelMessages = $this->renderPanelMessages('migrations');

		$autoloadOk = $this->requireProjectAutoload();
		$engine = $this->getMigrationEngine();

		if (!$autoloadOk || !$engine) {
			$help = $this->_('The PHP API for migrations could not be loaded. This section requires the Console package autoload to be available (vendor/autoload.php) and migration classes to exist.');
			$supportHtml = $this->renderConsoleSupportDetails('migrations');

			return sprintf(
				'
				<div class="uk-card uk-card-default uk-card-body">
					<h3 class="uk-h3">%s</h3>
					<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
					<p class="uk-text-small uk-text-muted">%s</p>
					%s
					<p class="uk-margin-top">
						<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
					</p>
					<hr>
					<p class="uk-text-small uk-text-muted">%s</p>
					<pre><code>%s</code></pre>
				</div>
				',
				$this->_('Migrations'),
				$this->_('Migrations UI is not available via PHP API in the current environment.'),
				htmlspecialchars($help, ENT_QUOTES, 'UTF-8'),
				$supportHtml,
				htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
				$this->_('Documentation / Repo'),
				$this->_('CLI fallback (manual):'),
				htmlspecialchars("php vendor/bin/wire migrate:status\nphp vendor/bin/wire migrate --dry-run\nphp vendor/bin/wire migrate --force", ENT_QUOTES, 'UTF-8')
			);
		}

		/** @var object{migrator: \Totoglu\Console\Migration\Migrator, repository: \Totoglu\Console\Migration\MigrationRepository} $engine */
		$migrator = $engine['migrator'];
		$repository = $engine['repository'];

		// Collect status
		$status = [];
		$tableExists = false;
		$error = null;
		try {
			$tableExists = $repository->tableExists();
			if ($tableExists) {
				// Full status (reads applied batches)
				$status = $migrator->getStatus();
			} else {
				// Table is not installed yet: don't auto-create it here.
				// We still want to show discovered migration files as pending.
				$files = $migrator->discoverFiles();
				foreach ($files as $file) {
					$status[] = [
						'name' => $file,
						'status' => 'pending',
						'batch' => null,
					];
				}
			}
		} catch (\Throwable $e) {
			$error = $this->safeExceptionMessage($e);
			$this->audit('migrations.status', 'exception', ['message' => $e->getMessage()]);
		}

		// Render status table
		$rows = '';
		$appliedCount = 0;
		$pendingCount = 0;
		foreach ($status as $row) {
			$isApplied = ($row['status'] ?? '') === 'applied';
			$appliedCount += $isApplied ? 1 : 0;
			$pendingCount += $isApplied ? 0 : 1;

			$labelClass = $isApplied ? 'uk-label-success' : 'uk-label-warning';
			$labelText = $isApplied ? $this->_('Applied') : $this->_('Pending');
			$batchText = $row['batch'] === null ? '-' : (string) $row['batch'];

			$rows .= sprintf(
				'<tr>
					<td><code>%s</code></td>
					<td><span class="uk-label %s">%s</span></td>
					<td class="uk-text-nowrap">%s</td>
				</tr>',
				htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8'),
				$labelClass,
				htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($batchText, ENT_QUOTES, 'UTF-8')
			);
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="3" class="uk-text-muted">' . htmlspecialchars($this->_('No migrations found.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		}

		$user = $this->wire()->user;
		$canRun = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;
		$canUseActions = $canRun && $tableExists;
		$disabled = $canUseActions ? '' : 'disabled';

		$permissionNote = $canRun
			? ($tableExists
				? $this->_('You are superuser. You can run migration actions from this UI.')
				: $this->_('You are superuser. Install the migrations table first to enable actions.'))
			: $this->_('Only superusers can run migration actions from this UI.');

		$errorAlert = $error
			? '<div class="uk-alert uk-alert-danger" uk-alert><p>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '';

		$tableAlert = $tableExists
			? '<div class="uk-alert uk-alert-primary" uk-alert><p>' . htmlspecialchars($this->_('Migrations table detected.'), ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '<div class="uk-alert uk-alert-warning" uk-alert><p>' . htmlspecialchars($this->_('Migrations table is not installed. Install it to enable migrations tracking and actions.'), ENT_QUOTES, 'UTF-8') . '</p></div>';

		// Actions form (superuser only)
		$csrf = $this->wire()->session->CSRF->renderInput();
		$tabUrl = $this->pageUrlWithTab('migrations');

		$installForm = '';
		if (!$tableExists && $canRun) {
			$installForm = sprintf(
				'
				<form method="post" action="%s" class="uk-margin-top">
					%s
					<input type="hidden" name="wc_action" value="migrate_install">
					<button class="uk-button uk-button-primary" type="submit">%s</button>
					<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
				</form>
				',
				htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
				$csrf,
				$this->_('Install migrations table'),
				$this->_('Creates the wire_migrations table used to track applied migrations and batches.')
			);
		}

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				%s
				%s
				%s

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m uk-margin-top" uk-grid>
					<div>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="migrate_run">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="number" min="0" name="steps" value="0" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="RUN" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-primary" type="submit" %s>%s</button>
						</form>
					</div>
					<div>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="migrate_rollback">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="number" min="0" name="steps" value="0" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="ROLLBACK" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-danger" type="submit" %s>%s</button>
						</form>
					</div>
				</div>

				<hr class="uk-margin">

				<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
					<div class="uk-text-small uk-text-muted">%s</div>
					<div class="uk-text-small uk-text-muted">%s: <span class="uk-badge">%d</span> · %s: <span class="uk-badge">%d</span></div>
				</div>

				<div class="uk-overflow-auto uk-margin-top">
					<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
						<thead>
							<tr>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</div>
			',
			$this->_('Migrations'),
			$this->_('This UI uses the Console package migration engine (PHP API).'),
			$panelMessages,
			$tableAlert,
			$errorAlert,
			$installForm,
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Steps (0 = all pending)'),
			$disabled,
			$this->_('Run all pending migrations, or limit by steps.'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type RUN to confirm.'),
			$disabled,
			$this->_('Run pending'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Steps (0 = last batch)'),
			$disabled,
			$this->_('Rollback last batch, or rollback last N migrations by steps.'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type ROLLBACK to confirm.'),
			$disabled,
			$this->_('Rollback'),
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$this->_('Applied'),
			$appliedCount,
			$this->_('Pending'),
			$pendingCount,
			$this->_('Migration'),
			$this->_('Status'),
			$this->_('Batch'),
			$rows
		);
	}

	private function renderQueuesCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_QUEUES)) {
			return $this->renderPermissionRequiredCard($this->_('Queues'), self::PERM_QUEUES);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleQueuesPost();
		}

		$panelMessages = $this->renderPanelMessages('queues');

		$autoloadOk = $this->requireProjectAutoload();
		$manager = $this->getQueueManager();

		if (!$autoloadOk || !$manager) {
			$help = $this->_('The PHP API for queues could not be loaded. This section requires the Console package autoload to be available (vendor/autoload.php) and queue classes to exist.');
			$supportHtml = $this->renderConsoleSupportDetails('queues');

			return sprintf(
				'
				<div class="uk-card uk-card-default uk-card-body">
					<h3 class="uk-h3">%s</h3>
					<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
					<p class="uk-text-small uk-text-muted">%s</p>
					%s
					<p class="uk-margin-top">
						<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
					</p>
					<hr>
					<p class="uk-text-small uk-text-muted">%s</p>
					<pre><code>%s</code></pre>
				</div>
				',
				$this->_('Queues'),
				$this->_('Queues UI is not available via PHP API in the current environment.'),
				htmlspecialchars($help, ENT_QUOTES, 'UTF-8'),
				$supportHtml,
				htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
				$this->_('Documentation / Repo'),
				$this->_('CLI fallback (manual):'),
				htmlspecialchars("php vendor/bin/wire queue:table\nphp vendor/bin/wire queue:failed\nphp vendor/bin/wire queue:retry all\nphp vendor/bin/wire queue:work --queue=default --sleep=3 --tries=3", ENT_QUOTES, 'UTF-8')
			);
		}

		$user = $this->wire()->user;
		$isSuperuser = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;

		$tabUrl = $this->pageUrlWithTab('queues');
		$csrf = $this->wire()->session->CSRF->renderInput();

		$tablesOk = $this->queueTablesExist();
		$tablesAlert = $tablesOk
			? '<div class="uk-alert uk-alert-primary" uk-alert><p>' . htmlspecialchars($this->_('Queue tables detected (queue_jobs, failed_jobs).'), ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '<div class="uk-alert uk-alert-warning" uk-alert><p>' . htmlspecialchars($this->_('Queue tables are not installed. Install them to enable queue actions and tracking.'), ENT_QUOTES, 'UTF-8') . '</p></div>';

		$installForm = '';
		if (!$tablesOk && $isSuperuser) {
			$installForm = sprintf(
				'
				<form method="post" action="%s" class="uk-margin-top">
					%s
					<input type="hidden" name="wc_action" value="queue_install">
					<button class="uk-button uk-button-primary" type="submit">%s</button>
					<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
				</form>
				',
				htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
				$csrf,
				$this->_('Install queue tables'),
				$this->_('Creates the queue_jobs and failed_jobs tables used by the queue system.')
			);
		}

		$disabled = ($isSuperuser && $tablesOk) ? '' : 'disabled';
		$permissionNote = $isSuperuser
			? ($tablesOk
				? $this->_('You are superuser. You can run queue actions from this UI.')
				: $this->_('You are superuser. Install the queue tables first to enable actions.'))
			: $this->_('Only superusers can run queue actions from this UI.');

		// Discovered queue handlers
		$queueMap = [];
		try {
			$queueMap = $manager->discoverQueues();
		} catch (\Throwable) {
			$queueMap = [];
		}

		$queueHandlersRows = '';
		if (!empty($queueMap)) {
			$items = array_keys($queueMap);
			sort($items);
			foreach (array_slice($items, 0, 30) as $name) {
				$queueHandlersRows .= '<li><code>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</code></li>';
			}
		} else {
			$queueHandlersRows = '<li class="uk-text-muted">' . htmlspecialchars($this->_('No queue handlers discovered.'), ENT_QUOTES, 'UTF-8') . '</li>';
		}

		// Stats + failed jobs
		$statsHtml = '';
		$failedRows = '';
		if ($tablesOk) {
			$db = $this->wire()->database;

			$totalJobs = (int) $db->query("SELECT COUNT(*) FROM queue_jobs")->fetchColumn();
			$totalFailed = (int) $db->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn();

			$statsHtml = sprintf(
				'<div class="uk-grid uk-grid-small uk-child-width-auto" uk-grid>
					<div><span class="uk-badge">%s: %d</span></div>
					<div><span class="uk-badge">%s: %d</span></div>
				</div>',
				htmlspecialchars($this->_('Queued'), ENT_QUOTES, 'UTF-8'),
				$totalJobs,
				htmlspecialchars($this->_('Failed'), ENT_QUOTES, 'UTF-8'),
				$totalFailed
			);

			$stmt = $db->query("SELECT id, queue, payload, failed_at FROM failed_jobs ORDER BY id DESC LIMIT 50");
			$failedJobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			if (empty($failedJobs)) {
				$failedRows = '<tr><td colspan="4" class="uk-text-muted">' . htmlspecialchars($this->_('No failed jobs found.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
			} else {
				foreach ($failedJobs as $job) {
					$payload = json_decode((string) ($job['payload'] ?? ''), true);
					$class = is_array($payload) ? ($payload['class'] ?? 'UnknownClass') : 'UnknownClass';

					$failedRows .= sprintf(
						'<tr>
							<td class="uk-text-nowrap">%s</td>
							<td class="uk-text-nowrap">%s</td>
							<td><code>%s</code></td>
							<td class="uk-text-nowrap">%s</td>
						</tr>',
						htmlspecialchars((string) $job['id'], ENT_QUOTES, 'UTF-8'),
						htmlspecialchars((string) $job['queue'], ENT_QUOTES, 'UTF-8'),
						htmlspecialchars((string) $class, ENT_QUOTES, 'UTF-8'),
						htmlspecialchars((string) $job['failed_at'], ENT_QUOTES, 'UTF-8')
					);
				}
			}
		}

		$workerHelp = htmlspecialchars("php vendor/bin/wire queue:work --queue=default --sleep=3 --tries=3", ENT_QUOTES, 'UTF-8');

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				%s
				%s

				<div class="uk-margin-top">
					<p class="uk-text-small uk-text-muted">%s</p>
					%s
				</div>

				<hr class="uk-margin">

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m" uk-grid>
					<div>
						<h4 class="uk-h4">%s</h4>
						<p class="uk-text-small uk-text-muted">%s</p>
						<pre><code>%s</code></pre>
					</div>
					<div>
						<h4 class="uk-h4">%s</h4>
						<p class="uk-text-small uk-text-muted">%s</p>
						<form method="post" action="%s" class="uk-margin-small">
							%s
							<input type="hidden" name="wc_action" value="queue_retry">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="failed_id" value="" placeholder="e.g. 12 or all" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="RETRY" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-primary" type="submit" %s>%s</button>
						</form>

						<form method="post" action="%s" class="uk-margin-small">
							%s
							<input type="hidden" name="wc_action" value="queue_clear">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="CLEAR" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-danger" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
				</div>

				<hr class="uk-margin">

				<h4 class="uk-h4">%s</h4>
				<ul class="uk-list uk-list-divider">%s</ul>

				<hr class="uk-margin">

				<h4 class="uk-h4">%s</h4>
				<div class="uk-overflow-auto">
					<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
						<thead>
							<tr>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</div>
			',
			$this->_('Queues'),
			$this->_('This UI uses the Console package queue engine (PHP API).'),
			$panelMessages,
			$tablesAlert,
			$installForm,
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$statsHtml,
			$this->_('Worker (recommended via CLI)'),
			$this->_('Queue workers are long-running processes. For stability, start them via CLI/supervisor.'),
			$workerHelp,
			$this->_('Actions'),
			$this->_('Retry failed jobs or clear the failed queue (superuser only).'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Failed job ID'),
			$disabled,
			$this->_('Use a numeric ID or "all".'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type RETRY to confirm.'),
			$disabled,
			$this->_('Retry'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$disabled,
			$this->_('Clear failed jobs'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type CLEAR to confirm.'),
			$this->_('This will delete all rows from failed_jobs.'),
			$this->_('Discovered queue handlers'),
			$queueHandlersRows,
			$this->_('Failed jobs (latest 50)'),
			$this->_('ID'),
			$this->_('Queue'),
			$this->_('Class'),
			$this->_('Failed at'),
			$failedRows
		);
	}

	private function handleQueuesPost(): void
	{
		$action = $this->input->post->text('wc_action');

		// Ignore unrelated posts (other tabs)
		if (!str_starts_with($action, 'queue_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run queue actions from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('queues', 'danger', $msg);
			$this->audit('queues', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_QUEUES)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_QUEUES);
			$this->error($msg);
			$this->addPanelMessage('queues', 'danger', $msg);
			$this->audit('queues', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_QUEUES]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		$confirm = (int) $this->input->post->int('confirm');
		if ($confirm !== 1) {
			$msg = $this->_('Confirmation missing.');
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'danger', $msg);
			$this->audit('scheduling.run', 'blocked', ['reason' => 'confirm_missing']);
			return;
		}

		$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
		if ($confirmText !== 'RUN') {
			$msg = $this->_('Type RUN to confirm.');
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'danger', $msg);
			$this->audit('scheduling.run', 'blocked', ['reason' => 'confirm_text_mismatch']);
			return;
		}

		if (!$this->checkRateLimit('scheduling', 10)) {
			$msg = $this->_('Please wait a moment before running another action.');
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'warning', $msg);
			$this->audit('scheduling.run', 'rate_limited');
			return;
		}

		$this->requireProjectAutoload();
		$manager = $this->getQueueManager();
		if (!$manager) {
			$msg = $this->_('Queue engine is not available. Please ensure the Console package is installed and autoloadable.');
			$this->error($msg);
			$this->addPanelMessage('queues', 'danger', $msg);
			$this->audit('queues', 'error', ['action' => $action, 'reason' => 'engine_unavailable']);
			return;
		}

		try {
			if ($action === 'queue_install') {
				$manager->ensureTables();
				$msg = $this->_('Queue tables installed.');
				$this->message($msg);
				$this->addPanelMessage('queues', 'success', $msg);
				$this->audit('queues.install_tables', 'success');
				return;
			}

			if (!$this->queueTablesExist()) {
				$msg = $this->_('Queue tables are not installed. Install them first.');
				$this->error($msg);
				$this->addPanelMessage('queues', 'danger', $msg);
				$this->audit('queues', 'blocked', ['action' => $action, 'reason' => 'tables_missing']);
				return;
			}

			if ($action === 'queue_clear') {
				$confirm = (int) $this->input->post->int('confirm');
				if ($confirm !== 1) {
					$this->error($this->_('Confirmation missing.'));
					return;
				}
				$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
				if ($confirmText !== 'CLEAR') {
					$msg = $this->_('Type CLEAR to confirm.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'danger', $msg);
					$this->audit('queues.clear_failed', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}
				$db = $this->wire()->database;
				if (!$this->checkRateLimit('queues_clear', 5)) {
					$msg = $this->_('Please wait a moment before running another action.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'warning', $msg);
					$this->audit('queues.clear_failed', 'rate_limited');
					return;
				}

				$db->exec("DELETE FROM failed_jobs");
				$db->exec("ALTER TABLE failed_jobs AUTO_INCREMENT = 1");
				$msg = $this->_('Failed jobs have been cleared.');
				$this->message($msg);
				$this->addPanelMessage('queues', 'success', $msg);
				$this->audit('queues.clear_failed', 'success');
				return;
			}


			if ($action === 'queue_retry') {
				$confirm = (int) $this->input->post->int('confirm');
				if ($confirm !== 1) {
					$msg = $this->_('Confirmation missing.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'danger', $msg);
					$this->audit('queues.retry_failed', 'blocked', ['reason' => 'confirm_missing']);
					return;
				}

				$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
				if ($confirmText !== 'RETRY') {
					$msg = $this->_('Type RETRY to confirm.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'danger', $msg);
					$this->audit('queues.retry_failed', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				if (!$this->checkRateLimit('queues_retry', 3)) {
					$msg = $this->_('Please wait a moment before running another action.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'warning', $msg);
					$this->audit('queues.retry_failed', 'rate_limited');
					return;
				}

				$id = trim((string) $this->input->post->text('failed_id'));
				if ($id === '') {
					$msg = $this->_('Failed job ID is required.');
					$this->error($msg);
					$this->addPanelMessage('queues', 'danger', $msg);
					$this->audit('queues.retry_failed', 'blocked', ['reason' => 'id_missing']);
					return;
				}

				$db = $this->wire()->database;

				if ($id === 'all') {
					$stmt = $db->query("SELECT * FROM failed_jobs");
					$jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
					if (empty($jobs)) {
						$msg = $this->_('No failed jobs found.');
						$this->message($msg);
						$this->addPanelMessage('queues', 'primary', $msg);
						$this->audit('queues.retry_failed', 'blocked', ['mode' => 'all', 'reason' => 'no_failed_jobs']);
						return;
					}
					$count = 0;
					foreach ($jobs as $job) {
						$this->requeueFailedJob($db, $job);
						$count++;
					}
					$msg = sprintf($this->_('Successfully re-queued %d job(s).'), $count);
					$this->message($msg);
					$this->addPanelMessage('queues', 'success', $msg);
					$this->audit('queues.retry_failed', 'success', ['mode' => 'all', 'count' => $count]);
					return;
				}

				$jobId = (int) $id;
				$stmt = $db->prepare("SELECT * FROM failed_jobs WHERE id = :id");
				$stmt->execute([':id' => $jobId]);
				$job = $stmt->fetch(\PDO::FETCH_ASSOC);
				if (!$job) {
					$msg = sprintf($this->_('Failed job with ID [%d] not found.'), $jobId);
					$this->error($msg);
					$this->addPanelMessage('queues', 'danger', $msg);
					$this->audit('queues.retry_failed', 'blocked', ['mode' => 'id', 'reason' => 'not_found', 'id' => $jobId]);
					return;
				}

				$this->requeueFailedJob($db, $job);
				$msg = sprintf($this->_('Job [%d] has been pushed back onto the queue.'), $jobId);
				$this->message($msg);
				$this->addPanelMessage('queues', 'success', $msg);
				$this->audit('queues.retry_failed', 'success', ['mode' => 'id', 'id' => $jobId]);
				return;
			}
		} catch (\Throwable $e) {
			$this->reportException('queues', 'queues', $e, ['action' => $action]);
		}
	}

	private function requeueFailedJob(\ProcessWire\WireDatabasePDO $db, array $job): void
	{
		$insert = $db->prepare("
			INSERT INTO queue_jobs (queue, payload, attempts, available_at, created_at)
			VALUES (:queue, :payload, 0, :available_at, :created_at)
		");
		$insert->execute([
			':queue' => $job['queue'],
			':payload' => $job['payload'],
			':available_at' => time(),
			':created_at' => time(),
		]);

		$delete = $db->prepare("DELETE FROM failed_jobs WHERE id = :id");
		$delete->execute([':id' => $job['id']]);
	}

	private function queueTablesExist(): bool
	{
		$db = $this->wire()->database;
		try {
			$stmt = $db->query("SELECT 1 FROM queue_jobs LIMIT 1");
			$stmt->closeCursor();
		} catch (\Throwable) {
			return false;
		}

		try {
			$stmt = $db->query("SELECT 1 FROM failed_jobs LIMIT 1");
			$stmt->closeCursor();
		} catch (\Throwable) {
			return false;
		}

		return true;
	}

	private function getQueueManager(): ?\Totoglu\Console\Queue\QueueManager
	{
		return $this->console()->getQueueManager();
	}

	private function renderSeedingCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_SEEDING)) {
			return $this->renderPermissionRequiredCard($this->_('Database Seeding'), self::PERM_SEEDING);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleSeedingPost();
		}

		$panelMessages = $this->renderPanelMessages('seeding');

		$autoloadOk = $this->requireProjectAutoload();
		$manager = $this->getSeederManager();

		if (!$autoloadOk || !$manager) {
			$help = $this->_('The PHP API for seeding could not be loaded. This section requires the Console package autoload to be available (vendor/autoload.php) and seeding classes to exist.');
			$supportHtml = $this->renderConsoleSupportDetails('seeding');

			return sprintf(
				'
				<div class="uk-card uk-card-default uk-card-body">
					<h3 class="uk-h3">%s</h3>
					<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
					<p class="uk-text-small uk-text-muted">%s</p>
					%s
					<p class="uk-margin-top">
						<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
					</p>
					<hr>
					<p class="uk-text-small uk-text-muted">%s</p>
					<pre><code>%s</code></pre>
				</div>
				',
				$this->_('Database Seeding'),
				$this->_('Database seeding UI is not available via PHP API in the current environment.'),
				htmlspecialchars($help, ENT_QUOTES, 'UTF-8'),
				$supportHtml,
				htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
				$this->_('Documentation / Repo'),
				$this->_('CLI fallback (manual):'),
				htmlspecialchars("php vendor/bin/wire db:seed --force\nphp vendor/bin/wire db:seed --class=DatabaseSeeder --force", ENT_QUOTES, 'UTF-8')
			);
		}

		$user = $this->wire()->user;
		$isSuperuser = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;
		$tabUrl = $this->pageUrlWithTab('seeding');
		$csrf = $this->wire()->session->CSRF->renderInput();

		$permissionNote = $isSuperuser
			? $this->_('You are superuser. You can run seeding actions from this UI.')
			: $this->_('Only superusers can run seeding actions from this UI.');

		$disabled = $isSuperuser ? '' : 'disabled';

		$seedersMap = [];
		$error = null;
		try {
			$seedersMap = $manager->getAvailableSeeders();
		} catch (\Throwable $e) {
			$error = $this->safeExceptionMessage($e);
			$this->audit('seeding.list', 'exception', ['message' => $e->getMessage()]);
			$seedersMap = [];
		}

		$errorAlert = $error
			? '<div class="uk-alert uk-alert-danger" uk-alert><p>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '';

		$optionsHtml = '<option value="all">' . htmlspecialchars($this->_('All discovered seeders'), ENT_QUOTES, 'UTF-8') . '</option>';
		$rows = '';

		if (!empty($seedersMap)) {
			$names = array_keys($seedersMap);
			sort($names);
			foreach ($names as $name) {
				$optionsHtml .= '<option value="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</option>';
				$rows .= '<tr><td><code>' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</code></td><td class="uk-text-muted uk-text-small">' . htmlspecialchars((string) $seedersMap[$name], ENT_QUOTES, 'UTF-8') . '</td></tr>';
			}
		} else {
			$rows = '<tr><td colspan="2" class="uk-text-muted">' . htmlspecialchars($this->_('No seeders found.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		}

		$fakerNote = class_exists('\\Faker\\Factory')
			? $this->_('Faker detected (fakerphp/faker).')
			: $this->_('Faker not detected (optional): composer require fakerphp/faker');

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
				%s

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m uk-margin-top" uk-grid>
					<div>
						<h4 class="uk-h4">%s</h4>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="seed_run">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<select class="uk-select uk-form-width-medium" name="seeder" %s>
										%s
									</select>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="SEED" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-danger" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
					<div>
						<h4 class="uk-h4">%s</h4>
						<p class="uk-text-small uk-text-muted">%s</p>
						<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
							<thead><tr><th>%s</th><th>%s</th></tr></thead>
							<tbody>%s</tbody>
						</table>
					</div>
				</div>
			</div>
			',
			$this->_('Database Seeding'),
			$this->_('This UI uses the Console package seeding engine (PHP API).'),
			$panelMessages,
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$errorAlert,
			$this->_('Run seeders'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Seeder'),
			$disabled,
			$optionsHtml,
			htmlspecialchars($this->_('Seeding may insert large amounts of data. Proceed carefully.'), ENT_QUOTES, 'UTF-8'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type SEED to confirm.'),
			$disabled,
			$this->_('Run seeding'),
			htmlspecialchars($fakerNote, ENT_QUOTES, 'UTF-8'),
			$this->_('Discovered seeders'),
			htmlspecialchars($this->_('Available seeders detected by the Console package.'), ENT_QUOTES, 'UTF-8'),
			$this->_('Seeder'),
			$this->_('Path'),
			$rows
		);
	}

	private function handleSeedingPost(): void
	{
		$action = $this->input->post->text('wc_action');
		if (!str_starts_with($action, 'seed_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run seeding actions from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('seeding', 'danger', $msg);
			$this->audit('seeding', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_SEEDING)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_SEEDING);
			$this->error($msg);
			$this->addPanelMessage('seeding', 'danger', $msg);
			$this->audit('seeding', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_SEEDING]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		$confirm = (int) $this->input->post->int('confirm');
		if ($confirm !== 1) {
			$msg = $this->_('Confirmation missing.');
			$this->error($msg);
			$this->addPanelMessage('seeding', 'danger', $msg);
			$this->audit('seeding', 'blocked', ['action' => $action, 'reason' => 'confirm_missing']);
			return;
		}

		$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
		if ($confirmText !== 'SEED') {
			$msg = $this->_('Type SEED to confirm.');
			$this->error($msg);
			$this->addPanelMessage('seeding', 'danger', $msg);
			$this->audit('seeding.run', 'blocked', ['reason' => 'confirm_text_mismatch']);
			return;
		}

		if (!$this->checkRateLimit('seeding', 10)) {
			$msg = $this->_('Please wait a moment before running another action.');
			$this->error($msg);
			$this->addPanelMessage('seeding', 'warning', $msg);
			$this->audit('seeding.run', 'rate_limited');
			return;
		}

		$this->requireProjectAutoload();
		$manager = $this->getSeederManager();
		if (!$manager) {
			$msg = $this->_('Seeding engine is not available. Please ensure the Console package is installed and autoloadable.');
			$this->error($msg);
			$this->addPanelMessage('seeding', 'danger', $msg);
			$this->audit('seeding', 'error', ['action' => $action, 'reason' => 'engine_unavailable']);
			return;
		}

		try {
			if ($action === 'seed_run') {
				$seeders = $manager->getAvailableSeeders();
				if (empty($seeders)) {
					$msg = $this->_('No seeders found.');
					$this->message($msg);
					$this->addPanelMessage('seeding', 'primary', $msg);
					return;
				}

				$selected = $this->input->post->text('seeder');
				if ($selected === '' || $selected === 'all') {
					$count = 0;
					foreach ($seeders as $name => $path) {
						$this->runSeeder($name, $path);
						$count++;
					}
					$msg = sprintf($this->_('Seeded %d seeder(s).'), $count);
					$this->message($msg);
					$this->addPanelMessage('seeding', 'success', $msg);
					$this->audit('seeding.run', 'success', ['mode' => 'all', 'count' => $count]);
					return;
				}

				if (!isset($seeders[$selected])) {
					$msg = sprintf($this->_('Seeder not found: %s'), $selected);
					$this->error($msg);
					$this->addPanelMessage('seeding', 'danger', $msg);
					$this->audit('seeding.run', 'blocked', ['reason' => 'seeder_not_found', 'seeder' => $selected]);
					return;
				}

				$this->runSeeder($selected, $seeders[$selected]);
				$msg = sprintf($this->_('Seeded: %s'), $selected);
				$this->message($msg);
				$this->addPanelMessage('seeding', 'success', $msg);
				$this->audit('seeding.run', 'success', ['mode' => 'single', 'seeder' => $selected]);
			}
		} catch (\Throwable $e) {
			$this->reportException('seeding', 'seeding', $e, ['action' => $action]);
		}
	}

	private function runSeeder(string $name, string $path): void
	{
		require_once $path;

		$namespace = '';
		$content = (string) @file_get_contents($path);
		if ($content !== '' && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
			$namespace = trim($matches[1]) . '\\';
		}

		$fullClassName = $namespace . $name;
		if (!class_exists($fullClassName)) {
			throw new \RuntimeException("Seeder class not found: {$fullClassName}");
		}

		$wire = $this->wire();
		$instance = new $fullClassName($wire);
		$seederBase = $this->console()->getClass('\\Totoglu\\Console\\Database\\Seeder');
		if (!$seederBase || !$instance instanceof $seederBase) {
			throw new \RuntimeException("Seeder must extend Totoglu\\Console\\Database\\Seeder: {$fullClassName}");
		}

		$instance->run();
	}

	private function getSeederManager(): ?\Totoglu\Console\Database\SeederManager
	{
		return $this->console()->getSeederManager();
	}

	private function renderTestingCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_TESTING)) {
			return $this->renderPermissionRequiredCard($this->_('Testing'), self::PERM_TESTING);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleTestingPost();
		}

		$panelMessages = $this->renderPanelMessages('testing');

		$root = WireConsoleDependencyChecker::getProjectRootPath($this->wire()->config);
		$wireBinary = $root !== '' ? ($root . '/vendor/bin/wire') : null;
		$pestBinary = $root !== '' ? ($root . '/vendor/bin/pest') : null;
		$phpunitBinary = $root !== '' ? ($root . '/vendor/bin/phpunit') : null;

		$hasWire = $wireBinary && is_file($wireBinary);
		$hasPest = $pestBinary && is_file($pestBinary);
		$hasPhpunit = $phpunitBinary && is_file($phpunitBinary);

		$user = $this->wire()->user;
		$isSuperuser = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;

		$tabUrl = $this->pageUrlWithTab('testing');
		$csrf = $this->wire()->session->CSRF->renderInput();

		$permissionNote = $isSuperuser
			? $this->_('You are superuser. You can run test commands from this UI (optional).')
			: $this->_('Only superusers can run test commands from this UI.');

		$canExec = $this->canRunProcesses();
		$disabled = ($isSuperuser && $hasWire && $canExec) ? '' : 'disabled';

		$envRows = '';
		$envRows .= '<tr><td>' . htmlspecialchars($this->_('wire binary'), ENT_QUOTES, 'UTF-8') . '</td><td><code>' . htmlspecialchars($hasWire ? 'vendor/bin/wire' : '-', ENT_QUOTES, 'UTF-8') . '</code></td><td>' . htmlspecialchars($hasWire ? $this->_('Detected') : $this->_('Not found'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		$envRows .= '<tr><td>' . htmlspecialchars($this->_('Pest'), ENT_QUOTES, 'UTF-8') . '</td><td><code>' . htmlspecialchars($hasPest ? 'vendor/bin/pest' : '-', ENT_QUOTES, 'UTF-8') . '</code></td><td>' . htmlspecialchars($hasPest ? $this->_('Detected') : $this->_('Not found'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		$envRows .= '<tr><td>' . htmlspecialchars($this->_('PHPUnit'), ENT_QUOTES, 'UTF-8') . '</td><td><code>' . htmlspecialchars($hasPhpunit ? 'vendor/bin/phpunit' : '-', ENT_QUOTES, 'UTF-8') . '</code></td><td>' . htmlspecialchars($hasPhpunit ? $this->_('Detected') : $this->_('Not found'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		$envRows .= '<tr><td>' . htmlspecialchars($this->_('Process execution'), ENT_QUOTES, 'UTF-8') . '</td><td><code>proc_open</code></td><td>' . htmlspecialchars($canExec ? $this->_('Available') : $this->_('Disabled'), ENT_QUOTES, 'UTF-8') . '</td></tr>';

		$recommended = htmlspecialchars("php vendor/bin/wire test", ENT_QUOTES, 'UTF-8');

		// Previous output (if any)
		$session = $this->wire()->session;
		$lastOutput = (string) $session->get('wireconsole_test_last_output');
		$lastExit = $session->get('wireconsole_test_last_exit');
		$lastExitText = $lastExit === null ? '-' : (string) $lastExit;

		$outputBlock = '';
		if ($lastOutput !== '') {
			if ($this->isDebugMode()) {
				$outputBlock = sprintf(
					'<hr class="uk-margin">
					<h4 class="uk-h4">%s</h4>
					<p class="uk-text-small uk-text-muted">%s: <code>%s</code></p>
					<pre><code>%s</code></pre>',
					htmlspecialchars($this->_('Last test output'), ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($this->_('Exit code'), ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($lastExitText, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($lastOutput, ENT_QUOTES, 'UTF-8')
				);
			} else {
				$outputBlock = sprintf(
					'<hr class="uk-margin">
					<h4 class="uk-h4">%s</h4>
					<p class="uk-text-small uk-text-muted">%s: <code>%s</code></p>
					<div class="uk-alert uk-alert-primary" uk-alert><p>%s</p></div>',
					htmlspecialchars($this->_('Last test output'), ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($this->_('Exit code'), ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($lastExitText, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($this->_('Output is hidden when debug mode is off.'), ENT_QUOTES, 'UTF-8')
				);
			}
		}

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m uk-margin-top" uk-grid>
					<div>
						<h4 class="uk-h4">%s</h4>
						<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
							<thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead>
							<tbody>%s</tbody>
						</table>
						<p class="uk-text-small uk-text-muted">%s</p>
						<pre><code>%s</code></pre>
					</div>
					<div>
						<h4 class="uk-h4">%s</h4>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="test_run">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input" type="text" name="args" value="" placeholder="e.g. --filter=WireConsole" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="TEST" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-primary" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
				</div>
				%s
			</div>
			',
			$this->_('Testing'),
			$this->_('This section provides a bridge UI. The Console package runs tests via Pest (CLI).'),
			$panelMessages,
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$this->_('Environment'),
			$this->_('Tool'),
			$this->_('Path'),
			$this->_('Status'),
			$envRows,
			$this->_('Recommended (CLI):'),
			$recommended,
			$this->_('Run tests (optional)'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Arguments'),
			$disabled,
			$this->_('Forwarded to the wire test command.'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type TEST to confirm.'),
			$disabled,
			$this->_('Run tests now'),
			htmlspecialchars($this->_('Running tests from the admin UI may be slow and can be restricted by server settings.'), ENT_QUOTES, 'UTF-8'),
			$outputBlock
		);
	}

	private function handleTestingPost(): void
	{
		$action = $this->input->post->text('wc_action');
		if (!str_starts_with($action, 'test_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run test commands from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_TESTING)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_TESTING);
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_TESTING]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		$confirm = (int) $this->input->post->int('confirm');
		if ($confirm !== 1) {
			$msg = $this->_('Confirmation missing.');
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing', 'blocked', ['action' => $action, 'reason' => 'confirm_missing']);
			return;
		}
		$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
		if ($confirmText !== 'TEST') {
			$msg = $this->_('Type TEST to confirm.');
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing.run', 'blocked', ['reason' => 'confirm_text_mismatch']);
			return;
		}

		if (!$this->checkRateLimit('testing', 10)) {
			$msg = $this->_('Please wait a moment before running another action.');
			$this->error($msg);
			$this->addPanelMessage('testing', 'warning', $msg);
			$this->audit('testing.run', 'rate_limited');
			return;
		}

		if (!$this->canRunProcesses()) {
			$msg = $this->_('Process execution is disabled on this server (proc_open not available).');
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing.run', 'error', ['reason' => 'proc_open_disabled']);
			return;
		}

		$root = WireConsoleDependencyChecker::getProjectRootPath($this->wire()->config);
		$wireBinary = $root !== '' ? ($root . '/vendor/bin/wire') : null;
		if (!$wireBinary || !is_file($wireBinary)) {
			$msg = $this->_('wire binary not found at vendor/bin/wire.');
			$this->error($msg);
			$this->addPanelMessage('testing', 'danger', $msg);
			$this->audit('testing.run', 'error', ['reason' => 'wire_binary_missing']);
			return;
		}

		if ($action === 'test_run') {
			$args = trim((string) $this->input->post->text('args'));
			$args = $this->sanitizeWireTestArgs($args);

			$cmd = 'php ' . escapeshellarg($wireBinary) . ' test';
			if ($args !== '') {
				$cmd .= ' ' . $args;
			}

			$result = $this->runCliCommand($cmd, $root, 60);
			$this->wire()->session->set('wireconsole_test_last_exit', $result['exit']);
			$this->wire()->session->set('wireconsole_test_last_output', $result['output']);

			if ($result['exit'] === 0) {
				$msg = $this->_('Tests completed successfully.');
				$this->message($msg);
				$this->addPanelMessage('testing', 'success', $msg);
				$this->audit('testing.run', 'success', ['args' => $args, 'exit' => 0]);
			} else {
				$msg = sprintf($this->_('Tests finished with exit code: %d'), (int) $result['exit']);
				$this->error($msg);
				$this->addPanelMessage('testing', 'danger', $msg);
				$this->audit('testing.run', 'error', ['args' => $args, 'exit' => (int) $result['exit']]);
			}
		}
	}

	private function canRunProcesses(): bool
	{
		if (!function_exists('proc_open')) {
			return false;
		}

		$disabled = (string) ini_get('disable_functions');
		if ($disabled === '') {
			return true;
		}

		$list = array_map('trim', explode(',', $disabled));
		return !in_array('proc_open', $list, true);
	}

	private function sanitizeCliArgs(string $args): string
	{
		// Allowlist: letters, numbers, spaces, dashes, underscores, colons, equals, dots, slashes.
		// Block shell metacharacters like ; & | ` $ > < ( )
		$clean = preg_replace('/[^a-zA-Z0-9_\\-\\s:=\\.\\/]/', '', $args);
		return trim((string) $clean);
	}

	/**
	 * Allowlist for "wire test" options when executed from the admin UI.
	 */
	private function sanitizeWireTestArgs(string $args): string
	{
		$args = trim($args);
		if ($args === '') {
			return '';
		}

		$tokens = preg_split('/\s+/', $args) ?: [];
		$out = [];

		foreach ($tokens as $t) {
			if ($t === '--parallel' || $t === '--bail' || $t === '--compact' || $t === '--stop-on-failure') {
				$out[] = $t;
				continue;
			}

			if (str_starts_with($t, '--filter=')) {
				$value = substr($t, 9);
				// Restrict filter to safe characters only.
				if ($value !== '' && preg_match('/^[a-zA-Z0-9_\\-\\.\\\\\\/]+$/', $value)) {
					$out[] = '--filter=' . $value;
				}
				continue;
			}

			if ($t === '--colors=never' || $t === '--colors=always') {
				$out[] = $t;
				continue;
			}
		}

		return implode(' ', $out);
	}

	private function checkRateLimit(string $key, int $seconds): bool
	{
		$session = $this->wire()->session;
		/** @var array<string, int> $map */
		$map = $session->get('wireconsole_rate_limits') ?: [];
		$now = time();

		$last = (int) ($map[$key] ?? 0);
		if ($last > 0 && ($now - $last) < $seconds) {
			return false;
		}

		$map[$key] = $now;
		$session->set('wireconsole_rate_limits', $map);
		return true;
	}

	/**
	 * @return array{exit: int, output: string}
	 */
	private function runCliCommand(string $command, string $cwd, int $timeoutSeconds): array
	{
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = @proc_open($command, $descriptors, $pipes, $cwd);
		if (!is_resource($process)) {
			return ['exit' => 1, 'output' => 'Failed to start process.'];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$output = '';
		$start = time();

		while (true) {
			$status = proc_get_status($process);
			$output .= (string) stream_get_contents($pipes[1]);
			$output .= (string) stream_get_contents($pipes[2]);

			if (!$status['running']) {
				break;
			}

			if ((time() - $start) >= $timeoutSeconds) {
				proc_terminate($process);
				$output .= "\n[Timeout] Process terminated after {$timeoutSeconds} seconds.\n";
				break;
			}

			usleep(100000); // 100ms
		}

		$output .= (string) stream_get_contents($pipes[1]);
		$output .= (string) stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$exit = proc_close($process);

		// Limit stored output size
		if (strlen($output) > 20000) {
			$output = substr($output, -20000);
		}

		return ['exit' => (int) $exit, 'output' => trim($output)];
	}

	private function renderBackupCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_BACKUP)) {
			return $this->renderPermissionRequiredCard($this->_('Database & Backup'), self::PERM_BACKUP);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleBackupPost();
		}

		$panelMessages = $this->renderPanelMessages('backup');

		$tabUrl = $this->pageUrlWithTab('backup');
		$csrf = $this->wire()->session->CSRF->renderInput();

		$backupTool = $this->getDatabaseBackupTool();
		$backupDir = $this->getDatabaseBackupsDirPath();
		$backupUrl = $this->getDatabaseBackupsDirUrl();

		$user = $this->wire()->user;
		$isSuperuser = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;

		$toolAlert = $backupTool
			? '<div class="uk-alert uk-alert-primary" uk-alert><p>' . htmlspecialchars($this->_('Database backup tool detected.'), ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '<div class="uk-alert uk-alert-warning" uk-alert><p>' . htmlspecialchars($this->_('Database backup tool not available (database->backups() returned null).'), ENT_QUOTES, 'UTF-8') . '</p></div>';

		$dirAlert = is_dir($backupDir)
			? '<div class="uk-alert uk-alert-primary" uk-alert><p>' . htmlspecialchars($this->_('Backup directory detected.'), ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '<div class="uk-alert uk-alert-warning" uk-alert><p>' . htmlspecialchars(sprintf($this->_('Backup directory not found: %s'), $backupDir), ENT_QUOTES, 'UTF-8') . '</p></div>';

		$writeAlert = '';
		if (is_dir($backupDir) && !is_writable($backupDir)) {
			$writeAlert = '<div class="uk-alert uk-alert-danger" uk-alert><p>' . htmlspecialchars($this->_('Backup directory is not writable. Backup/restore actions may fail.'), ENT_QUOTES, 'UTF-8') . '</p></div>';
		}

		$permissionNote = $isSuperuser
			? $this->_('You are superuser. You can run backup actions from this UI.')
			: $this->_('Only superusers can run backup actions from this UI.');

		$disabled = ($isSuperuser && $backupTool) ? '' : 'disabled';

		// Backup list
		$files = $this->listDatabaseBackupFiles(50);
		$listRows = '';
		foreach ($files as $f) {
			$sizeKb = (int) (filesize($backupDir . $f) / 1024);
			$modified = date('Y-m-d H:i', (int) filemtime($backupDir . $f));
			$url = $backupUrl !== null ? ($backupUrl . rawurlencode($f)) : null;

			$nameHtml = $url
				? '<a class="uk-link-text" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</a>'
				: '<code>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</code>';

			$listRows .= sprintf(
				'<tr>
					<td>%s</td>
					<td class="uk-text-nowrap">%s</td>
					<td class="uk-text-nowrap">%s</td>
				</tr>',
				$nameHtml,
				htmlspecialchars((string) $sizeKb, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string) $modified, ENT_QUOTES, 'UTF-8')
			);
		}
		if ($listRows === '') {
			$listRows = '<tr><td colspan="3" class="uk-text-muted">' . htmlspecialchars($this->_('No backup files found.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		}

		$restoreOptionsHtml = '';
		foreach ($files as $f) {
			$restoreOptionsHtml .= '<option value="' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</option>';
		}

		$restoreDisabled = ($isSuperuser && $backupTool && !empty($files)) ? '' : 'disabled';
		$restoreHelp = empty($files)
			? $this->_('No backup files available to restore.')
			: $this->_('Select a backup file, type RESTORE to confirm, and submit.');

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				%s
				%s
				%s

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m uk-margin-top" uk-grid>
					<div>
						<h4 class="uk-h4">%s</h4>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="backup_create">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="BACKUP" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-primary" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
					<div>
						<h4 class="uk-h4">%s</h4>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="backup_purge">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="number" min="1" name="days" value="30" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="PURGE" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-danger" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
				</div>

				<hr class="uk-margin">

				<div class="uk-alert uk-alert-danger" uk-alert>
					<p>%s</p>
				</div>
				<div class="uk-alert uk-alert-primary" uk-alert>
					<p>%s</p>
					<ul class="uk-list uk-list-bullet uk-text-small uk-margin-small-top">
						<li><code>%s</code></li>
						<li><code>%s</code></li>
					</ul>
				</div>
				<form method="post" action="%s" class="uk-margin-top">
					%s
					<input type="hidden" name="wc_action" value="backup_restore">
					<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-3@m" uk-grid>
						<div>
							<label class="uk-form-label">%s</label>
							<div class="uk-form-controls">
								<select class="uk-select" name="file" %s>
									%s
								</select>
							</div>
						</div>
						<div>
							<label class="uk-form-label">%s</label>
							<div class="uk-form-controls">
								<input class="uk-input" type="text" name="confirm_text" value="" placeholder="RESTORE" %s>
							</div>
						</div>
						<div>
							<label class="uk-form-label">%s</label>
							<div class="uk-form-controls uk-margin-small-top">
								<label><input class="uk-checkbox" type="checkbox" name="pre_backup" value="1" checked %s> %s</label>
							</div>
						</div>
					</div>
					<input type="hidden" name="confirm" value="1">
					<button class="uk-button uk-button-danger uk-margin-top" type="submit" %s>%s</button>
					<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
				</form>

				<hr class="uk-margin">

				<p class="uk-text-small uk-text-muted">%s</p>

				<div class="uk-overflow-auto">
					<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
						<thead>
							<tr>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>

				<p class="uk-text-small uk-text-muted uk-margin-top">%s</p>
			</div>
			',
			$this->_('Database & Backup'),
			$this->_('This UI uses ProcessWire core backup tooling. The Console package provides the CLI and operational conventions.'),
			$panelMessages,
			$toolAlert,
			$dirAlert,
			$writeAlert,
			$this->_('Create backup'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type BACKUP to confirm.'),
			$disabled,
			$this->_('Create database backup'),
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$this->_('Purge old backups'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Days'),
			$disabled,
			$this->_('Delete backup files older than this many days.'),
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type PURGE to confirm.'),
			$disabled,
			$this->_('Purge'),
			$this->_('This will permanently delete backup files.'),
			$this->_('Restore will overwrite your database. Use with extreme caution.'),
			$this->_('After restoring a database, it is often recommended to clear caches and refresh modules:'),
			'php vendor/bin/wire cache:clear',
			'php vendor/bin/wire module:refresh',
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Restore backup'),
			$restoreDisabled,
			$restoreOptionsHtml,
			$this->_('Confirmation'),
			$restoreDisabled,
			$this->_('Create backup first'),
			$restoreDisabled,
			$this->_('Create a fresh backup before restoring (recommended)'),
			$restoreDisabled,
			$this->_('Restore database'),
			htmlspecialchars($restoreHelp, ENT_QUOTES, 'UTF-8'),
			$this->_('Latest backup files (up to 50):'),
			$this->_('File'),
			$this->_('Size (KB)'),
			$this->_('Modified'),
			$listRows,
			$this->_('Note: If direct downloads are blocked by server rules, retrieve backups from the filesystem instead.')
		);
	}

	private function handleBackupPost(): void
	{
		$action = $this->input->post->text('wc_action');

		if (!str_starts_with($action, 'backup_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run backup actions from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('backup', 'danger', $msg);
			$this->audit('backup', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_BACKUP)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_BACKUP);
			$this->error($msg);
			$this->addPanelMessage('backup', 'danger', $msg);
			$this->audit('backup', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_BACKUP]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		$backup = $this->getDatabaseBackupTool();
		if (!$backup) {
			$msg = $this->_('Database backup tool not available.');
			$this->error($msg);
			$this->addPanelMessage('backup', 'danger', $msg);
			$this->audit('backup', 'error', ['action' => $action, 'reason' => 'tool_unavailable']);
			return;
		}

		try {
			if ($action === 'backup_create') {
				$confirm = (int) $this->input->post->int('confirm');
				if ($confirm !== 1) {
					$msg = $this->_('Confirmation missing.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.create', 'blocked', ['reason' => 'confirm_missing']);
					return;
				}

				$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
				if ($confirmText !== 'BACKUP') {
					$msg = $this->_('Type BACKUP to confirm.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.create', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				if (!$this->checkRateLimit('backup_create', 10)) {
					$msg = $this->_('Please wait a moment before running another action.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'warning', $msg);
					$this->audit('backup.create', 'rate_limited');
					return;
				}

				$file = $backup->backup();
				if (!$file) {
					$msg = $this->_('Database backup failed.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.create', 'error');
					return;
				}
				$msg = sprintf($this->_('Database backup created: %s'), basename((string) $file));
				$this->message($msg);
				$this->addPanelMessage('backup', 'success', $msg);
				$this->audit('backup.create', 'success', ['file' => basename((string) $file)]);
				return;
			}

			if ($action === 'backup_purge') {
				$confirm = (int) $this->input->post->int('confirm');
				if ($confirm !== 1) {
					$this->error($this->_('Confirmation missing.'));
					return;
				}
				$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
				if ($confirmText !== 'PURGE') {
					$msg = $this->_('Type PURGE to confirm.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.purge', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				if (!$this->checkRateLimit('backup_purge', 10)) {
					$msg = $this->_('Please wait a moment before running another action.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'warning', $msg);
					$this->audit('backup.purge', 'rate_limited');
					return;
				}

				$days = (int) $this->input->post->int('days');
				if ($days < 1) {
					$msg = $this->_('Days must be >= 1.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.purge', 'blocked', ['reason' => 'invalid_days', 'days' => $days]);
					return;
				}

				$deleted = $this->purgeDatabaseBackupsOlderThanDays($days);
				$msg = sprintf($this->_('Deleted %d backup file(s) older than %d days.'), $deleted, $days);
				$this->message($msg);
				$this->addPanelMessage('backup', 'success', $msg);
				$this->audit('backup.purge', 'success', ['days' => $days, 'deleted' => $deleted]);
				return;
			}

			if ($action === 'backup_restore') {
				$confirm = (int) $this->input->post->int('confirm');
				if ($confirm !== 1) {
					$this->error($this->_('Confirmation missing.'));
					return;
				}

				if (!$this->checkRateLimit('backup_restore', 30)) {
					$msg = $this->_('Please wait a moment before running another action.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'warning', $msg);
					$this->audit('backup.restore', 'rate_limited');
					return;
				}

				$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));
				if ($confirmText !== 'RESTORE') {
					$msg = $this->_('Type RESTORE to confirm.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.restore', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				$fileName = basename((string) $this->input->post->text('file'));
				if ($fileName === '') {
					$this->error($this->_('Backup file is required.'));
					return;
				}

				$filePath = $this->resolveDatabaseBackupFilePath($fileName);
				if ($filePath === null) {
					$msg = $this->_('Selected backup file was not found.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.restore', 'blocked', ['reason' => 'file_not_found', 'file' => $fileName]);
					return;
				}

				$preBackup = (int) $this->input->post->int('pre_backup') === 1;
				if ($preBackup) {
					$preFile = $backup->backup();
					if (!$preFile) {
						$msg = $this->_('Pre-backup failed. Restore aborted.');
						$this->error($msg);
						$this->addPanelMessage('backup', 'danger', $msg);
						$this->audit('backup.restore', 'error', ['reason' => 'pre_backup_failed', 'file' => $fileName]);
						$errs = $backup->errors();
						if (!empty($errs)) {
							foreach ($errs as $err) {
								$this->error((string) $err);
								$this->addPanelMessage('backup', 'danger', (string) $err);
							}
						}
						return;
					}
					$msg = sprintf($this->_('Pre-backup created: %s'), basename((string) $preFile));
					$this->message($msg);
					$this->addPanelMessage('backup', 'success', $msg);
				}

				$ok = $backup->restore($filePath);
				if (!$ok) {
					$msg = $this->_('Database restore failed.');
					$this->error($msg);
					$this->addPanelMessage('backup', 'danger', $msg);
					$this->audit('backup.restore', 'error', ['file' => $fileName]);
					$errs = $backup->errors();
					if (!empty($errs)) {
						foreach ($errs as $err) {
							$this->error((string) $err);
							$this->addPanelMessage('backup', 'danger', (string) $err);
						}
					}
					return;
				}

				$msg = sprintf($this->_('Database restored from: %s'), $fileName);
				$this->message($msg);
				$this->addPanelMessage('backup', 'success', $msg);
				$this->audit('backup.restore', 'success', ['file' => $fileName, 'pre_backup' => $preBackup]);
				return;
			}
		} catch (\Throwable $e) {
			$this->reportException('backup', 'backup', $e, ['action' => $action]);
		}
	}

	private function getDatabaseBackupsDirPath(): string
	{
		return $this->wire()->config->paths->assets . 'backups/database/';
	}

	private function getDatabaseBackupsDirUrl(): ?string
	{
		// This is often blocked by /site/assets/backups/.htaccess in production.
		// Provide a best-effort URL only when it looks publicly accessible.
		if (!isset($this->wire()->config->urls->assets)) {
			return null;
		}
		$backupsHtaccess = $this->wire()->config->paths->assets . 'backups/.htaccess';
		if (is_file($backupsHtaccess)) {
			$content = (string) @file_get_contents($backupsHtaccess);
			if ($content !== '' && preg_match('/deny\s+from\s+all|require\s+all\s+denied/i', $content)) {
				return null;
			}
		}
		return $this->wire()->config->urls->assets . 'backups/database/';
	}

	private function listDatabaseBackupFiles(int $limit = 50): array
	{
		$dir = $this->getDatabaseBackupsDirPath();
		if (!is_dir($dir)) {
			return [];
		}

		$files = array_values(array_filter(scandir($dir) ?: [], static function (string $f) use ($dir): bool {
			return $f !== '.' && $f !== '..' && is_file($dir . $f);
		}));

		usort($files, static fn(string $a, string $b): int => filemtime($dir . $b) <=> filemtime($dir . $a));
		return array_slice($files, 0, $limit);
	}

	private function purgeDatabaseBackupsOlderThanDays(int $days): int
	{
		$dir = $this->getDatabaseBackupsDirPath();
		if (!is_dir($dir)) {
			return 0;
		}

		$threshold = time() - ($days * 86400);
		$deleted = 0;

		foreach (scandir($dir) ?: [] as $f) {
			if ($f === '.' || $f === '..') {
				continue;
			}
			$path = $dir . $f;
			if (!is_file($path)) {
				continue;
			}
			if ((int) filemtime($path) >= $threshold) {
				continue;
			}
			if (@unlink($path)) {
				$deleted++;
			}
		}

		return $deleted;
	}

	private function resolveDatabaseBackupFilePath(string $fileName): ?string
	{
		$fileName = basename($fileName);
		if ($fileName === '' || str_contains($fileName, '..')) {
			return null;
		}

		$dir = $this->getDatabaseBackupsDirPath();
		$path = $dir . $fileName;

		if (!is_file($path)) {
			return null;
		}

		return $path;
	}

	private function getDatabaseBackupTool(): ?\ProcessWire\WireDatabaseBackup
	{
		/** @var mixed $backup */
		$backup = $this->wire()->database->backups();
		return $backup instanceof \ProcessWire\WireDatabaseBackup ? $backup : null;
	}

	private function renderSchedulingCard(): string
	{
		if (!$this->userCanViewPermission(self::PERM_SCHEDULING)) {
			return $this->renderPermissionRequiredCard($this->_('Task Scheduling'), self::PERM_SCHEDULING);
		}

		// Handle actions first (POST). Keep it superuser-only.
		if ($this->input->post('wc_action')) {
			$this->handleSchedulingPost();
		}

		$panelMessages = $this->renderPanelMessages('scheduling');

		$autoloadOk = $this->requireProjectAutoload();
		$manager = $this->getScheduleManager();

		if (!$autoloadOk || !$manager) {
			$help = $this->_('The PHP API for scheduling could not be loaded. This section requires the Console package autoload to be available (vendor/autoload.php) and scheduling classes to exist.');
			$supportHtml = $this->renderConsoleSupportDetails('scheduling');

			return sprintf(
				'
				<div class="uk-card uk-card-default uk-card-body">
					<h3 class="uk-h3">%s</h3>
					<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
					<p class="uk-text-small uk-text-muted">%s</p>
					%s
					<p class="uk-margin-top">
						<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
					</p>
					<hr>
					<p class="uk-text-small uk-text-muted">%s</p>
					<pre><code>%s</code></pre>
				</div>
				',
				$this->_('Task Scheduling'),
				$this->_('Scheduling UI is not available via PHP API in the current environment.'),
				htmlspecialchars($help, ENT_QUOTES, 'UTF-8'),
				$supportHtml,
				htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
				$this->_('Documentation / Repo'),
				$this->_('CLI fallback (manual):'),
				htmlspecialchars("php vendor/bin/wire schedule:run", ENT_QUOTES, 'UTF-8')
			);
		}

		$user = $this->wire()->user;
		$isSuperuser = $user && method_exists($user, 'isSuperuser') ? $user->isSuperuser() : false;
		$tabUrl = $this->pageUrlWithTab('scheduling');
		$csrf = $this->wire()->session->CSRF->renderInput();

		$permissionNote = $isSuperuser
			? $this->_('You are superuser. You can run schedule actions from this UI.')
			: $this->_('Only superusers can run schedule actions from this UI.');

		$disabled = $isSuperuser ? '' : 'disabled';

		// Discover tasks and read schedules.
		$tasks = [];
		$error = null;
		try {
			$tasks = $this->discoverScheduledTasks($manager);
		} catch (\Throwable $e) {
			$error = $this->safeExceptionMessage($e);
			$this->audit('scheduling.list', 'exception', ['message' => $e->getMessage()]);
			$tasks = [];
		}

		$errorAlert = $error
			? '<div class="uk-alert uk-alert-danger" uk-alert><p>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p></div>'
			: '';

		$rows = '';
		foreach ($tasks as $t) {
			$dueLabel = $t['is_due'] ? '<span class="uk-label uk-label-success">' . htmlspecialchars($this->_('Due'), ENT_QUOTES, 'UTF-8') . '</span>'
				: '<span class="uk-label uk-label-warning">' . htmlspecialchars($this->_('Not due'), ENT_QUOTES, 'UTF-8') . '</span>';

			$rows .= sprintf(
				'<tr>
					<td><code>%s</code></td>
					<td class="uk-text-nowrap"><code>%s</code></td>
					<td class="uk-text-nowrap">%s</td>
					<td class="uk-text-nowrap">%s</td>
				</tr>',
				htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($t['expression'], ENT_QUOTES, 'UTF-8'),
				$dueLabel,
				htmlspecialchars($t['next_run'] ?? '-', ENT_QUOTES, 'UTF-8')
			);
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="4" class="uk-text-muted">' . htmlspecialchars($this->_('No scheduled tasks found.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		}

		$cronHelp = htmlspecialchars('* * * * * cd /path/to/project && php vendor/bin/wire schedule:run >> /dev/null 2>&1', ENT_QUOTES, 'UTF-8');

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				%s
				<div class="uk-alert uk-alert-primary" uk-alert><p>%s</p></div>
				%s

				<div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m uk-margin-top" uk-grid>
					<div>
						<h4 class="uk-h4">%s</h4>
						<p class="uk-text-small uk-text-muted">%s</p>
						<pre><code>%s</code></pre>
					</div>
					<div>
						<h4 class="uk-h4">%s</h4>
						<form method="post" action="%s">
							%s
							<input type="hidden" name="wc_action" value="schedule_run">
							<input type="hidden" name="confirm" value="1">
							<div class="uk-margin-small">
								<label class="uk-form-label">%s</label>
								<div class="uk-form-controls">
									<input class="uk-input uk-form-width-small" type="text" name="confirm_text" value="" placeholder="RUN" %s>
									<p class="uk-text-small uk-text-muted">%s</p>
								</div>
							</div>
							<button class="uk-button uk-button-primary" type="submit" %s>%s</button>
							<p class="uk-text-small uk-text-muted uk-margin-small-top">%s</p>
						</form>
					</div>
				</div>

				<hr class="uk-margin">
				<p class="uk-text-small uk-text-muted">%s</p>
				<div class="uk-overflow-auto">
					<table class="uk-table uk-table-divider uk-table-small uk-table-justify">
						<thead>
							<tr>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
								<th>%s</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</div>
			',
			$this->_('Task Scheduling'),
			$this->_('This UI uses the Console package scheduler (PHP API).'),
			$panelMessages,
			htmlspecialchars($permissionNote, ENT_QUOTES, 'UTF-8'),
			$errorAlert,
			$this->_('Server cron (recommended)'),
			$this->_('Add a single cron entry that runs every minute:'),
			$cronHelp,
			$this->_('Run due tasks now'),
			htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8'),
			$csrf,
			$this->_('Confirmation'),
			$disabled,
			$this->_('Type RUN to confirm.'),
			$disabled,
			$this->_('Run schedule:run'),
			htmlspecialchars($this->_('Runs only tasks that are due according to their cron expression.'), ENT_QUOTES, 'UTF-8'),
			$this->_('Discovered tasks:'),
			$this->_('Task'),
			$this->_('Cron'),
			$this->_('Due'),
			$this->_('Next run'),
			$rows
		);
	}

	private function handleSchedulingPost(): void
	{
		$action = $this->input->post->text('wc_action');
		if (!str_starts_with($action, 'schedule_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run schedule actions from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'danger', $msg);
			$this->audit('scheduling', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_SCHEDULING)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_SCHEDULING);
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'danger', $msg);
			$this->audit('scheduling', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_SCHEDULING]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		$this->requireProjectAutoload();
		$manager = $this->getScheduleManager();
		if (!$manager) {
			$msg = $this->_('Scheduling engine is not available. Please ensure the Console package is installed and autoloadable.');
			$this->error($msg);
			$this->addPanelMessage('scheduling', 'danger', $msg);
			$this->audit('scheduling', 'error', ['action' => $action, 'reason' => 'engine_unavailable']);
			return;
		}

		try {
			if ($action === 'schedule_run') {
				$ran = $this->runDueScheduledTasks($manager);
				if ($ran === 0) {
					$msg = $this->_('No scheduled tasks are due right now.');
					$this->message($msg);
					$this->addPanelMessage('scheduling', 'primary', $msg);
				} else {
					$msg = sprintf($this->_('Executed %d scheduled task(s).'), $ran);
					$this->message($msg);
					$this->addPanelMessage('scheduling', 'success', $msg);
				}
				$this->audit('scheduling.run', 'success', ['executed' => $ran]);
			}
		} catch (\Throwable $e) {
			$this->reportException('scheduling', 'scheduling', $e, ['action' => $action]);
		}
	}

	/**
	 * @return array<int, array{name: string, expression: string, is_due: bool, next_run: string|null, class: string, path: string}>
	 */
	private function discoverScheduledTasks(\Totoglu\Console\Scheduling\ScheduleManager $manager): array
	{
		$wire = $this->wire();
		$tasks = $manager->getAvailableTasks();
		if (!$tasks) {
			return [];
		}

		$out = [];
		foreach ($tasks as $name => $path) {
			require_once $path;

			$namespace = '';
			$content = (string) @file_get_contents($path);
			if ($content !== '' && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
				$namespace = trim($matches[1]) . '\\';
			}
			$class = $namespace . $name;

			if (!class_exists($class)) {
				continue;
			}

			$instance = new $class($wire);
			$taskBase = $this->console()->getClass('\\Totoglu\\Console\\Scheduling\\Task');
			if (!$taskBase || !$instance instanceof $taskBase) {
				continue;
			}

			$event = $this->console()->newScheduleEvent();
			if (!$event) {
				continue;
			}
			$instance->schedule($event);

			$expression = $event->getExpression();
			$isDue = $event->isDue();

			$nextRun = null;
			if (class_exists(\Cron\CronExpression::class)) {
				try {
					$cron = new \Cron\CronExpression($expression);
					$nextRun = $cron->getNextRunDate('now', 0, true, $this->wire()->config->timezone)->format('Y-m-d H:i');
				} catch (\Throwable) {
					$nextRun = null;
				}
			}

			$out[] = [
				'name' => (string) $name,
				'expression' => (string) $expression,
				'is_due' => (bool) $isDue,
				'next_run' => $nextRun,
				'class' => $class,
				'path' => (string) $path,
			];
		}

		// Sort by name for stable UI
		usort($out, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
		return $out;
	}

	private function runDueScheduledTasks(\Totoglu\Console\Scheduling\ScheduleManager $manager): int
	{
		$wire = $this->wire();
		$tasks = $manager->getAvailableTasks();
		if (!$tasks) {
			return 0;
		}

		$ran = 0;
		foreach ($tasks as $name => $path) {
			require_once $path;

			$namespace = '';
			$content = (string) @file_get_contents($path);
			if ($content !== '' && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
				$namespace = trim($matches[1]) . '\\';
			}
			$class = $namespace . $name;
			if (!class_exists($class)) {
				continue;
			}

			$instance = new $class($wire);
			$taskBase = $this->console()->getClass('\\Totoglu\\Console\\Scheduling\\Task');
			if (!$taskBase || !$instance instanceof $taskBase) {
				continue;
			}

			$event = $this->console()->newScheduleEvent();
			if (!$event) {
				continue;
			}
			$instance->schedule($event);

			if (!$event->isDue()) {
				continue;
			}

			try {
				$instance->handle();
				$ran++;
			} catch (\Throwable $e) {
				$this->audit('scheduling.task', 'error', ['task' => (string) $name, 'message' => $e->getMessage()]);
				$msg = $this->isDebugMode()
					? sprintf($this->_('Task %s failed: %s'), (string) $name, $e->getMessage())
					: sprintf($this->_('Task %s failed. Please check the wire-console log.'), (string) $name);
				$this->error($msg);
			}
		}

		return $ran;
	}

	private function getScheduleManager(): ?\Totoglu\Console\Scheduling\ScheduleManager
	{
		return $this->console()->getScheduleManager();
	}

	private function handleMigrationsPost(): void
	{
		$action = $this->input->post->text('wc_action');
		if (!str_starts_with($action, 'migrate_')) {
			return;
		}

		$user = $this->wire()->user;
		if (!$user || !method_exists($user, 'isSuperuser') || !$user->isSuperuser()) {
			$msg = $this->_('Only superusers can run migration actions from the admin UI.');
			$this->error($msg);
			$this->addPanelMessage('migrations', 'danger', $msg);
			$this->audit('migrations', 'denied', ['action' => $action, 'reason' => 'not_superuser']);
			return;
		}
		if (!$this->userCanViewPermission(self::PERM_MIGRATIONS)) {
			$msg = sprintf($this->_('Permission required: %s'), self::PERM_MIGRATIONS);
			$this->error($msg);
			$this->addPanelMessage('migrations', 'danger', $msg);
			$this->audit('migrations', 'denied', ['action' => $action, 'reason' => 'missing_permission', 'permission' => self::PERM_MIGRATIONS]);
			return;
		}

		$this->wire()->session->CSRF->validate();

		if (!$this->checkRateLimit('migrations', 3)) {
			$msg = $this->_('Please wait a moment before running another action.');
			$this->error($msg);
			$this->addPanelMessage('migrations', 'warning', $msg);
			$this->audit('migrations', 'rate_limited', ['action' => $action]);
			return;
		}

		$confirm = (int) $this->input->post->int('confirm');
		if ($confirm !== 1) {
			$msg = $this->_('Confirmation missing.');
			$this->error($msg);
			$this->addPanelMessage('migrations', 'danger', $msg);
			$this->audit('migrations', 'blocked', ['action' => $action, 'reason' => 'confirm_missing']);
			return;
		}

		$confirmText = strtoupper(trim((string) $this->input->post->text('confirm_text')));

		$this->requireProjectAutoload();
		$engine = $this->getMigrationEngine();
		if (!$engine) {
			$msg = $this->_('Migration engine is not available. Please ensure the Console package is installed and autoloadable.');
			$this->error($msg);
			$this->addPanelMessage('migrations', 'danger', $msg);
			$this->audit('migrations', 'error', ['action' => $action, 'reason' => 'engine_unavailable']);
			return;
		}

		/** @var \Totoglu\Console\Migration\Migrator $migrator */
		$migrator = $engine['migrator'];
		/** @var \Totoglu\Console\Migration\MigrationRepository $repository */
		$repository = $engine['repository'];

		$steps = (int) $this->input->post->int('steps');

		try {
			if ($action === 'migrate_install') {
				$repository->ensureTable();
				$msg = $this->_('Migrations table installed.');
				$this->message($msg);
				$this->addPanelMessage('migrations', 'success', $msg);
				$this->audit('migrations.install_table', 'success');
				return;
			}

			// Require the tracking table to be installed before running actions.
			if (!$repository->tableExists()) {
				$msg = $this->_('Migrations table is not installed. Install it first.');
				$this->error($msg);
				$this->addPanelMessage('migrations', 'danger', $msg);
				$this->audit('migrations', 'blocked', ['action' => $action, 'reason' => 'table_missing']);
				return;
			}

			if ($action === 'migrate_run') {
				if ($confirmText !== 'RUN') {
					$msg = $this->_('Type RUN to confirm.');
					$this->error($msg);
					$this->addPanelMessage('migrations', 'danger', $msg);
					$this->audit('migrations.run', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				$result = $migrator->runPending($steps);
				$applied = $result['applied'] ?? [];
				$errors = $result['errors'] ?? [];

				if (!empty($applied)) {
					$msg = sprintf($this->_('Applied migrations: %d'), count($applied));
					$this->message($msg);
					$this->addPanelMessage('migrations', 'success', $msg);
				} else {
					$msg = $this->_('No pending migrations to apply.');
					$this->message($msg);
					$this->addPanelMessage('migrations', 'primary', $msg);
				}
				$this->audit('migrations.run', empty($errors) ? 'success' : 'error', [
					'steps' => $steps,
					'applied_count' => count($applied),
					'error_count' => count($errors),
				]);
				foreach ($errors as $err) {
					$this->error($err);
					$this->addPanelMessage('migrations', 'danger', (string) $err);
				}
			}

			if ($action === 'migrate_rollback') {
				if ($confirmText !== 'ROLLBACK') {
					$msg = $this->_('Type ROLLBACK to confirm.');
					$this->error($msg);
					$this->addPanelMessage('migrations', 'danger', $msg);
					$this->audit('migrations.rollback', 'blocked', ['reason' => 'confirm_text_mismatch']);
					return;
				}

				$result = $steps > 0 ? $migrator->rollbackSteps($steps) : $migrator->rollbackLastBatch();
				$rolledBack = $result['rolledBack'] ?? [];
				$errors = $result['errors'] ?? [];

				if (!empty($rolledBack)) {
					$msg = sprintf($this->_('Rolled back migrations: %d'), count($rolledBack));
					$this->message($msg);
					$this->addPanelMessage('migrations', 'success', $msg);
				} else {
					$msg = $this->_('Nothing to rollback.');
					$this->message($msg);
					$this->addPanelMessage('migrations', 'primary', $msg);
				}
				$this->audit('migrations.rollback', empty($errors) ? 'success' : 'error', [
					'steps' => $steps,
					'rolled_back_count' => count($rolledBack),
					'error_count' => count($errors),
				]);
				foreach ($errors as $err) {
					$this->error($err);
					$this->addPanelMessage('migrations', 'danger', (string) $err);
				}
			}
		} catch (\Throwable $e) {
			$this->reportException('migrations', 'migrations', $e, ['action' => $action]);
		}
	}

	private function pageUrlWithTab(string $tab): string
	{
		$url = $this->page ? $this->page->url : $this->config->urls->admin;
		$tab = $this->wire()->sanitizer->name($tab);
		return rtrim($url, '/') . '/?tab=' . rawurlencode($tab);
	}

	private function requireProjectAutoload(): bool
	{
		return $this->console()->ensureAutoload();
	}

	/**
	 * @return array{migrator: \Totoglu\Console\Migration\Migrator, repository: \Totoglu\Console\Migration\MigrationRepository}|null
	 */
	private function getMigrationEngine(): ?array
	{
		return $this->console()->getMigrationEngine();
	}

	private function renderOverviewCard(string $boostStatus, string $testStatus): string
	{
		$configureUrl = $this->config->urls->admin . 'module/edit?name=WireConsole';

		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<div class="uk-alert uk-alert-primary" uk-alert>
					<p>%s</p>
				</div>

				<table class="uk-table uk-table-divider uk-table-small">
					<tbody>
						<tr><td>%s</td><td><code>%s</code></td></tr>
						<tr><td>%s</td><td>%s</td></tr>
						<tr><td>%s</td><td>%s</td></tr>
						<tr><td>%s</td><td><code>%s</code></td></tr>
					</tbody>
				</table>

				<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-top">
					<div class="uk-text-small uk-text-muted">%s</div>
					<div>
						<a class="uk-button uk-button-default" href="%s">%s</a>
						<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
					</div>
				</div>
			</div>
			',
			$this->_('Status'),
			$this->_('Access permission for this module: wire-console. Users without this permission cannot access this page.'),
			$this->_('Console package'),
			htmlspecialchars(self::PACKAGE_CONSOLE, ENT_QUOTES, 'UTF-8'),
			$this->_('Boost package'),
			htmlspecialchars($boostStatus, ENT_QUOTES, 'UTF-8'),
			$this->_('Testing'),
			htmlspecialchars($testStatus, ENT_QUOTES, 'UTF-8'),
			$this->_('Permission'),
			'wire-console',
			$this->_('Use the WireConsole module configuration page for package status and installation guidance.'),
			htmlspecialchars($configureUrl, ENT_QUOTES, 'UTF-8'),
			$this->_('WireConsole configuration'),
			htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
			$this->_('Repository / Documentation')
		);
	}

	private function renderFeatureCard(string $title, string $description, string $roadmap, string $repoUrl): string
	{
		return sprintf(
			'
			<div class="uk-card uk-card-default uk-card-body">
				<h3 class="uk-h3">%s</h3>
				<p class="uk-text-small uk-text-muted">%s</p>
				<div class="uk-alert uk-alert-warning" uk-alert><p>%s</p></div>
				<p class="uk-text-small uk-text-muted">%s</p>
				<p class="uk-margin-top">
					<a class="uk-button uk-button-default" href="%s" target="_blank" rel="noopener noreferrer">%s</a>
				</p>
			</div>
			',
			htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
			$this->_('This section is currently in scaffold mode (UI/UX skeleton). Actions will become available once the Console package API/CLI integration is implemented.'),
			htmlspecialchars($roadmap, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8'),
			$this->_('Documentation / Repo')
		);
	}
}
