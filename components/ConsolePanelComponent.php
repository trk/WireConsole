<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitButton;
use Htmx\Ui\KitCheckbox;
use Htmx\Ui\KitInput;
use Htmx\Ui\KitNotice;
use Htmx\Ui\KitSelect;
use Totoglu\Htmx\Component;
use Totoglu\WireConsole\ConsoleRuntime;

require_once dirname(__DIR__) . '/src/ConsoleRuntime.php';
require_once dirname(__DIR__, 2) . '/WireKit/ui/KitInput.php';
require_once dirname(__DIR__, 2) . '/WireKit/ui/KitSelect.php';
require_once dirname(__DIR__, 2) . '/WireKit/ui/KitCheckbox.php';

/**
 * Abstract base class for standalone ProcessWireConsole tab panel components.
 *
 * Provides shared access to `ConsoleRuntime`, `WireKit` UI primitives, file inspector triggers,
 * and Superuser action deck rendering.
 */
abstract class ConsolePanelComponent extends Component
{
	public string $workspaceId = 'hxc_wc_workspace';

	protected ?ConsoleRuntime $runtimeInstance = null;

	public function mount(): void
	{
		$this->initPanelIdentity();
	}

	public function hydrate(): void
	{
		$this->initPanelIdentity();
		parent::hydrate();
	}

	protected function initPanelIdentity(): void
	{
		$shortName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($this))->getShortName()));
		$this->id = 'hxc_wc_' . $shortName;
		$this->setStateKey('hx__state__' . $this->id);
	}

	public function runtime(): ConsoleRuntime
	{
		return $this->runtimeInstance ??= ConsoleRuntime::make($this->wire());
	}

	public function setRuntime(ConsoleRuntime $runtime): static
	{
		$this->runtimeInstance = $runtime;

		return $this;
	}

	protected function esc(mixed $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false);
	}

	protected function renderInspectTrigger(string $category, string $identifier, ?string $label = null): string
	{
		$btnLabel = $label ?? $this->_('Inspect');

		return (string) (new KitButton())
			->label($btnLabel)
			->icon('code')
			->variant('secondary')
			->size('xs')
			->addClass('wk-mono')
			->hxAction($this->requestUrl(), 'POST')
			->hxTarget('#' . $this->workspaceId)
			->hxSwap('outerMorph')
			->hxVals([
				'__htmx_action' => 'queryInspectFile',
				'category' => $category,
				'identifier' => $identifier,
			]);
	}

	protected function renderSuperuserRequiredBanner(): string
	{
		return (string) (new KitNotice())
			->title($this->_('Read-only Access'))
			->message($this->_('Mutating actions (running migrations, retrying queues, seeding, restoring backups, and running tests) require a Superuser account.'))
			->variant('primary');
	}

	/**
	 * @param list<KitActionCard|string> $cards
	 */
	protected function renderActionGrid(array $cards): string
	{
		$out = [];
		foreach ($cards as $card) {
			$out[] = (string) $card;
		}

		return '<div class="wk-action-grid">' . implode("\n", $out) . '</div>';
	}

	/**
	 * Render a responsive `<div class="wk-table-wrap"><table class="wk-table">` from column headers and `<tr>` strings.
	 *
	 * @param list<string|array{label: string, class?: string}> $headers
	 * @param list<string> $rows
	 */
	protected function renderDataTable(array $headers, array $rows): string
	{
		$ths = [];
		foreach ($headers as $header) {
			if (is_array($header)) {
				$cls = isset($header['class']) && $header['class'] !== ''
					? ' class="' . $this->esc($header['class']) . '"'
					: '';
				$ths[] = '<th' . $cls . '>' . $this->esc($header['label']) . '</th>';
			} else {
				$ths[] = '<th>' . $this->esc($header) . '</th>';
			}
		}

		$theadHtml = implode('', $ths);
		$tbodyHtml = implode("\n", $rows);

		return <<<HTML
		<div class="wk-table-wrap">
			<table class="wk-table">
				<thead>
					<tr>{$theadHtml}</tr>
				</thead>
				<tbody>{$tbodyHtml}</tbody>
			</table>
		</div>
		HTML;
	}

	/**
	 * Return structured actions deck HTML and main panel HTML for embedding inside `ConsoleWorkspace`.
	 *
	 * @return array{actions: string, panel: string}
	 */
	abstract public function renderSection(): array;

	public function render(): string
	{
		['actions' => $actions, 'panel' => $panel] = $this->renderSection();
		$deck = $actions !== ''
			? '<section class="wk-action-deck" aria-label="' . $this->esc($this->_('Quick Actions')) . '">' . $actions . '</section>'
			: '';

		return $deck . '<div class="wk-panel">' . $panel . '</div>';
	}
}
