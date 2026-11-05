<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Component\Concerns\HandlesWorkspaceMutations;
use Htmx\Ui\ConsoleOnboardingCard;
use Htmx\Ui\ConsoleVersionStrip;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitButton;
use Htmx\Ui\KitCodePreview;
use Htmx\Ui\KitConfirmModal;
use Htmx\Ui\KitNotice;
use Htmx\Ui\KitTabs;
use Totoglu\Htmx\Component;
use Totoglu\WireConsole\ConsoleRuntime;

require_once dirname(__DIR__) . '/src/ConsoleRuntime.php';
require_once dirname(__DIR__) . '/ui/ConsoleVersionStrip.php';
require_once dirname(__DIR__) . '/ui/ConsoleOnboardingCard.php';
require_once __DIR__ . '/Concerns/HandlesWorkspaceMutations.php';
require_once __DIR__ . '/ConsolePanelComponent.php';
require_once __DIR__ . '/ConsoleOverviewPanel.php';
require_once __DIR__ . '/ConsoleMigrationsPanel.php';
require_once __DIR__ . '/ConsoleQueuesPanel.php';
require_once __DIR__ . '/ConsoleSeedingPanel.php';
require_once __DIR__ . '/ConsoleSchedulingPanel.php';
require_once __DIR__ . '/ConsoleBackupPanel.php';
require_once __DIR__ . '/ConsoleTestingPanel.php';
require_once __DIR__ . '/ConsoleBoostPanel.php';
require_once __DIR__ . '/ConsoleLogsPanel.php';

/**
 * ConsoleWorkspace
 *
 * Stateful HTMX v4 orchestrator component rendering the ProcessWire Console dashboard:
 * - Package readiness & Composer onboarding banner (prominent when packages are missing)
 * - Accessible tab navigation via `KitTabs`
 * - Dedicated `Console*Panel` components for Overview, Migrations, Queues, Seeding,
 *   Scheduling, Backups, Testing, Boost & AI, and Logs & Audit
 * - Superuser-gated mutation actions with `KitActionCard` & `KitConfirmModal`
 */
class ConsoleWorkspace extends BaseWorkspace
{
	use HandlesWorkspaceMutations;

	public string $activeTab = 'overview';
	public string $activeLogChannel = ConsoleRuntime::LOG_CHANNEL;
	public string $logSearch = '';
	public int $logLimit = 50;
	public string $inspectedCategory = '';
	public string $inspectedIdentifier = '';

	protected ?ConsoleRuntime $runtimeInstance = null;

	public function workspaceId(): string
	{
		return 'console';
	}

	public function mount(string $activeTab = ''): void
	{
		$this->initWorkspaceIdentity();
		$targetTab = $activeTab !== '' ? $activeTab : $this->activeTab;
		$this->activeTab = $this->resolvePermittedTab($targetTab);
	}

	public function hydrate(): void
	{
		$this->initWorkspaceIdentity();
		parent::hydrate();
		$this->initWorkspaceIdentity();
		$this->activeTab = $this->resolvePermittedTab($this->activeTab);
	}

	protected function initWorkspaceIdentity(): void
	{
		$this->id = 'hxc_wc_workspace';
		$this->setStateKey('hx__state__hxc_wc_workspace');
	}

	/**
	 * Switch the active workspace tab via read-only HTMX GET/POST request.
	 */
	public function querySwitchTab(string $tab = 'overview'): void
	{
		$this->activeTab = $this->resolvePermittedTab($tab);
		$this->inspectedCategory = '';
		$this->inspectedIdentifier = '';
	}

	/**
	 * Open a read-only file or skill preview drawer inside the current workspace tab.
	 */
	public function queryInspectFile(string $category = '', string $identifier = ''): void
	{
		$this->activeTab = $this->resolvePermittedTab($this->activeTab);
		$cleanCategory = strtolower(trim($category));
		$cleanId = trim($identifier);

		if (!$this->runtime()->userCanInspectCategory($cleanCategory)) {
			$this->inspectedCategory = '';
			$this->inspectedIdentifier = '';
			$this->runtime()->audit('file.inspect', 'denied_rbac', [
				'category' => $cleanCategory,
				'identifier' => $cleanId,
			]);
			$this->setNotice(
				'danger',
				$this->_('Permission Denied'),
				$this->_('You do not have permission to inspect this file.')
			);

			return;
		}

		$this->inspectedCategory = $cleanCategory;
		$this->inspectedIdentifier = $cleanId;
	}

	/**
	 * Close the read-only file inspector drawer.
	 */
	public function queryCloseInspect(): void
	{
		$this->inspectedCategory = '';
		$this->inspectedIdentifier = '';
	}

	/**
	 * Switch the selected log channel inside the Logs & Audit tab.
	 */
	public function querySelectLogChannel(string $channel = ConsoleRuntime::LOG_CHANNEL): void
	{
		$this->activeTab = $this->resolvePermittedTab('logs');
		$clean = (string) ($this->wire()->sanitizer?->name($channel) ?? '');
		$this->activeLogChannel = $clean !== '' ? $clean : ConsoleRuntime::LOG_CHANNEL;
	}

	/**
	 * Apply search keyword and entry limit filters inside the Logs & Audit tab.
	 */
	public function queryFilterLogs(): void
	{
		$this->activeTab = $this->resolvePermittedTab('logs');
		$input = $this->wire()->input;
		$rawSearch = $input->post('log_search') ?? $input->get('log_search') ?? $this->logSearch;
		$rawLimit = $input->post('log_limit') ?? $input->get('log_limit') ?? $this->logLimit;
		$search = trim((string) $rawSearch);
		$limit = (int) $rawLimit;
		$this->logSearch = mb_substr($search, 0, 120);
		$this->logLimit = in_array($limit, [25, 50, 100, 200], true) ? $limit : 50;
	}

	/**
	 * Reset the log search filter inside the Logs & Audit tab.
	 */
	public function queryResetLogFilter(): void
	{
		$this->activeTab = $this->resolvePermittedTab('logs');
		$this->logSearch = '';
	}

	/**
	 * Refresh the current workspace tab state without executing a mutation.
	 */
	public function queryRefresh(): void
	{
		$this->activeTab = $this->resolvePermittedTab($this->activeTab);
		$this->setNotice(
			'primary',
			$this->_('Refreshed'),
			sprintf($this->_('Telemetry and runtime state updated at %s.'), date('H:i:s'))
		);
	}

	// =========================================================================
	// RENDERING (HTMX v4 Server-Driven Component View)
	// =========================================================================

	public function render(): string
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$tabs = $runtime->getAccessibleTabs();
		$packages = $runtime->getPackagesStatus();

		if ($tabs === []) {
			$denied = KitNotice::make([
				'message' => $this->_('You do not have permission to view any Console sections.'),
				'variant' => 'danger',
				'title' => $this->_('Access Denied'),
			]);

			return "<div id=\"{$this->id}\" class=\"wc-workspace\">{$denied->render()}</div>";
		}

		$this->activeTab = $this->resolvePermittedTab($this->activeTab);
		$activeTabLabel = $sanitizer->entities1(
			html_entity_decode($tabs[$this->activeTab]['label'] ?? '', ENT_QUOTES, 'UTF-8')
		);
		$url = $sanitizer->entities($this->requestUrl());
		$id = $sanitizer->entities($this->id);

		// Render header, tab bar, process/notification centers & persistent runtime version strip
		$headerHtml = $this->renderHeader();
		$versionStripHtml = ConsoleVersionStrip::make(['versions' => $runtime->getVersionMatrix()])->render();
		$navHtml = $this->renderTabNav($tabs);
		$inspectorHtml = $this->renderInspectedFileDrawer();
		$processHtml = $this->renderProcessCenter();
		$noticeHtml = $this->renderNotificationCenter();

		// When trk/processwire-console is missing, always show the setup onboarding card at the top
		$onboardingBanner = !$packages['console']['installed']
			? ConsoleOnboardingCard::make(['packages' => $packages, 'prominent' => true])->render()
			: '';

		$panelComponent = $this->resolveActivePanelComponent();
		['actions' => $actionsHtml, 'panel' => $panelHtml] = $panelComponent->renderSection();

		$actionsDeckHtml = $actionsHtml !== ''
			? '<section class="wk-action-deck" aria-label="' . $sanitizer->entities($this->_('Quick Actions')) . '">' . $actionsHtml . '</section>'
			: '';
		$confirmDialogHtml = KitConfirmModal::make()->render();

		return <<<HTML
		<div id="{$id}" class="wk-workspace wc-workspace" data-wk-active-tab="{$sanitizer->entities($this->activeTab)}" data-wk-active-tab-label="{$activeTabLabel}" data-wc-active-tab="{$sanitizer->entities($this->activeTab)}" data-wc-active-tab-label="{$activeTabLabel}">
			<form class="wc-workspace__form"
				autocomplete="off"
				hx-action="{$url}"
				hx-method="post"
				hx-target="#{$id}"
				hx-swap="outerMorph"
				hx-status:422="swap:outerMorph target:#{$id}"
				hx-disable="#{$id} button">
				{$this->renderStatePayload()}
				{$headerHtml}
				{$navHtml}
				{$processHtml}
				{$noticeHtml}
				{$versionStripHtml}
				{$inspectorHtml}
				{$onboardingBanner}
				{$actionsDeckHtml}
				<main class="wk-panel" role="tabpanel">
					{$panelHtml}
				</main>
				{$confirmDialogHtml}
			</form>
		</div>
		HTML;
	}

	/**
	 * Instantiate and wire the dedicated ConsolePanelComponent for the active tab.
	 */
	protected function resolveActivePanelComponent(): ConsolePanelComponent
	{
		$class = match ($this->activeTab) {
			'migrations' => ConsoleMigrationsPanel::class,
			'queues' => ConsoleQueuesPanel::class,
			'seeding' => ConsoleSeedingPanel::class,
			'scheduling' => ConsoleSchedulingPanel::class,
			'backup' => ConsoleBackupPanel::class,
			'testing' => ConsoleTestingPanel::class,
			'boost' => ConsoleBoostPanel::class,
			'logs' => ConsoleLogsPanel::class,
			default => ConsoleOverviewPanel::class,
		};

		/** @var ConsolePanelComponent $panel */
		$panel = new $class();
		$wire = $this->wire();
		if ($wire !== null) {
			$panel->setWire($wire);
		}
		$panel->setRuntime($this->runtime());
		$panel->workspaceId = $this->id;

		if ($panel instanceof ConsoleLogsPanel) {
			$panel->activeLogChannel = $this->activeLogChannel;
			$panel->logSearch = $this->logSearch;
			$panel->logLimit = $this->logLimit;
		}

		return $panel;
	}

	/**
	 * Render the top workspace header bar with role badge and quick refresh.
	 */
	protected function renderHeader(): string
	{
		$url = $this->requestUrl();
		$id = $this->id;

		$roleLabel = $this->runtime()->isSuperuser()
			? KitBadge::make(['label' => $this->_('Superuser Mode'), 'variant' => 'primary', 'showDot' => false])
			: KitBadge::make(['label' => $this->_('Read-only View'), 'variant' => 'muted', 'showDot' => false]);

		$refreshBtn = KitButton::make()
			->label($this->_('Refresh'))
			->icon('refresh')
			->variant('neutral')
			->size('sm')
			->addClass('wc-btn-refresh')
			->hxAction($url)
			->hxMethod('post')
			->hxTarget('#' . $id)
			->hxSwap('outerMorph')
			->hxVals(['__htmx_action' => 'queryRefresh']);

		return $this->renderWorkspaceHeader(
			$this->_('Console'),
			$this->_('Monitor system health, background tasks, scheduled jobs, database backups, and activity logs.'),
			'terminal',
			[$roleLabel],
			[$refreshBtn]
		);
	}

	/**
	 * Render the horizontal tab bar for accessible sections via `KitTabs`.
	 *
	 * @param array<string, array{label: string, icon: string, permission: string, description: string}> $tabs
	 */
	protected function renderTabNav(array $tabs): string
	{
		$kitTabs = KitTabs::make()
			->active($this->activeTab)
			->ariaLabel($this->_('Console sections'))
			->htmxSwitch($this->requestUrl(), '#' . $this->id, 'querySwitchTab', 'tab');

		foreach ($tabs as $key => $meta) {
			$kitTabs->addTab($key, $meta['label'], $meta['icon']);
		}

		return $kitTabs->render();
	}

	/**
	 * Render any flash notice triggered by the most recent action via `KitNotice`.
	 */
	protected function renderFlashNotice(): string
	{
		if ($this->flashNotice === null) {
			return '';
		}

		return KitNotice::make([
			'message' => $this->flashNotice['message'],
			'variant' => $this->flashNotice['variant'],
			'title' => $this->flashNotice['title'],
			'details' => $this->flashNotice['details'],
		])->render();
	}

	/**
	 * Render a read-only source file or skill inspector drawer when an item is selected via `KitCodePreview`.
	 */
	protected function renderInspectedFileDrawer(): string
	{
		if ($this->inspectedCategory === '' || $this->inspectedIdentifier === '') {
			return '';
		}

		if (!$this->runtime()->userCanInspectCategory($this->inspectedCategory)) {
			$deniedCategory = $this->inspectedCategory;
			$deniedId = $this->inspectedIdentifier;
			$this->inspectedCategory = '';
			$this->inspectedIdentifier = '';
			$this->runtime()->audit('file.inspect', 'denied_rbac', [
				'category' => $deniedCategory,
				'identifier' => $deniedId,
			]);
			if ($this->flashNotice === null) {
				$this->setNotice(
					'danger',
					$this->_('Permission Denied'),
					$this->_('You do not have permission to inspect this file.')
				);
			}

			return '';
		}

		$inspected = $this->runtime()->inspectProjectFile($this->inspectedCategory, $this->inspectedIdentifier);
		if ($inspected === null) {
			return '';
		}

		$linesCount = (int) $inspected['lineCount'];
		$sizeFormatted = (string) $inspected['sizeFormatted'];

		return KitCodePreview::make()
			->asCard(true)
			->title((string) $inspected['relativePath'])
			->subtitle($this->_('Read-only source preview'))
			->badgeLabel(sprintf($this->_('%d lines · %s'), $linesCount, $sizeFormatted))
			->code((string) $inspected['content'])
			->closeHtmx($this->requestUrl(), '#' . $this->id, 'queryCloseInspect')
			->render();
	}

	// =========================================================================
	// INTERNAL HELPERS & SECURITY GUARDS
	// =========================================================================

	/**
	 * Enforce permission, superuser status, typed confirmation token, and session rate limit.
	 */
	protected function guardMutation(
		string $requiredPermission,
		string $actionKey,
		string $expectedConfirmationWord,
		int $cooldownSeconds
	): bool {
		$runtime = $this->runtime();

		if (!$runtime->userCanView($requiredPermission) || !$runtime->isSuperuser()) {
			$runtime->audit($actionKey, 'denied_rbac', ['permission' => $requiredPermission]);
			$this->setNotice(
				'danger',
				$this->_('Permission Denied'),
				$this->_('Superuser privileges and section permission are required to execute this action.')
			);

			return false;
		}

		$inputField = 'confirm_' . $actionKey;
		$submittedWord = strtoupper(trim((string) ($this->wire()->input->post($inputField) ?? '')));
		if ($submittedWord !== strtoupper($expectedConfirmationWord)) {
			$runtime->audit($actionKey, 'denied_confirmation', [
				'expected' => $expectedConfirmationWord,
				'submitted' => $submittedWord,
			]);
			$this->setNotice(
				'warning',
				$this->_('Confirmation Required'),
				sprintf($this->_('Please type "%s" in the confirmation field to proceed.'), $expectedConfirmationWord)
			);

			return false;
		}

		$rate = $runtime->checkRateLimit($actionKey, $cooldownSeconds);
		if (!$rate['allowed']) {
			$runtime->audit($actionKey, 'rate_limited', ['retryAfter' => $rate['retryAfter']]);
			$this->setNotice(
				'warning',
				$this->_('Cooldown Active'),
				sprintf($this->_('Please wait %d second(s) before triggering this action again.'), $rate['retryAfter'])
			);

			return false;
		}

		return true;
	}

	/**
	 * Resolve a requested tab slug against the user's permitted tabs.
	 */
	protected function resolvePermittedTab(string $requestedTab): string
	{
		$clean = strtolower(trim($requestedTab));
		$tabs = $this->runtime()->getAccessibleTabs();

		if (isset($tabs[$clean])) {
			return $clean;
		}

		$firstKey = array_key_first($tabs);

		return is_string($firstKey) ? $firstKey : 'overview';
	}

	/**
	 * Record a flash notice for rendering in the current response cycle.
	 */
	protected function setNotice(string $variant, string $title, string $message, string $details = ''): void
	{
		$this->flashNotice = [
			'variant' => $variant,
			'title' => $title,
			'message' => $message,
			'details' => $details,
		];
	}

	/**
	 * Lazily instantiate or return the wired ConsoleRuntime service.
	 */
	public function runtime(): ConsoleRuntime
	{
		return $this->runtimeInstance ??= ConsoleRuntime::make($this->wire());
	}
}
