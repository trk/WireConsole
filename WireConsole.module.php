<?php

declare(strict_types=1);

namespace ProcessWire;

require_once __DIR__ . '/WireConsoleDependencyChecker.php';

final class WireConsole extends WireData implements Module, ConfigurableModule
{
	private const PACKAGE_CONSOLE = 'trk/processwire-console';
	private const PACKAGE_BOOST = 'trk/processwire-boost';
	private const REPO_MODULE = 'https://github.com/trk/WireConsole';
	private const REPO_CONSOLE = 'https://github.com/trk/processwire-console';
	private const REPO_BOOST = 'https://github.com/trk/processwire-boost';

	public static function getModuleInfo(): array
	{
		return [
			'title' => 'Wire Console',
			'version' => 1,
			'summary' => 'Provides status and setup guidance for ProcessWire Console and Boost composer packages.',
			'href' => self::REPO_MODULE,
			'author' => 'Iskender TOTOGLU @trk',
			'icon' => 'terminal',
			'singular' => true,
			'autoload' => false,
			'requires' => [
				'ProcessWire>=3.0.210',
				'PHP>=8.2.0',
			],
		];
	}

	public function init(): void
	{
		// Intentionally empty (stage 1).
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
	{
		$config = $this->wire()->config;

		$composerJsonPath = WireConsoleDependencyChecker::getComposerJsonPath($config);
		$vendorPath = WireConsoleDependencyChecker::getVendorPath($config);

		$packages = [
			self::PACKAGE_CONSOLE,
			self::PACKAGE_BOOST,
		];

		$status = WireConsoleDependencyChecker::getPackagesStatus($config, $packages);
		$hasConsole = !empty($status[self::PACKAGE_CONSOLE]['installed']);
		$hasProcessModule = $this->modules->isInstalled('ProcessWireConsole');

		if ($hasConsole && !$hasProcessModule) {
			$inputfields->add($this->buildMarkupField(
				$this->_('Recommendation'),
				$this->renderProcessModuleRecommendationMarkup()
			));
		}

		$inputfields->add($this->buildMarkupField(
			$this->_('WireConsole / Composer status'),
			$this->renderStatusMarkup($composerJsonPath !== null, $vendorPath !== null, $status)
		));

		$inputfields->add($this->buildMarkupField(
			$this->_('Repository links'),
			$this->renderRepoLinksMarkup()
		));

		$inputfields->add($this->buildMarkupField(
			$this->_('Install commands'),
			$this->renderInstallCommandsMarkup($status)
		));

		$inputfields->add($this->buildMarkupField(
			$this->_('Notes'),
			$this->renderNotesMarkup()
		));

		return $inputfields;
	}

	private function renderProcessModuleRecommendationMarkup(): string
	{
		$moduleUrl = $this->config->urls->admin . 'module/edit?name=ProcessWireConsole';

		return sprintf(
			'<div class="NoticeMessage">
				<p>%s</p>
				<p>%s</p>
				<p><a href="%s">%s</a></p>
			</div>',
			$this->_('ProcessWireConsole module is not installed.'),
			$this->_('Since the Console package is installed, you can now install the admin Process module to access Console features in the panel.'),
			htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8'),
			$this->_('Open ProcessWireConsole module')
		);
	}

	private function buildMarkupField(string $label, string $html): InputfieldMarkup
	{
		/** @var InputfieldMarkup $f */
		$f = $this->modules->get('InputfieldMarkup');
		$f->label = $label;
		$f->value = $html;
		return $f;
	}

	/**
	 * @param array<string, array{inComposer: bool, installed: bool}> $status
	 */
	private function renderStatusMarkup(bool $hasComposerJson, bool $hasVendor, array $status): string
	{
		$yes = $this->_('Yes');
		$no = $this->_('No');

		$environmentNotice = '';
		if (!$hasComposerJson || !$hasVendor) {
			$environmentNotice = sprintf(
				'<div class="NoticeError"><p>%s</p><p>%s</p></div>',
				$this->_('Composer environment not detected.'),
				$this->_('Could not find composer.json and/or vendor/ in the expected project root. Please verify your installation and paths, then refresh this page.')
			);
		}

		$rows = '';
		foreach ($status as $package => $s) {
			$rows .= sprintf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars((string) $package, ENT_QUOTES, 'UTF-8'),
				$s['inComposer'] ? $yes : $no,
				$s['installed'] ? $yes : $no
			);
		}

		return sprintf(
			'
			%s
			<p>%s</p>
			<ul>
				<li>composer.json: %s</li>
				<li>vendor/: %s</li>
			</ul>
			<table class="AdminDataList">
				<thead>
					<tr>
						<th>%s</th>
						<th>%s</th>
						<th>%s</th>
					</tr>
				</thead>
				<tbody>%s</tbody>
			</table>
			',
			$environmentNotice,
			$this->_('This module does not install packages automatically. It only checks and shows guidance.'),
			$hasComposerJson ? $yes : $no,
			$hasVendor ? $yes : $no,
			$this->_('Package'),
			$this->_('In composer.json'),
			$this->_('Installed (vendor)'),
			$rows
		);
	}

	/**
	 * @param array<string, array{inComposer: bool, installed: bool}> $status
	 */
	private function renderInstallCommandsMarkup(array $status): string
	{
		$cmds = [];

		// Console: required for ProcessWireConsole (stage 2 UI).
		if (empty($status[self::PACKAGE_CONSOLE]['installed'])) {
			$cmds[] = 'composer require ' . self::PACKAGE_CONSOLE;
		}

		// Boost: optional.
		if (empty($status[self::PACKAGE_BOOST]['installed'])) {
			$cmds[] = 'composer require ' . self::PACKAGE_BOOST;
		}

		if (!$cmds) {
			return '<p>' . $this->_('All known packages appear to be installed.') . '</p>';
		}

		$html = '<p>' . $this->_('Run the following command(s) from your project root:') . '</p>';
		$html .= '<ol>';
		$html .= '<li>' . $this->_('Open a terminal on your server/local environment and change directory to your ProcessWire project root (where composer.json exists).') . '</li>';
		$html .= '<li>' . $this->_('Run the command(s) below.') . '</li>';
		$html .= '<li>' . $this->_('Refresh this configuration page and then refresh modules (Modules → Refresh).') . '</li>';
		$html .= '</ol>';
		$html .= '<pre><code>' . htmlspecialchars(implode("\n", $cmds), ENT_QUOTES, 'UTF-8') . '</code></pre>';
		$html .= '<p><small>' . sprintf(
			$this->_('Note: %s is optional. Install it only if you want AI/MCP related features in later stages.'),
			htmlspecialchars(self::PACKAGE_BOOST, ENT_QUOTES, 'UTF-8')
		) . '</small></p>';
		return $html;
	}

	private function renderRepoLinksMarkup(): string
	{
		return sprintf(
			'<ul>
				<li>%s: <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>
				<li>%s: <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>
			</ul>
			<p><small>%s</small></p>',
			$this->_('Console repository'),
			htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars(self::REPO_CONSOLE, ENT_QUOTES, 'UTF-8'),
			$this->_('Boost repository (optional)'),
			htmlspecialchars(self::REPO_BOOST, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars(self::REPO_BOOST, ENT_QUOTES, 'UTF-8'),
			sprintf(
				$this->_('If you are developing locally, you can also add these repositories to your %s as path repositories.'),
				'composer.json'
			)
		);
	}

	private function renderNotesMarkup(): string
	{
		return sprintf(
			'<ul>
				<li>%s</li>
				<li>%s</li>
				<li>%s</li>
			</ul>',
			sprintf(
				$this->_('The ProcessWireConsole admin page requires the %s package.'),
				htmlspecialchars(self::PACKAGE_CONSOLE, ENT_QUOTES, 'UTF-8')
			),
			sprintf(
				$this->_('The %s package is optional and enables AI/MCP related capabilities in later stages.'),
				htmlspecialchars(self::PACKAGE_BOOST, ENT_QUOTES, 'UTF-8')
			),
			$this->_('If you encounter composer dependency conflicts, try running composer with --with-all-dependencies (-W) and/or check: composer why symfony/console.')
		);
	}
}
