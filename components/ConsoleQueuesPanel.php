<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitCodePreview;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitInput;
use Htmx\Ui\KitSelect;

/**
 * Standalone Queues tab panel component for ProcessWireConsole.
 */
class ConsoleQueuesPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$status = $runtime->getQueuesStatus(50);
		$isSuper = $runtime->isSuperuser();

		$tablesBadge = (new KitBadge())
			->label($status['tablesExist'] ? $this->_('Queue Tables Ready') : $this->_('Tables Not Installed'))
			->variant($status['tablesExist'] ? 'success' : 'warning')
			->showDot(true);

		if ($status['discoveredQueues'] === []) {
			$discoveredHtml = (string) (new KitEmptyState())
				->title($this->_('No Queue Job Classes Discovered'))
				->description($this->_('Generate a background job handler in site/queue/ using the CLI command below.'))
				->icon('refresh')
				->cliCommand('php vendor/bin/wire make:queue SendWelcomeEmail');
		} else {
			$chips = [];
			foreach ($status['discoveredQueues'] as $q) {
				$chips[] = $this->renderInspectTrigger('queue', $q['name'], $q['name']);
			}
			$discoveredHtml = '<div class="wk-chip-list">' . implode('', $chips) . '</div>';
		}

		$pendingJobs = isset($status['pendingJobs']) && is_array($status['pendingJobs']) ? $status['pendingJobs'] : [];
		if ($pendingJobs === []) {
			$pendingHtml = '<p class="wk-muted-note">' . $sanitizer->entities($this->_('Zero pending jobs in queue_jobs.')) . '</p>';
		} else {
			$trs = [];
			foreach ($pendingJobs as $job) {
				$idSafe = (int) $job['id'];
				$queueSafe = $sanitizer->entities($job['queue']);
				$classSafe = $sanitizer->entities($job['jobClass']);
				$attemptsSafe = (int) $job['attempts'];
				$availSafe = $sanitizer->entities($job['availableAt']);
				$payloadDetails = (string) (new KitCodePreview())
					->summary($this->_('Payload JSON'))
					->code((string) ($job['payloadFormatted'] ?? ''));

				$statusVariant = match ($job['status'] ?? 'Ready') {
					'Reserved' => 'primary',
					'Delayed' => 'warning',
					default => 'success',
				};
				$statusLabel = match ($job['status'] ?? 'Ready') {
					'Reserved' => $this->_('Reserved'),
					'Delayed' => $this->_('Delayed'),
					default => $this->_('Ready'),
				};
				$statusBadge = (string) (new KitBadge())
					->label($statusLabel)
					->variant($statusVariant)
					->showDot(true);

				$trs[] = <<<HTML
				<tr>
					<td class="wk-tabular">#{$idSafe}</td>
					<td><span class="wk-chip">{$queueSafe}</span></td>
					<td><code class="wk-code-inline">{$classSafe}</code></td>
					<td class="wk-tabular">{$attemptsSafe}</td>
					<td>{$statusBadge}</td>
					<td class="wk-tabular">{$availSafe}</td>
					<td>{$payloadDetails}</td>
				</tr>
				HTML;
			}
			$pendingHtml = $this->renderDataTable([
				$this->_('ID'),
				$this->_('Queue'),
				$this->_('Job Class'),
				$this->_('Attempts'),
				$this->_('Status'),
				$this->_('Available At'),
				$this->_('Payload'),
			], $trs);
		}

		if ($status['failedJobs'] === []) {
			$failedHtml = '<p class="wk-muted-note">' . $sanitizer->entities($this->_('Zero failed jobs recorded in failed_jobs.')) . '</p>';
		} else {
			$trs = [];
			foreach ($status['failedJobs'] as $job) {
				$idSafe = (int) $job['id'];
				$queueSafe = $sanitizer->entities($job['queue']);
				$classSafe = $sanitizer->entities($job['jobClass']);
				$dateSafe = $sanitizer->entities($job['failedAt']);
				$errSafe = $sanitizer->entities($job['exceptionPreview']);
				$payloadCode = (string) (new KitCodePreview())->code((string) ($job['payloadFormatted'] ?? ''));
				$traceCode = (string) (new KitCodePreview())->code((string) ($job['exceptionFull'] ?? ''));

				$trs[] = <<<HTML
				<tr>
					<td class="wk-tabular">#{$idSafe}</td>
					<td><span class="wk-chip">{$queueSafe}</span></td>
					<td><code class="wk-code-inline">{$classSafe}</code></td>
					<td class="wk-tabular">{$dateSafe}</td>
					<td class="wk-cell-error">
						<details class="wk-inline-details">
							<summary>{$errSafe}</summary>
							<div class="wk-inline-details__body">
								<strong>{$this->_('Payload JSON:')}</strong>
								{$payloadCode}
								<strong>{$this->_('Stack Trace / Exception:')}</strong>
								{$traceCode}
							</div>
						</details>
					</td>
				</tr>
				HTML;
			}
			$failedHtml = $this->renderDataTable([
				$this->_('ID'),
				$this->_('Queue'),
				$this->_('Job Class'),
				$this->_('Failed At'),
				$this->_('Exception Summary'),
			], $trs);
		}

		$handlerCount = count($status['discoveredQueues']);
		$subtitle = sprintf(
			'%s %d · %s %d · %s %d',
			$this->_('Queued Jobs:'),
			(int) $status['queuedCount'],
			$this->_('Failed Jobs:'),
			(int) $status['failedCount'],
			$this->_('Discovered Handlers:'),
			$handlerCount
		);

		$bodyHtml = '<h4 class="wk-subhead">' . $sanitizer->entities1($this->_('Discovered Queue Classes')) . '</h4>'
			. $discoveredHtml
			. '<h4 class="wk-subhead">' . $sanitizer->entities1($this->_('Pending Jobs (queue_jobs)')) . '</h4>'
			. $pendingHtml
			. '<h4 class="wk-subhead">' . $sanitizer->entities1($this->_('Failed Jobs')) . '</h4>'
			. $failedHtml;

		$panelHtml = (string) (new KitCard())
			->icon('refresh')
			->title($this->_('Background Queues'))
			->subtitle($subtitle)
			->badge($tablesBadge)
			->body($bodyHtml);

		$actionsHtml = $isSuper
			? $this->renderQueuesActionCards($status)
			: $this->renderSuperuserRequiredBanner();

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}

	/**
	 * @param array<string, mixed> $status
	 */
	protected function renderQueuesActionCards(array $status): string
	{
		$sanitizer = $this->wire()->sanitizer;
		$tablesExist = (bool) ($status['tablesExist'] ?? false);
		$queuedCount = (int) ($status['queuedCount'] ?? 0);
		$failedCount = (int) ($status['failedCount'] ?? 0);
		$discoveredQueues = isset($status['discoveredQueues']) && is_array($status['discoveredQueues'])
			? $status['discoveredQueues']
			: [];
		$hasDiscovered = $discoveredQueues !== [];

		$queueClassOptions = [];
		foreach ($discoveredQueues as $q) {
			$qName = (string) ($q['name'] ?? '');
			if ($qName !== '') {
				$queueClassOptions[$qName] = $qName;
			}
		}

		$cards = [];

		if (!$tablesExist) {
			$cards[] = (new KitActionCard())
				->icon('plus-circle')
				->title($this->_('Create Queue Tables'))
				->cliCommand('queue:table')
				->description($this->_('Creates queue_jobs and failed_jobs tables in the database.'))
				->confirm('confirm_queues_install', 'RUN')
				->actionMethod('installQueueTables')
				->button($this->_('Create Tables'), 'database')
				->variant('primary');
		}

		$workControls = (string) (new KitInput())
			->name('work_queue_name')
			->value('default')
			->size('sm')
			->width('short')
			->placeholder('default')
			->autocomplete('off')
			->spellcheck(false)
			. (string) (new KitInput())
				->type('number')
				->name('work_max_jobs')
				->value(5)
				->min(1)
				->max(50)
				->size('sm')
				->width('num')
				->inlineLabel($this->_('Max'))
				->autocomplete('off')
			. (string) (new KitInput())
				->type('number')
				->name('work_max_tries')
				->value(3)
				->min(1)
				->max(10)
				->size('sm')
				->width('num')
				->inlineLabel($this->_('Tries'))
				->autocomplete('off');

		$cards[] = (new KitActionCard())
			->icon('cogs')
			->title($this->_('Process Queue Batch'))
			->cliCommand('queue:work')
			->description($this->_('Pops and executes up to N pending jobs from the selected queue synchronously.'))
			->confirm('confirm_queues_work', 'WORK')
			->actionMethod('workQueueBatch')
			->button($this->_('Work Batch'), 'play')
			->controlsHtml($workControls)
			->disabled(!$tablesExist)
			->variant('primary');

		$dispatchControls = (string) (new KitSelect())
			->name('dispatch_job_class')
			->size('sm')
			->placeholder($this->_('Select Queue Handler...'))
			->options($queueClassOptions)
			. (string) (new KitInput())
				->name('dispatch_job_payload')
				->value('{}')
				->size('sm')
				->width('short')
				->placeholder('{"userId": 1}')
				->autocomplete('off')
				->spellcheck(false);

		$cards[] = (new KitActionCard())
			->icon('paper-plane')
			->title($this->_('Dispatch Queue Job'))
			->cliCommand('queue:push')
			->description($this->_('Pushes a discovered Queue handler onto queue_jobs with a validated JSON payload.'))
			->confirm('confirm_queues_dispatch', 'DISPATCH')
			->actionMethod('dispatchQueueJob')
			->button($this->_('Dispatch Job'), 'paper-plane')
			->controlsHtml($dispatchControls)
			->disabled(!$tablesExist || !$hasDiscovered)
			->variant('primary');

		$cards[] = (new KitActionCard())
			->icon('repeat')
			->title($this->_('Retry Failed Jobs'))
			->cliCommand('queue:retry')
			->description($this->_('Enter "all" to requeue every failed job, or enter a specific failed job ID.'))
			->confirm('confirm_queues_retry', 'RETRY')
			->actionMethod('retryFailedJobs')
			->button($this->_('Retry Failed'), 'repeat')
			->controlsHtml((string) (new KitInput())->name('retry_target')->value('all')->size('sm')->width('short')->placeholder('all or ID')->autocomplete('off')->spellcheck(false))
			->disabled(!$tablesExist || $failedCount === 0)
			->variant('primary');

		$cards[] = (new KitActionCard())
			->icon('eraser')
			->title($this->_('Purge Pending Queue'))
			->cliCommand('queue:clear')
			->description($this->_('Deletes pending records from queue_jobs. Enter "all" to purge all queues or specify a queue name.'))
			->confirm('confirm_queues_purge_pending', 'PURGE')
			->actionMethod('purgePendingJobs')
			->button($this->_('Purge Pending'), 'eraser')
			->controlsHtml((string) (new KitInput())->name('purge_queue_name')->value('all')->size('sm')->width('short')->placeholder('all or default')->autocomplete('off')->spellcheck(false))
			->disabled(!$tablesExist)
			->variant('danger');

		$cards[] = (new KitActionCard())
			->icon('times-circle')
			->title($this->_('Delete Single Failed Job'))
			->cliCommand('queue:forget')
			->description($this->_('Deletes a specific failed job record by its numeric ID without clearing the rest of the table.'))
			->confirm('confirm_queues_delete_one', 'DELETE')
			->actionMethod('deleteFailedJob')
			->button($this->_('Delete Job'), 'times')
			->controlsHtml((string) (new KitInput())->type('number')->name('delete_failed_id')->value(1)->min(1)->size('sm')->width('num')->inlineLabel($this->_('Job ID'))->autocomplete('off'))
			->disabled(!$tablesExist || $failedCount === 0)
			->variant('danger');

		$cards[] = (new KitActionCard())
			->icon('trash')
			->title($this->_('Clear Failed Jobs'))
			->cliCommand('queue:flush')
			->description($this->_('Permanently deletes all records from failed_jobs.'))
			->confirm('confirm_queues_clear', 'CLEAR')
			->actionMethod('clearFailedJobs')
			->button($this->_('Clear Failed'), 'trash')
			->disabled(!$tablesExist || $failedCount === 0)
			->variant('danger');

		$cards[] = KitActionCard::scaffold(
			$this->_('Scaffold Queue Job'),
			$this->_('Generates a background job handler class in site/queue/.'),
			'make_queue_name',
			'SendWelcomeEmail',
			'confirm_queues_make',
			'makeQueueClass',
			$this->_('Create Queue'),
			'file-code-o',
			'make:queue'
		);

		return $this->renderActionGrid($cards);
	}
}
