<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitButton;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitEmptyState;

require_once __DIR__ . '/ConsolePanelComponent.php';

/**
 * Standalone SQL Backups tab panel component for ProcessWireConsole.
 */
class ConsoleBackupPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$backups = $runtime->listBackups(50);
		$backupDir = $runtime->getBackupDirectory();
		$writable = is_dir($backupDir) && is_writable($backupDir);
		$isSuper = $runtime->isSuperuser();

		$dirBadge = (new KitBadge())
			->label($writable ? $this->_('Directory Writable') : $this->_('Directory Not Writable'))
			->variant($writable ? 'success' : 'danger')
			->showDot(true);

		$adminBackupUrl = ($this->wire()->page?->url ?? '') . 'download-backup?file=';

		if ($backups === []) {
			$listHtml = (string) (new KitEmptyState())
				->title($this->_('No SQL Backups Found'))
				->description($this->_('Create a database snapshot below or run vendor/bin/wire db:backup from the terminal.'))
				->icon('hdd-o')
				->cliCommand('php vendor/bin/wire db:backup');
		} else {
			$trs = [];
			foreach ($backups as $file) {
				$nameSafe = $sanitizer->entities($file['name']);
				$sizeSafe = $sanitizer->entities($file['sizeFormatted']);
				$dateSafe = $sanitizer->entities($file['modifiedFormatted']);
				$inspectBtn = $this->renderInspectTrigger('backup', $file['name']);
				$dlUrl = $adminBackupUrl . rawurlencode($file['name']);
				$dlBtn = $isSuper
					? (string) (new KitButton())
						->label($this->_('Download'))
						->icon('download')
						->variant('secondary')
						->size('xs')
						->href($dlUrl)
						->setAttribute('download', true)
					: '';

				$trs[] = <<<HTML
				<tr>
					<td><code class="wk-code-inline">{$nameSafe}</code></td>
					<td class="wk-tabular">{$sizeSafe}</td>
					<td class="wk-tabular">{$dateSafe}</td>
					<td class="wk-text-right">{$inspectBtn} {$dlBtn}</td>
				</tr>
				HTML;
			}
			$listHtml = $this->renderDataTable([
				$this->_('Filename'),
				$this->_('Size'),
				$this->_('Created At'),
				['label' => $this->_('Actions'), 'class' => 'wk-text-right'],
			], $trs);
		}

		$restoreOptions = '';
		foreach ($backups as $file) {
			$nameSafe = $sanitizer->entities($file['name']);
			$restoreOptions .= "<option value=\"{$nameSafe}\">{$nameSafe} ({$sanitizer->entities($file['sizeFormatted'])})</option>";
		}
		if ($restoreOptions === '') {
			$restoreOptions = '<option value="">' . $sanitizer->entities($this->_('No backups available')) . '</option>';
		}

		$hasBackups = $backups !== [];

		$createCard = (new KitActionCard())
			->icon('plus-circle')
			->title($this->_('Create SQL Snapshot'))
			->cliCommand('db:backup')
			->description($this->_('Generates a complete SQL dump in site/assets/backups/database/.'))
			->confirm('confirm_backup_create', 'BACKUP')
			->actionMethod('createBackup')
			->button($this->_('Create Backup'), 'hdd-o')
			->controlsHtml('<input type="text" name="backup_description" class="wk-input wk-input--sm wk-input-short" placeholder="' . $this->esc($this->_('Optional note')) . '" autocomplete="off">')
			->disabled(!$writable)
			->variant('primary');

		$purgeCard = (new KitActionCard())
			->icon('eraser')
			->title($this->_('Purge Old Backups'))
			->cliCommand('backup:purge')
			->description($this->_('Deletes .sql snapshots older than the specified retention window.'))
			->confirm('confirm_backup_purge', 'PURGE')
			->actionMethod('purgeBackups')
			->button($this->_('Purge Old'), 'eraser')
			->controlsHtml('<label class="wk-field-inline"><span>' . $this->esc($this->_('Days')) . '</span><input type="number" name="purge_days" value="30" min="1" max="3650" class="wk-input wk-input--sm wk-input-num" autocomplete="off"></label>')
			->disabled(!$hasBackups)
			->variant('secondary');

		$restoreControls = '<select name="restore_file" class="wk-select wk-select--sm">' . $restoreOptions . '</select>'
			. '<label class="wk-checkbox-inline"><input type="checkbox" name="restore_pre_backup" value="1" checked class="wk-checkbox"><span>' . $this->esc($this->_('Safety snapshot')) . '</span></label>';

		$restoreCard = (new KitActionCard())
			->icon('history')
			->title($this->_('Restore SQL Snapshot'))
			->cliCommand('db:restore')
			->description($this->_('Restores the selected SQL dump. Creates a pre-restore safety backup automatically by default.'))
			->confirm('confirm_backup_restore', 'RESTORE')
			->actionMethod('restoreBackup')
			->button($this->_('Restore'), 'history')
			->controlsHtml($restoreControls)
			->disabled(!$hasBackups)
			->variant('danger');

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$createCard, $purgeCard, $restoreCard])
			: $this->renderSuperuserRequiredBanner();

		$backupCount = count($backups);
		$panelHtml = (string) (new KitCard())
			->icon('hdd-o')
			->title($this->_('Database Backups'))
			->subtitle(sprintf('%s %d', $this->_('Snapshots stored in site/assets/backups/database/:'), $backupCount))
			->badge($dirBadge)
			->body($listHtml);

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
