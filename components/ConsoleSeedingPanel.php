<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitSelect;

/**
 * Standalone Seeding tab panel component for ProcessWireConsole.
 */
class ConsoleSeedingPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$seeders = $runtime->getDiscoveredSeeders();
		$hasFaker = $runtime->hasFaker();
		$isSuper = $runtime->isSuperuser();

		$fakerBadge = (new KitBadge())
			->label($hasFaker ? $this->_('FakerPHP Installed') : $this->_('FakerPHP Not Installed'))
			->variant($hasFaker ? 'success' : 'muted')
			->showDot(true);

		if ($seeders === []) {
			$listHtml = (string) (new KitEmptyState())
				->title($this->_('No Database Seeders Found'))
				->description($this->_('Create a seeder class in site/seeders/ to populate sample or initial CMS content.'))
				->icon('bolt')
				->cliCommand('php vendor/bin/wire make:seeder DatabaseSeeder');
		} else {
			$trs = [];
			foreach ($seeders as $seeder) {
				$nameSafe = $sanitizer->entities($seeder['name']);
				$classSafe = $sanitizer->entities($seeder['class']);
				$pathSafe = $sanitizer->entities(basename($seeder['path']));
				$inspectBtn = $this->renderInspectTrigger('seeder', $seeder['name']);

				$trs[] = <<<HTML
				<tr>
					<td><strong>{$nameSafe}</strong></td>
					<td><code class="wk-code-inline">{$classSafe}</code></td>
					<td><code class="wk-code-inline">{$pathSafe}</code></td>
					<td class="wk-text-right">{$inspectBtn}</td>
				</tr>
				HTML;
			}
			$listHtml = $this->renderDataTable([
				$this->_('Seeder Name'),
				$this->_('Class'),
				$this->_('File'),
				['label' => $this->_('Source'), 'class' => 'wk-text-right'],
			], $trs);
		}

		$seederOptions = ['all' => $this->_('Default (DatabaseSeeder / All)')];
		foreach ($seeders as $seeder) {
			$sName = (string) ($seeder['name'] ?? '');
			if ($sName !== '') {
				$seederOptions[$sName] = $sName;
			}
		}

		$runCard = (new KitActionCard())
			->icon('bolt')
			->title($this->_('Run Database Seeder'))
			->cliCommand('db:seed')
			->description($this->_('Executes the selected seeder class inside the booted ProcessWire environment.'))
			->confirm('confirm_seeding_run', 'SEED')
			->actionMethod('runSeeders')
			->button($this->_('Run Seeder'), 'play')
			->controlsHtml((string) (new KitSelect())->name('seeder_target')->size('sm')->options($seederOptions))
			->disabled($seeders === [])
			->variant('primary');

		$scaffoldCard = KitActionCard::scaffold(
			$this->_('Scaffold Seeder'),
			$this->_('Generates a database seeder class in site/seeders/.'),
			'make_seeder_name',
			'BlogPostSeeder',
			'confirm_seeding_make',
			'makeSeederClass',
			$this->_('Create Seeder'),
			'file-code-o',
			'make:seeder'
		);

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$runCard, $scaffoldCard])
			: $this->renderSuperuserRequiredBanner();

		$seederCount = count($seeders);
		$panelHtml = (string) (new KitCard())
			->icon('bolt')
			->title($this->_('Database Seeders'))
			->subtitle(sprintf('%s %d', $this->_('Discovered Seeder Classes:'), $seederCount))
			->badge($fakerBadge)
			->body($listHtml);

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
