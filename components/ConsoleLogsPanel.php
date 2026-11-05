<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitButton;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitCodePreview;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitInput;
use Htmx\Ui\KitSelect;
use Htmx\Ui\KitTabs;
use Totoglu\WireConsole\ConsoleRuntime;

require_once __DIR__ . '/ConsolePanelComponent.php';

/**
 * ConsoleLogsPanel
 *
 * Dedicated HTMX v4 component for the Logs & Audit tab of ProcessWireConsole.
 */
class ConsoleLogsPanel extends ConsolePanelComponent
{
	public string $activeLogChannel = ConsoleRuntime::LOG_CHANNEL;
	public string $logSearch = '';
	public int $logLimit = 50;

	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$channels = $this->runtime()->getLogChannels();
		$isSuper = $this->runtime()->isSuperuser();
		$url = $this->requestUrl();

		// Ensure activeLogChannel exists or fallback to first available / wire-console-audit
		$channelNames = array_column($channels, 'name');
		if ($this->activeLogChannel === '' || ($channelNames !== [] && !in_array($this->activeLogChannel, $channelNames, true))) {
			$this->activeLogChannel = in_array(ConsoleRuntime::LOG_CHANNEL, $channelNames, true)
				? ConsoleRuntime::LOG_CHANNEL
				: ($channelNames[0] ?? ConsoleRuntime::LOG_CHANNEL);
		}

		$channelsNav = '';
		if ($channels !== []) {
			$tabs = KitTabs::make()
				->active($this->activeLogChannel)
				->variant('segmented')
				->ariaLabel($this->_('Log Channels'))
				->htmxSwitch($url, '#' . $this->workspaceId, 'querySelectLogChannel', 'channel');

			foreach ($channels as $ch) {
				$tabs->addTab(
					$ch['name'],
					$ch['name'],
					$ch['isAudit'] ? 'shield' : 'file-text-o',
					$ch['sizeFormatted']
				);
			}
			$channelsNav = $tabs->render();
		}

		$clampedLimit = in_array($this->logLimit, [25, 50, 100, 200], true) ? $this->logLimit : 50;

		$searchInput = (string) (new KitInput())
			->search($this->logSearch)
			->name('log_search')
			->size('sm')
			->placeholder($this->_('Filter log entries by keyword, user, or action...'))
			->autocomplete('off')
			->spellcheck(false)
			->clearable(true)
			->setAttribute('aria-label', $this->_('Filter log entries'))
			->hxAction($url, 'POST')
			->hxTrigger('input changed delay:280ms, search')
			->hxTarget('#' . $this->workspaceId)
			->hxSwap('outerMorph')
			->hxVals(['__htmx_action' => 'queryFilterLogs']);

		$limitSelect = (string) (new KitSelect())
			->name('log_limit')
			->value($clampedLimit)
			->size('sm')
			->options([
				25 => '25 ' . $this->_('entries'),
				50 => '50 ' . $this->_('entries'),
				100 => '100 ' . $this->_('entries'),
				200 => '200 ' . $this->_('entries'),
			])
			->setAttribute('aria-label', $this->_('Entries limit'))
			->hxAction($url, 'POST')
			->hxTrigger('change')
			->hxTarget('#' . $this->workspaceId)
			->hxSwap('outerMorph')
			->hxVals(['__htmx_action' => 'queryFilterLogs']);

		$filterBtn = KitButton::make()
			->label($this->_('Filter'))
			->icon('filter')
			->variant('neutral')
			->size('xs')
			->submit(true)
			->nameValue('__htmx_action', 'queryFilterLogs')
			->render();

		$resetBtn = $this->logSearch !== ''
			? KitButton::make()
				->label($this->_('Clear'))
				->icon('times')
				->variant('ghost')
				->size('xs')
				->hxAction($url, 'POST')
				->hxTarget('#' . $this->workspaceId)
				->hxSwap('outerMorph')
				->hxVals(['__htmx_action' => 'queryResetLogFilter'])
				->render()
			: '';

		$filterBarHtml = <<<HTML
		<div class="wk-toolbar-row">
			{$searchInput}
			{$limitSelect}
			{$resetBtn}
			{$filterBtn}
		</div>
		HTML;

		$entries = $this->runtime()->readLogEntries($this->activeLogChannel, $clampedLimit, $this->logSearch);
		$activeChannelSafe = $sanitizer->entities($this->activeLogChannel);

		$entriesHtml = '';
		if ($entries === []) {
			$entriesHtml = KitEmptyState::make([
				'title' => sprintf($this->_('No Entries in "%s"'), $this->activeLogChannel),
				'description' => $this->_('Log entries will appear here as actions and events are recorded by ProcessWire.'),
				'icon' => 'list-alt',
				'cliCommand' => 'php vendor/bin/wire log:tail ' . $this->activeLogChannel,
			])->render();
		} else {
			$trs = [];
			foreach ($entries as $entry) {
				$dateSafe = $sanitizer->entities($entry['date']);
				$userSafe = $sanitizer->entities($entry['user']);
				$audit = $entry['audit'] ?? null;

				if (is_array($audit)) {
					$statusStr = (string) ($audit['status'] ?? '');
					$badgeVariant = match (true) {
						$statusStr === 'ok' => 'success',
						$statusStr === 'error' => 'danger',
						str_starts_with($statusStr, 'denied') || $statusStr === 'rate_limited' => 'warning',
						default => 'primary',
					};
					$statusBadge = KitBadge::make([
						'label' => $statusStr !== '' ? $statusStr : 'event',
						'variant' => $badgeVariant,
					])->render();
					$actionSafe = $sanitizer->entities((string) ($audit['action'] ?? ''));
					$ipSafe = $sanitizer->entities((string) ($audit['ip'] ?? ''));
					$ctxFormatted = (string) ($audit['contextFormatted'] ?? '');

					$ctxHtml = $ctxFormatted !== ''
						? KitCodePreview::make()
							->code($ctxFormatted)
							->collapsible(true, $this->_('Context JSON'))
							->render()
						: '';

					$payloadCellHtml = <<<HTML
					<div class="wk-chip-list">
						{$statusBadge}
						<code class="wk-code-inline">{$actionSafe}</code>
						<span class="wk-chip"><i class="fa fa-globe" aria-hidden="true"></i> <code>{$ipSafe}</code></span>
					</div>
					{$ctxHtml}
					HTML;
				} else {
					$textSafe = $sanitizer->entities($entry['text']);
					$payloadCellHtml = "<code class=\"wk-code-inline\">{$textSafe}</code>";
				}

				$trs[] = <<<HTML
				<tr>
					<td class="wk-num">{$dateSafe}</td>
					<td><span class="wk-chip">{$userSafe}</span></td>
					<td>{$payloadCellHtml}</td>
				</tr>
				HTML;
			}
			$entriesHtml = $this->renderDataTable([
				$this->_('Timestamp'),
				$this->_('User'),
				$this->_('Message / Payload'),
			], $trs);
		}

		$activeChannelBytes = 0;
		foreach ($channels as $ch) {
			if ($ch['name'] === $this->activeLogChannel) {
				$activeChannelBytes = (int) $ch['sizeBytes'];
				break;
			}
		}

		$clearOneCard = KitActionCard::make()
			->icon('trash')
			->title(sprintf($this->_('Clear "%s" Channel'), $this->activeLogChannel))
			->cliCommand('log:clear')
			->description(sprintf($this->_('Permanently deletes all entries in the currently active log channel: %s'), $this->activeLogChannel))
			->confirm('confirm_logs_clear', 'CLEAR')
			->actionMethod('clearLogChannel')
			->button($this->_('Clear Log'), 'trash')
			->controlsHtml(<<<HTML
			<input type="hidden" name="log_channel_target" value="{$activeChannelSafe}">
			<span class="wk-action-card__summary">{$this->_('Target channel:')} <code>{$activeChannelSafe}</code></span>
			HTML)
			->disabled($activeChannelBytes === 0)
			->variant('danger');

		$clearAllCard = KitActionCard::make()
			->icon('eraser')
			->title($this->_('Clear All Log Channels'))
			->cliCommand('log:clear-all')
			->description($this->_('Empties every ProcessWire .txt log file in site/assets/logs/ at once.'))
			->confirm('confirm_logs_clear_all', 'CLEARALL')
			->actionMethod('clearAllLogChannels')
			->button($this->_('Clear All Logs'), 'eraser')
			->disabled($channels === [])
			->variant('danger');

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$clearOneCard, $clearAllCard])
			: $this->renderSuperuserRequiredBanner();

		$channelCount = count($channels);

		$panelHtml = KitCard::make()
			->title($this->_('System & Audit Logs'))
			->subtitle(sprintf(
				'%s %s · %s %d',
				$this->_('Active Channel:'),
				$this->activeLogChannel,
				$this->_('Available Channels:'),
				$channelCount
			))
			->icon('list-alt')
			->body("{$channelsNav}\n{$filterBarHtml}\n{$entriesHtml}")
			->render();

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
