<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitSelect;

/**
 * Standalone Scheduling tab panel component for ProcessWireConsole.
 */
class ConsoleSchedulingPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$tasks = $runtime->getScheduledTasks();
		$isSuper = $runtime->isSuperuser();

		if ($tasks === []) {
			$listHtml = (string) (new KitEmptyState())
				->title($this->_('No Scheduled Tasks Found'))
				->description($this->_('Create a cron task in site/schedule/ and configure your system crontab to run vendor/bin/wire schedule:run every minute.'))
				->icon('clock-o')
				->cliCommand('php vendor/bin/wire make:task PruneExpiredLogs');
		} else {
			$trs = [];
			foreach ($tasks as $task) {
				$nameSafe = $sanitizer->entities($task['name']);
				$descSafe = $sanitizer->entities($task['description']);
				$exprSafe = $sanitizer->entities($task['expression']);
				$nextSafe = $sanitizer->entities($task['nextRun']);
				$lastSafe = $sanitizer->entities((string) ($task['lastTriggered'] ?? '—'));
				$dueBadge = (string) (new KitBadge())
					->label($task['isDue'] ? $this->_('Due Now') : $this->_('Scheduled'))
					->variant($task['isDue'] ? 'warning' : 'success')
					->showDot(true);
				$inspectBtn = $this->renderInspectTrigger('task', $task['name']);

				$trs[] = <<<HTML
				<tr>
					<td><strong>{$nameSafe}</strong><div class="wk-cell-sub">{$descSafe}</div></td>
					<td><code class="wk-code-inline">{$exprSafe}</code></td>
					<td>{$dueBadge}</td>
					<td class="wk-tabular">{$nextSafe}</td>
					<td class="wk-tabular">{$lastSafe}</td>
					<td class="wk-text-right">{$inspectBtn}</td>
				</tr>
				HTML;
			}
			$listHtml = $this->renderDataTable([
				$this->_('Task'),
				$this->_('Cron Expression'),
				$this->_('Status'),
				$this->_('Next Run'),
				$this->_('Last Triggered'),
				['label' => $this->_('Source'), 'class' => 'wk-text-right'],
			], $trs);
		}

		$scheduleOptions = ['due' => $this->_('Only Tasks Currently Due')];
		foreach ($tasks as $task) {
			$tName = (string) ($task['name'] ?? '');
			if ($tName !== '') {
				$scheduleOptions[$tName] = sprintf($this->_('Force Run: %s'), $tName);
			}
		}

		$triggerCard = (new KitActionCard())
			->icon('clock-o')
			->title($this->_('Trigger Scheduled Tasks'))
			->cliCommand('schedule:run')
			->description($this->_('Run all tasks that are due at the current minute, or force-run a specific task immediately.'))
			->confirm('confirm_scheduling_run', 'RUN')
			->actionMethod('runScheduledTasks')
			->button($this->_('Execute Tasks'), 'play')
			->controlsHtml((string) (new KitSelect())->name('schedule_target')->size('sm')->options($scheduleOptions))
			->disabled($tasks === [])
			->variant('primary');

		$scaffoldTaskCard = KitActionCard::scaffold(
			$this->_('Scaffold Task'),
			$this->_('Generates a scheduled cron task class in site/schedule/.'),
			'make_task_name',
			'PruneExpiredLogs',
			'confirm_scheduling_make',
			'makeTaskClass',
			$this->_('Create Task'),
			'file-code-o',
			'make:task'
		);

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$triggerCard, $scaffoldTaskCard])
			: $this->renderSuperuserRequiredBanner();

		$taskCount = count($tasks);
		$panelHtml = (string) (new KitCard())
			->icon('clock-o')
			->title($this->_('Scheduled Tasks'))
			->subtitle(sprintf('%s %d', $this->_('Discovered Tasks:'), $taskCount))
			->body($listHtml);

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
