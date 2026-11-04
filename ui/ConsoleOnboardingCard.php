<?php

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

/**
 * Stateless Console-specific UI card rendering the Composer environment & companion package onboarding guide.
 * Composes WireKit's `KitCard`, `KitBadge`, and `KitCliBox`.
 */
class ConsoleOnboardingCard extends Ui
{
	public string $name = 'console-onboarding-card';

	/** @var array<string, mixed> */
	public array $defaultParams = [
		'packages' => [],
		'prominent' => false,
	];

	/**
	 * @param array<string, mixed> $packages
	 */
	public function packages(array $packages): static
	{
		$this->parameters->set('packages', $packages);

		return $this;
	}

	public function prominent(bool $prominent = true): static
	{
		$this->parameters->set('prominent', $prominent);

		return $this;
	}

	public function render(): string
	{
		/** @var array<string, mixed> $packages */
		$packages = $this->parameters->getArray('packages');
		$isMissingBanner = $this->parameters->getBool('prominent', false);

		$rootPath = $this->esc((string) ($packages['rootPath'] ?? ''));
		$hasComposer = (bool) ($packages['hasComposerJson'] ?? false);
		$hasVendor = (bool) ($packages['hasVendorDir'] ?? false);
		$hasWireBin = (bool) ($packages['hasWireBinary'] ?? false);

		$consolePkg = is_array($packages['console'] ?? null) ? $packages['console'] : [];
		$boostPkg = is_array($packages['boost'] ?? null) ? $packages['boost'] : [];

		$consoleInstalled = (bool) ($consolePkg['installed'] ?? false);
		$boostInstalled = (bool) ($boostPkg['installed'] ?? false);

		$consoleStatusBadge = (string) (new KitBadge())
			->label($consoleInstalled
				? sprintf($this->__('Installed (%s)'), (string) ($consolePkg['version'] ?? 'active'))
				: $this->__('Missing — Required for CLI features'))
			->variant($consoleInstalled ? 'success' : 'warning')
			->showDot(true);

		$boostStatusBadge = (string) (new KitBadge())
			->label($boostInstalled
				? sprintf($this->__('Installed (%s)'), (string) ($boostPkg['version'] ?? 'active'))
				: $this->__('Not Installed — Optional AI & MCP Toolkit'))
			->variant($boostInstalled ? 'success' : 'muted')
			->showDot(true);

		$composerBadge = (string) (new KitBadge())
			->label($hasComposer ? 'composer.json' : $this->__('composer.json missing'))
			->variant($hasComposer ? 'success' : 'danger');

		$vendorBadge = (string) (new KitBadge())
			->label($hasVendor ? 'vendor/autoload.php' : $this->__('vendor/ missing'))
			->variant($hasVendor ? 'success' : 'warning');

		$wireBinBadge = (string) (new KitBadge())
			->label($hasWireBin ? 'vendor/bin/wire' : $this->__('vendor/bin/wire missing'))
			->variant($hasWireBin ? 'success' : 'warning');

		$consoleCmd = (string) (new KitCliBox())
			->command('composer require trk/processwire-console')
			->caption($this->__('1. Install the core ProcessWire Console package (migrations, queues, seeders, scheduler, testing):'));

		$boostCmd = (string) (new KitCliBox())
			->command('composer require --dev trk/processwire-boost && php vendor/bin/wire boost:install')
			->caption($this->__('2. Optional: Install ProcessWire Boost (AI skills, MCP server, and architecture rules):'));

		$heading = $isMissingBanner
			? $this->__('Composer Package Setup Required')
			: $this->__('Composer Environment & Package Status');
		$intro = $isMissingBanner
			? $this->__('The trk/processwire-console package was not detected in your project root. Run the Composer command below in your project directory to unlock migrations, queues, seeders, and scheduled tasks.')
			: $this->__('Live inspection of your project root Composer environment and companion CLI packages.');

		$bodyHtml = <<<HTML
		<div class="wc-pkg-grid">
			<div class="wc-pkg-item">
				<div class="wc-pkg-item__top">
					<div>
						<strong class="wc-pkg-item__name">trk/processwire-console</strong>
						<span class="wc-pkg-item__role">{$this->__('Core CLI & Runtime Engine')}</span>
					</div>
					{$consoleStatusBadge}
				</div>
				<p class="wc-pkg-item__summary">{$this->__('Provides vendor/bin/wire commands, schema migrations, database-backed queues, Faker seeders, cron task scheduling, and Pest test integration.')}</p>
				<div class="wc-pkg-item__links">
					<a href="https://github.com/trk/processwire-console" target="_blank" rel="noopener noreferrer" class="wc-link">
						<i class="fa fa-github" aria-hidden="true"></i> github.com/trk/processwire-console
					</a>
				</div>
			</div>

			<div class="wc-pkg-item">
				<div class="wc-pkg-item__top">
					<div>
						<strong class="wc-pkg-item__name">trk/processwire-boost</strong>
						<span class="wc-pkg-item__role">{$this->__('AI Agent Skills & MCP Server')}</span>
					</div>
					{$boostStatusBadge}
				</div>
				<p class="wc-pkg-item__summary">{$this->__('Curates ProcessWire domain skills (.agents/skills/) and provides a 28-tool JSON-RPC MCP server for AI coding assistants.')}</p>
				<div class="wc-pkg-item__links">
					<a href="https://github.com/trk/processwire-boost" target="_blank" rel="noopener noreferrer" class="wc-link">
						<i class="fa fa-github" aria-hidden="true"></i> github.com/trk/processwire-boost
					</a>
				</div>
			</div>
		</div>

		<div class="wc-onboarding-commands">
			<div class="wc-onboarding-root">
				<span class="wc-onboarding-root__label">{$this->__('Project Root Path:')}</span>
				<code class="wk-code-inline">{$rootPath}</code>
			</div>
			{$consoleCmd}
			{$boostCmd}
		</div>
		HTML;

		$card = (new KitCard())
			->icon('cubes')
			->title($heading)
			->subtitle($intro)
			->headerActions($composerBadge . ' ' . $vendorBadge . ' ' . $wireBinBadge)
			->body($bodyHtml);

		if ($isMissingBanner) {
			$card->accent('warning')->addClass('wk-card--onboarding-alert');
		}

		return (string) $card;
	}
}
