<?php

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

/**
 * Stateless Console-specific UI element rendering the runtime & package version matrix strip.
 */
class ConsoleVersionStrip extends Ui
{
	public string $name = 'console-version-strip';

	/** @var array<string, mixed> */
	public array $defaultParams = [
		'versions' => [],
	];

	/**
	 * @param array<string, string> $versions
	 */
	public function versions(array $versions): static
	{
		$this->parameters->set('versions', $versions);

		return $this;
	}

	public function render(): string
	{
		/** @var array<string, string> $versions */
		$versions = $this->parameters->getArray('versions');

		$this->addClass('wk-version-strip');
		$this->setAttribute('aria-label', $this->__('Runtime Version Matrix'));

		$items = [
			['icon' => 'code', 'label' => 'PHP', 'value' => $versions['php'] ?? ''],
			['icon' => 'cogs', 'label' => 'ProcessWire', 'value' => $versions['processwire'] ?? ''],
			['icon' => 'database', 'label' => 'DB', 'value' => $versions['database'] ?? ''],
			['icon' => 'exchange', 'label' => 'Htmx', 'value' => $versions['htmx'] ?? ''],
			['icon' => 'cubes', 'label' => 'WireKit', 'value' => $versions['wirekit'] ?? ''],
			['icon' => 'terminal', 'label' => 'processwire-console', 'value' => $versions['console'] ?? ''],
			['icon' => 'rocket', 'label' => 'processwire-boost', 'value' => $versions['boost'] ?? ''],
		];

		$chips = [];
		foreach ($items as $item) {
			$chips[] = sprintf(
				'<span class="wk-chip"><i class="fa fa-%s" aria-hidden="true"></i> %s <code class="wk-code-inline">%s</code></span>',
				$this->esc($item['icon']),
				$this->esc($item['label']),
				$this->esc($item['value'])
			);
		}

		return '<div ' . $this->attributes->render() . '>' . implode("\n", $chips) . '</div>';
	}
}
