<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\ConsoleOnboardingCard;
use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitInput;
use Htmx\Ui\KitProgressBar;
use Htmx\Ui\KitSelect;
use Htmx\Ui\KitStatCard;

require_once __DIR__ . '/ConsolePanelComponent.php';
require_once dirname(__DIR__) . '/ui/ConsoleOnboardingCard.php';

/**
 * Standalone Overview tab panel component for ProcessWireConsole.
 *
 * Renders the 4x2 KPI telemetry grid (`KitStatCard` + `KitProgressBar`), Superuser maintenance/cache
 * action cards (`KitActionCard`), and the Composer package onboarding guide (`ConsoleOnboardingCard`).
 */
class ConsoleOverviewPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$runtime = $this->runtime();
		$telemetry = $runtime->getOverviewTelemetry();
		$packages = $telemetry['packages'];

		$mig = $telemetry['migrations'];
		$queues = $telemetry['queues'];
		$seed = $telemetry['seeding'];
		$sched = $telemetry['scheduling'];
		$bak = $telemetry['backup'];
		$test = $telemetry['testing'];
		$boost = $telemetry['boost'];
		$maint = $telemetry['maintenance'];

		$migPending = (int) $mig['pending'];
		$migBadge = (new KitBadge())
			->label($migPending > 0 ? sprintf($this->_('%d Pending'), $migPending) : $this->_('Up to date'))
			->variant($migPending > 0 ? 'warning' : 'success')
			->showDot(true);

		$qFailed = (int) $queues['failedCount'];
		$qBadge = (new KitBadge())
			->label($qFailed > 0 ? sprintf($this->_('%d Failed'), $qFailed) : $this->_('Healthy'))
			->variant($qFailed > 0 ? 'danger' : 'success')
			->showDot(true);

		$seedBadge = (new KitBadge())
			->label($seed['hasFaker'] ? $this->_('Faker Ready') : $this->_('No Faker'))
			->variant($seed['hasFaker'] ? 'success' : 'muted')
			->showDot(true);

		$schedDue = (int) $sched['dueCount'];
		$schedBadge = (new KitBadge())
			->label($schedDue > 0 ? sprintf($this->_('%d Due Now'), $schedDue) : $this->_('Idle'))
			->variant($schedDue > 0 ? 'warning' : 'success')
			->showDot(true);

		$bakBadge = (new KitBadge())
			->label($bak['writable'] ? $this->_('Writable') : $this->_('Read-only'))
			->variant($bak['writable'] ? 'success' : 'danger')
			->showDot(true);

		$testBadge = (new KitBadge())
			->label($test['ready'] ? $this->_('Pest Ready') : $this->_('Unavailable'))
			->variant($test['ready'] ? 'success' : 'muted')
			->showDot(true);

		$boostBadge = (new KitBadge())
			->label($boost['hasConfig'] ? $this->_('Configured') : ($boost['available'] ? $this->_('Ready') : $this->_('Optional')))
			->variant($boost['hasConfig'] ? 'success' : ($boost['available'] ? 'primary' : 'muted'))
			->showDot(true);

		$maintBadge = (new KitBadge())
			->label($maint['isDown'] ? $this->_('Maintenance (503)') : $this->_('Live (200)'))
			->variant($maint['isDown'] ? 'warning' : 'success')
			->showDot(true);

		$migProgress = (new KitProgressBar())
			->value($mig['total'] > 0 ? (int) $mig['ran'] : 0)
			->max($mig['total'] > 0 ? (int) $mig['total'] : 1)
			->variant($migPending > 0 ? 'warning' : ($mig['total'] > 0 ? 'success' : 'muted'));

		$qTotal = (int) $queues['queuedCount'] + $qFailed;
		$qProgress = (new KitProgressBar())
			->value($qTotal > 0 ? (int) $queues['queuedCount'] : 0)
			->max($qTotal > 0 ? $qTotal : 1)
			->variant($qFailed > 0 ? 'danger' : ($qTotal > 0 ? 'primary' : 'muted'));

		$boostProgress = (new KitProgressBar())
			->value((int) $boost['enabledModuleCount'])
			->max(max(1, (int) $boost['moduleCount']))
			->variant(($boost['moduleCount'] > 0 && $boost['enabledModuleCount'] >= $boost['moduleCount']) ? 'success' : 'primary');

		$latestBackupText = $bak['latestFile'] !== null
			? (string) $bak['latestFile']
			: $this->_('No snapshots yet');

		$maintValue = $maint['isDown'] ? $this->_('DOWN') : $this->_('LIVE');
		$maintMeta = $maint['isDown'] && $maint['sinceFormatted'] !== null
			? sprintf($this->_('Down since %s'), (string) $maint['sinceFormatted'])
			: $this->_('Normal HTTP traffic active');

		$kpiCards = implode("\n", [
			(string) (new KitStatCard())
				->icon('database')
				->label($this->_('Migrations'))
				->badge($migBadge)
				->value("{$mig['ran']} / {$mig['total']}")
				->progress($migProgress)
				->meta("{$this->_('Executed schema migrations')} (Batch #{$mig['lastBatch']})"),
			(string) (new KitStatCard())
				->icon('refresh')
				->label($this->_('Queues'))
				->badge($qBadge)
				->value((string) $queues['queuedCount'])
				->progress($qProgress)
				->meta("{$queues['discoveredCount']} {$this->_('handlers')} · {$qFailed} {$this->_('failed')}"),
			(string) (new KitStatCard())
				->icon('bolt')
				->label($this->_('Seeders'))
				->badge($seedBadge)
				->value((string) $seed['discoveredCount'])
				->meta($this->_('Seeder classes in site/seeders/')),
			(string) (new KitStatCard())
				->icon('clock-o')
				->label($this->_('Scheduling'))
				->badge($schedBadge)
				->value((string) $sched['discoveredCount'])
				->meta($this->_('Scheduled tasks in site/schedule/')),
			(string) (new KitStatCard())
				->icon('hdd-o')
				->label($this->_('SQL Backups'))
				->badge($bakBadge)
				->value((string) $bak['totalCount'])
				->meta($latestBackupText),
			(string) (new KitStatCard())
				->icon('check-circle')
				->label($this->_('Testing'))
				->badge($testBadge)
				->value((string) $test['testFileCount'])
				->meta($this->_('Pest *Test.php files discovered')),
			(string) (new KitStatCard())
				->icon('rocket')
				->label($this->_('Boost & AI'))
				->badge($boostBadge)
				->value((string) $boost['skillCount'])
				->progress($boostProgress)
				->meta("{$boost['agentCount']} {$this->_('agents')} · {$boost['enabledModuleCount']}/{$boost['moduleCount']} {$this->_('modules synced')}"),
			(string) (new KitStatCard())
				->icon('power-off')
				->label($this->_('App Status'))
				->badge($maintBadge)
				->value($maintValue)
				->meta($maintMeta),
		]);

		$onboardingCard = ($packages['console']['installed'] && !$packages['boost']['installed'])
			? (string) (new ConsoleOnboardingCard())->packages($packages)->prominent(false)
			: '';

		$actionsHtml = $runtime->isSuperuser()
			? $this->renderSystemMaintenanceCards($maint)
			: '';

		$panelHtml = '<div class="wk-kpi-grid">' . $kpiCards . '</div>' . $onboardingCard;

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}

	/**
	 * @param array<string, mixed> $maint
	 */
	protected function renderSystemMaintenanceCards(array $maint): string
	{
		$isDown = (bool) ($maint['isDown'] ?? false);

		$maintCard = $isDown
			? (new KitActionCard())
				->icon('play-circle')
				->title($this->_('Disable Maintenance Mode'))
				->cliCommand('wire up')
				->description($this->_('Removes site/down.json and restores normal public HTTP traffic.'))
				->confirm('confirm_system_up', 'UP')
				->actionMethod('disableMaintenanceMode')
				->button($this->_('Bring App Live'), 'check')
				->variant('primary')
			: (new KitActionCard())
				->icon('pause-circle')
				->title($this->_('Maintenance Mode'))
				->cliCommand('wire down')
				->description($this->_('Writes site/down.json with an optional secret bypass phrase and Retry-After header.'))
				->confirm('confirm_system_down', 'DOWN')
				->actionMethod('enableMaintenanceMode')
				->button($this->_('Put App Down'), 'power-off')
				->controlsHtml(
					(string) (new KitInput())
						->name('down_secret')
						->size('sm')
						->width('short')
						->placeholder($this->_('Optional secret'))
						->autocomplete('off')
						->spellcheck(false)
						->setAttribute('autocorrect', 'off')
						->setAttribute('autocapitalize', 'off')
						->setAttribute('data-1p-ignore', 'true')
						->setAttribute('data-lpignore', 'true')
					. (string) (new KitInput())
						->type('number')
						->name('down_retry')
						->value(60)
						->min(0)
						->max(86400)
						->size('sm')
						->width('num')
						->inlineLabel($this->_('Retry (s)'))
						->autocomplete('off')
				)
				->variant('secondary');

		$cacheControls = (string) (new KitSelect())
			->name('cache_clear_mode')
			->size('sm')
			->options([
				'all' => $this->_('All Caches (cache:clear)'),
				'wire' => $this->_('WireCache Only (cache:wire:clear)'),
				'core' => $this->_('Modules & FileCompiler Only'),
				'modules' => $this->_('Modules Registry Only (module:refresh)'),
			]);

		$cacheCard = (new KitActionCard())
			->icon('bolt')
			->title($this->_('Flush System Caches'))
			->cliCommand('cache:clear')
			->description($this->_('Flush all caches (Modules, FileCompiler & WireCache), only WireCache (cache:wire:clear), or only core module/compiler caches.'))
			->confirm('confirm_system_cache_clear', 'CACHE')
			->actionMethod('clearSystemCaches')
			->button($this->_('Clear Caches'), 'refresh')
			->controlsHtml($cacheControls)
			->variant('primary');

		return $this->renderActionGrid([$maintCard, $cacheCard]);
	}
}
