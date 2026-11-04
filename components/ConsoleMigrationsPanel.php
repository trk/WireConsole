<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitProgressBar;

require_once __DIR__ . '/ConsolePanelComponent.php';

/**
 * Standalone Migrations tab panel component for ProcessWireConsole.
 */
class ConsoleMigrationsPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$status = $runtime->getMigrationsStatus();
		$isSuper = $runtime->isSuperuser();

		$tableBadge = (new KitBadge())
			->label($status['tableExists'] ? $this->_('Table Installed') : $this->_('Table Not Created'))
			->variant($status['tableExists'] ? 'success' : 'warning')
			->showDot(true);

		if ($status['items'] === []) {
			$rowsHtml = (string) (new KitEmptyState())
				->title($this->_('No Migration Files Found'))
				->description($this->_('Create your first timestamped ProcessWire schema migration in site/migrations/.'))
				->icon('database')
				->cliCommand('php vendor/bin/wire make:migration create_example_fields');
		} else {
			$trs = [];
			foreach ($status['items'] as $item) {
				$isRan = $item['status'] === 'Ran';
				$badge = (string) (new KitBadge())
					->label($isRan ? $this->_('Ran') : $this->_('Pending'))
					->variant($isRan ? 'success' : 'warning')
					->showDot(true);
				$nameSafe = $sanitizer->entities($item['name']);
				$batchSafe = $item['batch'] !== null ? '#' . $item['batch'] : '—';
				$dateSafe = $item['migrated_at'] !== null ? $sanitizer->entities($item['migrated_at']) : '—';
				$inspectBtn = $this->renderInspectTrigger('migration', $item['name']);

				$trs[] = <<<HTML
				<tr>
					<td><code class="wk-code-inline">{$nameSafe}</code></td>
					<td>{$badge}</td>
					<td class="wk-tabular">{$batchSafe}</td>
					<td class="wk-tabular">{$dateSafe}</td>
					<td class="wk-text-right">{$inspectBtn}</td>
				</tr>
				HTML;
			}
			$rowsHtml = $this->renderDataTable([
				$this->_('Migration Name'),
				$this->_('Status'),
				$this->_('Batch'),
				$this->_('Migrated At'),
				['label' => $this->_('Source'), 'class' => 'wk-text-right'],
			], $trs);
		}

		$migPercent = $status['total'] > 0
			? (int) round(($status['ran'] / $status['total']) * 100)
			: ($status['tableExists'] ? 100 : 0);

		$progressHtml = (string) (new KitProgressBar())
			->value($status['total'] > 0 ? (int) $status['ran'] : ($status['tableExists'] ? 1 : 0))
			->max($status['total'] > 0 ? (int) $status['total'] : 1)
			->variant($status['pending'] > 0 ? 'warning' : ($status['tableExists'] ? 'success' : 'muted'))
			->label($this->_('Execution Progress'))
			->meta(sprintf('%d / %d (%d%%)', $status['ran'], $status['total'], $migPercent))
			->size('md');

		$subtitle = sprintf(
			'%s %d · %s %d · %s %d · %s #%d',
			$this->_('Total:'),
			(int) $status['total'],
			$this->_('Ran:'),
			(int) $status['ran'],
			$this->_('Pending:'),
			(int) $status['pending'],
			$this->_('Last Batch:'),
			(int) $status['lastBatch']
		);

		$panelHtml = (string) (new KitCard())
			->icon('database')
			->title($this->_('Schema Migrations'))
			->subtitle($subtitle)
			->badge($tableBadge)
			->body($progressHtml . $rowsHtml);

		$actionsHtml = $isSuper
			? $this->renderMigrationsActionCards($status)
			: $this->renderSuperuserRequiredBanner();

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}

	/**
	 * @param array<string, mixed> $status
	 */
	protected function renderMigrationsActionCards(array $status): string
	{
		$tableExists = (bool) ($status['tableExists'] ?? false);
		$ran = (int) ($status['ran'] ?? 0);
		$pending = (int) ($status['pending'] ?? 0);
		$rollbackPreview = isset($status['rollbackPreview']) && is_array($status['rollbackPreview']) ? $status['rollbackPreview'] : [];
		$rollbackDesc = $this->_('Reverts the last migration batch (steps = 0) or rolls back a specific number of executed migrations.');
		if ($rollbackPreview !== []) {
			$rollbackDesc .= ' ' . sprintf($this->_('Next batch: %s'), implode(', ', $rollbackPreview));
		}

		$cards = [];

		if (!$tableExists) {
			$cards[] = (new KitActionCard())
				->icon('plus-circle')
				->title($this->_('Initialize Migrations Table'))
				->cliCommand('migrate:install')
				->description($this->_('Creates the wire_migrations tracking table in the database.'))
				->confirm('confirm_migrations_install', 'RUN')
				->actionMethod('installMigrationsTable')
				->button($this->_('Install Table'), 'database')
				->variant('primary');
		}

		$cards[] = (new KitActionCard())
			->icon('play')
			->title($this->_('Run Pending Migrations'))
			->cliCommand('migrate')
			->description($this->_('Executes pending migration files in chronological order. Leave steps at 0 to run all pending migrations.'))
			->confirm('confirm_migrations_run', 'RUN')
			->actionMethod('runMigrations')
			->button($this->_('Run Migrations'), 'play')
			->controlsHtml('<label class="wk-field-inline"><span>' . $this->esc($this->_('Steps')) . '</span><input type="number" name="migration_steps" value="0" min="0" max="100" class="wk-input wk-input--sm wk-input-num" autocomplete="off"></label>')
			->disabled(!$tableExists || $pending === 0)
			->variant('primary');

		$cards[] = (new KitActionCard())
			->icon('undo')
			->title($this->_('Rollback Migrations'))
			->cliCommand('migrate:rollback')
			->description($rollbackDesc)
			->confirm('confirm_migrations_rollback', 'ROLLBACK')
			->actionMethod('rollbackMigrations')
			->button($this->_('Rollback'), 'undo')
			->controlsHtml('<label class="wk-field-inline"><span>' . $this->esc($this->_('Steps')) . '</span><input type="number" name="rollback_steps" value="0" min="0" max="100" class="wk-input wk-input--sm wk-input-num" autocomplete="off"></label>')
			->disabled(!$tableExists || $ran === 0)
			->variant('danger');

		$resetControls = '<select name="migration_reset_mode" class="wk-select wk-select--sm">'
			. '<option value="reset">' . $this->esc($this->_('Reset All (migrate:reset)')) . '</option>'
			. '<option value="refresh">' . $this->esc($this->_('Refresh All (migrate:refresh)')) . '</option>'
			. '<option value="fresh">' . $this->esc($this->_('Fresh All — Drop & Re-run (migrate:fresh)')) . '</option>'
			. '</select>'
			. '<label class="wk-checkbox-inline"><input type="checkbox" name="migration_pre_backup" value="1" class="wk-checkbox"><span>' . $this->esc($this->_('Pre-reset SQL backup')) . '</span></label>';

		$cards[] = (new KitActionCard())
			->icon('refresh')
			->title($this->_('Reset / Refresh / Fresh'))
			->cliCommand('migrate:reset')
			->description($this->_('Rolls back all applied migrations, refreshes all migrations, or drops wire_migrations and re-runs from scratch.'))
			->confirm('confirm_migrations_reset', 'RESET')
			->actionMethod('resetOrRefreshMigrations')
			->button($this->_('Execute Reset'), 'refresh')
			->controlsHtml($resetControls)
			->disabled(!$tableExists || $ran === 0)
			->variant('danger');

		$cards[] = KitActionCard::scaffold(
			$this->_('Scaffold Migration'),
			$this->_('Generates a timestamped anonymous migration file in site/migrations/.'),
			'make_migration_name',
			'create_blog_fields',
			'confirm_migrations_make',
			'makeMigration',
			$this->_('Create Migration'),
			'file-code-o',
			'make:migration'
		);

		return $this->renderActionGrid($cards);
	}
}
