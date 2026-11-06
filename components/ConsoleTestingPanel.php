<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitCliBox;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitInput;
use Htmx\Ui\KitSelect;

/**
 * Standalone Testing tab panel component for ProcessWireConsole.
 */
class ConsoleTestingPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$runtime = $this->runtime();
		$env = $runtime->getTestingEnvironment();
		$testFiles = $runtime->getDiscoveredTestFiles();
		$isSuper = $runtime->isSuperuser();

		$pestBadge = (string) (new KitBadge())
			->label($env['hasPestBinary'] ? 'vendor/bin/pest' : $this->_('Pest Missing'))
			->variant($env['hasPestBinary'] ? 'success' : 'warning')
			->showDot(true);

		$xmlBadge = (string) (new KitBadge())
			->label($env['hasPhpunitXml'] ? 'phpunit.xml' : $this->_('No phpunit.xml'))
			->variant($env['hasPhpunitXml'] ? 'success' : 'muted')
			->showDot(true);

		$procBadge = (string) (new KitBadge())
			->label($env['procOpenAvailable'] ? 'proc_open()' : $this->_('proc_open disabled'))
			->variant($env['procOpenAvailable'] ? 'success' : 'danger')
			->showDot(true);

		$cliBox = (string) (new KitCliBox())
			->command('php vendor/bin/wire test')
			->caption($this->_('Run full Pest suite from your terminal or CI pipeline:'));

		if ($testFiles === []) {
			$listHtml = (string) (new KitEmptyState())
				->title($this->_('No Pest Test Files Discovered'))
				->description($this->_('Scaffold your first Pest feature test in tests/Feature/ using the generator card below.'))
				->icon('check-circle')
				->cliCommand('php vendor/bin/wire make:test HomePageTest');
		} else {
			$trs = [];
			foreach ($testFiles as $tf) {
				$suiteSafe = $sanitizer->entities($tf['suite']);
				$nameSafe = $sanitizer->entities($tf['name']);
				$relSafe = $sanitizer->entities($tf['relativePath']);
				$inspectBtn = $this->renderInspectTrigger('test', $tf['id']);

				$trs[] = <<<HTML
				<tr>
					<td><span class="wk-chip">{$suiteSafe}</span></td>
					<td><strong>{$nameSafe}</strong></td>
					<td><code class="wk-code-inline">{$relSafe}</code></td>
					<td class="wk-text-right">{$inspectBtn}</td>
				</tr>
				HTML;
			}
			$listHtml = $this->renderDataTable([
				$this->_('Suite'),
				$this->_('Test Name'),
				$this->_('Relative Path'),
				['label' => $this->_('Source'), 'class' => 'wk-text-right'],
			], $trs);
		}

		$targetOptions = [];
		foreach ($testFiles as $tf) {
			$relPath = (string) ($tf['relativePath'] ?? '');
			if ($relPath !== '') {
				$targetOptions[$relPath] = sprintf('%s (%s)', (string) ($tf['name'] ?? ''), (string) ($tf['suite'] ?? ''));
			}
		}

		$runControls = (string) (new KitSelect())
			->name('test_target_path')
			->size('sm')
			->placeholder($this->_('All Suites (Full Run)'))
			->options($targetOptions)
			. (string) (new KitInput())
				->name('test_args')
				->size('sm')
				->width('short')
				->placeholder('--filter=ExampleTest')
				->autocomplete('off')
				->spellcheck(false)
				->setAttribute('autocapitalize', 'off');

		$runTestCard = (new KitActionCard())
			->icon('check-circle')
			->title($this->_('Run Pest Test Suite'))
			->cliCommand('test')
			->description($this->_('Select a target test file or provide allowlisted flags (--filter=Name, --group=name, --testsuite=name, --stop-on-failure, --bail, --compact).'))
			->confirm('confirm_testing_run', 'TEST')
			->actionMethod('runTests')
			->button($this->_('Run Tests'), 'play')
			->controlsHtml($runControls)
			->disabled(!$env['ready'] || $testFiles === [])
			->variant('primary');

		$scaffoldCard = KitActionCard::scaffold(
			$this->_('Scaffold Pest Test'),
			$this->_('Generates a Pest test file in tests/Feature/.'),
			'make_test_name',
			'HomePageTest',
			'confirm_testing_make',
			'makeTestFile',
			$this->_('Create Test'),
			'file-code-o',
			'make:test'
		);

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$runTestCard, $scaffoldCard])
			: $this->renderSuperuserRequiredBanner();

		$bodyHtml = $listHtml . '<div class="wc-onboarding-commands">' . $cliBox . '</div>';

		$panelHtml = (string) (new KitCard())
			->icon('check-circle')
			->title($this->_('Pest PHP Test Runner'))
			->subtitle(sprintf('%s %d', $this->_('Discovered Test Files:'), (int) $env['testFileCount']))
			->headerActions($pestBadge . ' ' . $xmlBadge . ' ' . $procBadge)
			->body($bodyHtml);

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
