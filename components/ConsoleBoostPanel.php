<?php

declare(strict_types=1);

namespace Htmx\Component;

use Htmx\Ui\KitActionCard;
use Htmx\Ui\KitBadge;
use Htmx\Ui\KitCard;
use Htmx\Ui\KitCliBox;
use Htmx\Ui\KitEmptyState;
use Htmx\Ui\KitProgressBar;

require_once __DIR__ . '/ConsolePanelComponent.php';

/**
 * ConsoleBoostPanel
 *
 * Dedicated HTMX v4 component for the Boost & AI tab of ProcessWireConsole.
 */
class ConsoleBoostPanel extends ConsolePanelComponent
{
	/**
	 * @return array{actions: string, panel: string}
	 */
	public function renderSection(): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$status = $this->runtime()->getBoostStatus();
		$isSuper = $this->runtime()->isSuperuser();

		if (!$status['available']) {
			$emptyState = KitEmptyState::make([
				'title' => $this->_('ProcessWire Boost Package Not Installed'),
				'description' => $this->_('Install trk/processwire-boost via Composer to manage AI coding guidelines, domain skills (.agents/skills/), and the 28-tool ProcessWire MCP server.'),
				'icon' => 'rocket',
				'cliCommand' => 'composer require --dev trk/processwire-boost && php vendor/bin/wire boost:install',
			])->render();

			$panelHtml = KitCard::make()
				->title($this->_('ProcessWire Boost & AI Ecosystem'))
				->subtitle($this->_('AI agent rules, skill playbooks, and Model Context Protocol (MCP) configuration.'))
				->icon('rocket')
				->body($emptyState)
				->render();

			return [
				'actions' => '',
				'panel' => $panelHtml,
			];
		}

		$cfgBadge = KitBadge::make([
			'label' => $status['hasConfig'] ? '.agents/boost.json' : $this->_('Not Initialized'),
			'variant' => $status['hasConfig'] ? 'success' : 'warning',
		])->render();

		$mapBadge = KitBadge::make([
			'label' => $status['hasMapJson'] ? '.agents/map.json' : $this->_('No map.json'),
			'variant' => $status['hasMapJson'] ? 'success' : 'muted',
		])->render();

		$mcpEnabled = (bool) ($status['config']['mcp'] ?? false);
		$mcpBadge = KitBadge::make([
			'label' => $mcpEnabled ? $this->_('MCP Enabled') : $this->_('MCP Disabled'),
			'variant' => $mcpEnabled ? 'success' : 'muted',
		])->render();

		$configuredAgents = $status['config']['agents'] !== []
			? $status['config']['agents']
			: $status['detectedProjectAgents'];

		$agentChips = [];
		foreach ($configuredAgents as $agentKey) {
			$agentSafe = $sanitizer->entities($agentKey);
			$agentChips[] = "<span class=\"wk-chip\"><i class=\"fa fa-terminal\" aria-hidden=\"true\"></i> <code>{$agentSafe}</code></span>";
		}
		$agentsHtml = $agentChips !== []
			? '<div class="wk-chip-list">' . implode('', $agentChips) . '</div>'
			: '<p class="wk-muted-note">' . $sanitizer->entities($this->_('No AI agents configured yet in .agents/boost.json.')) . '</p>';

		$skillChips = [];
		foreach ($status['installedSkills'] as $skillName) {
			$skillChips[] = $this->renderInspectTrigger('skill', $skillName, $skillName);
		}
		$skillsHtml = $skillChips !== []
			? '<div class="wk-chip-list">' . implode('', $skillChips) . '</div>'
			: '<p class="wk-muted-note">' . $sanitizer->entities($this->_('No skills synced in .agents/skills/ yet.')) . '</p>';

		$modulesHtml = '';
		if ($status['discoverableModules'] === []) {
			$modulesHtml = '<p class="wk-muted-note">' . $sanitizer->entities($this->_('No Boost-aware modules (with AGENTS.md or boost/ directory) discovered in site/modules/.')) . '</p>';
		} else {
			$trs = [];
			foreach ($status['discoverableModules'] as $mod) {
				$modSafe = $sanitizer->entities($mod['name']);
				$verSafe = $sanitizer->entities((string) ($mod['version'] ?? '—'));
				$gBadge = KitBadge::make([
					'label' => $mod['hasGuidelines'] ? $this->_('Guidelines') : '—',
					'variant' => $mod['hasGuidelines'] ? 'success' : 'muted',
				])->render();
				$sBadge = KitBadge::make([
					'label' => $mod['hasSkills'] ? $this->_('Skills') : '—',
					'variant' => $mod['hasSkills'] ? 'success' : 'muted',
				])->render();
				$eBadge = KitBadge::make([
					'label' => $mod['enabled'] ? $this->_('Enabled') : $this->_('Discovered'),
					'variant' => $mod['enabled'] ? 'primary' : 'warning',
				])->render();

				$trs[] = <<<HTML
				<tr>
					<td><strong>{$modSafe}</strong></td>
					<td><code class="wk-code-inline">{$verSafe}</code></td>
					<td>{$gBadge}</td>
					<td>{$sBadge}</td>
					<td>{$eBadge}</td>
				</tr>
				HTML;
			}
			$modulesHtml = $this->renderDataTable([
				$this->_('Module'),
				$this->_('Version'),
				$this->_('AGENTS.md / Guidelines'),
				$this->_('Boost Skills'),
				$this->_('Sync Status'),
			], $trs);
		}

		// Live MCP Server Diagnostics & Tool Catalog (`boost:mcp`)
		$mcpDiag = $this->runtime()->getMcpServerDiagnostics();
		$mcpSectionHtml = '';
		if ($mcpDiag['available']) {
			$protoSafe = $sanitizer->entities($mcpDiag['protocolVersion']);
			$srvNameSafe = $sanitizer->entities($mcpDiag['serverName']);
			$srvVerSafe = $sanitizer->entities($mcpDiag['serverVersion']);
			$execBadge = KitBadge::make([
				'label' => $mcpDiag['allowExecute']
					? 'PW_MCP_ALLOW_EXECUTE=1 (Enabled)'
					: 'PW_MCP_ALLOW_EXECUTE=0 (Disabled)',
				'variant' => $mcpDiag['allowExecute'] ? 'warning' : 'muted',
			])->render();

			$toolRows = [];
			foreach ($mcpDiag['tools'] as $tool) {
				$tName = $sanitizer->entities($tool['name']);
				$tDesc = $sanitizer->entities($tool['description']);
				$tBadge = KitBadge::make([
					'label' => $tool['isReadOnly'] ? $this->_('Read-Only') : $this->_('Mutation'),
					'variant' => $tool['isReadOnly'] ? 'success' : 'warning',
				])->render();

				$toolRows[] = <<<HTML
				<tr>
					<td><code class="wk-code-inline">{$tName}</code></td>
					<td>{$tBadge}</td>
					<td>{$tDesc}</td>
				</tr>
				HTML;
			}
			$mcpToolsTableHtml = $this->renderDataTable([
				$this->_('Tool Name'),
				$this->_('Mode'),
				$this->_('Description'),
			], $toolRows);
			$summaryText = sprintf(
				$this->_('Inspect %d Registered MCP Tools (%d read-only · %d mutation)'),
				$mcpDiag['toolCount'],
				$mcpDiag['readOnlyCount'],
				$mcpDiag['mutationCount']
			);

			$mcpSectionHtml = <<<HTML
			<h4 class="wk-subhead">{$this->_('MCP Server Diagnostics & Tool Catalog (boost:mcp)')}</h4>
			<div class="wk-chip-list">
				<span class="wk-chip"><i class="fa fa-server" aria-hidden="true"></i> <span>{$srvNameSafe}</span> <code>{$srvVerSafe}</code></span>
				<span class="wk-chip"><i class="fa fa-exchange" aria-hidden="true"></i> <span>Protocol</span> <code>{$protoSafe}</code></span>
				{$execBadge}
			</div>
			<details class="wk-inline-details">
				<summary>{$sanitizer->entities1($summaryText)}</summary>
				<div class="wk-inline-details__body">
					{$mcpToolsTableHtml}
				</div>
			</details>
			HTML;
		}

		$cliUpdateBox = KitCliBox::make([
			'command' => 'php vendor/bin/wire boost:update --discover',
			'caption' => $this->_('Synchronize AI agent guidelines, skills, and MCP server configs from CLI:'),
		])->render();

		$gChecked = ($status['config']['guidelines'] ?? true) ? ' checked' : '';
		$sChecked = ($status['config']['skills'] ?? true) ? ' checked' : '';
		$mChecked = ($status['config']['mcp'] ?? false) ? ' checked' : '';

		$agentCheckboxes = [];
		foreach ($status['availableAgents'] as $agentMeta) {
			$aName = $sanitizer->entities($agentMeta['name']);
			$aDisplay = $sanitizer->entities($agentMeta['displayName']);
			$aChecked = ($agentMeta['enabled'] || (!$status['hasConfig'] && $agentMeta['detected'])) ? ' checked' : '';
			$agentCheckboxes[] = "<label class=\"wk-checkbox-inline\"><input type=\"checkbox\" name=\"boost_agents[]\" value=\"{$aName}\"{$aChecked}><span>{$aDisplay}</span></label>";
		}
		$agentCheckboxesHtml = implode('', $agentCheckboxes);

		$modCheckboxes = [];
		foreach ($status['discoverableModules'] as $modMeta) {
			$mName = $sanitizer->entities($modMeta['name']);
			$modChecked = ($modMeta['enabled'] || !$status['hasConfig']) ? ' checked' : '';
			$modCheckboxes[] = "<label class=\"wk-checkbox-inline\"><input type=\"checkbox\" name=\"boost_modules[]\" value=\"{$mName}\"{$modChecked}><span>{$mName}</span></label>";
		}
		$modCheckboxesHtml = implode('', $modCheckboxes);

		$mcpToolOptions = [];
		foreach ($this->runtime()->getReadOnlyMcpToolNames() as $roTool) {
			$roSafe = $sanitizer->entities($roTool);
			$mcpToolOptions[] = "<option value=\"{$roSafe}\">{$roSafe}</option>";
		}
		$mcpToolOptionsHtml = implode('', $mcpToolOptions);

		$boostReady = (bool) ($status['available'] ?? false);

		$syncCard = KitActionCard::make()
			->icon('refresh')
			->title($this->_('Synchronize Boost Guidelines & Skills'))
			->cliCommand('boost:update')
			->description($this->_('Regenerates .agents/map.json, syncs .agents/skills/*, updates agent rule files (CLAUDE.md, AGENTS.md, .cursorrules, etc.), and refreshes MCP configurations.'))
			->confirm('confirm_boost_sync', 'SYNC')
			->actionMethod('syncBoost')
			->button($this->_('Sync Boost'), 'refresh')
			->controlsHtml(<<<HTML
			<label class="wk-checkbox-inline">
				<input type="checkbox" name="boost_discover" value="1" checked>
				<span>{$this->_('Auto-discover new modules')}</span>
			</label>
			HTML)
			->disabled(!$boostReady)
			->variant('primary');

		$configDetailsHtml = <<<HTML
		<details class="wk-inline-details">
			<summary>{$this->_('Select Features, AI Agents & Modules')}</summary>
			<div class="wk-inline-details__body">
				<div class="wk-checkbox-group">
					<label class="wk-checkbox-inline"><input type="checkbox" name="boost_features[]" value="guidelines"{$gChecked}><span>{$this->_('Guidelines')}</span></label>
					<label class="wk-checkbox-inline"><input type="checkbox" name="boost_features[]" value="skills"{$sChecked}><span>{$this->_('Skills')}</span></label>
					<label class="wk-checkbox-inline"><input type="checkbox" name="boost_features[]" value="mcp"{$mChecked}><span>{$this->_('MCP Server')}</span></label>
				</div>
				<div class="wk-checkbox-group">{$agentCheckboxesHtml}</div>
				<div class="wk-checkbox-group">{$modCheckboxesHtml}</div>
			</div>
		</details>
		HTML;

		$configureCard = KitActionCard::make()
			->icon('sliders')
			->title($this->_('Configure AI Agents & Modules'))
			->cliCommand('boost:install')
			->description($this->_('Select active Boost features, target AI coding assistants, and module guideline/skill sources to persist in .agents/boost.json.'))
			->confirm('confirm_boost_configure', 'SAVE')
			->actionMethod('configureBoost')
			->button($this->_('Save Config'), 'check')
			->extraBodyHtml($configDetailsHtml)
			->disabled(!$boostReady)
			->variant('primary');

		$remoteSkillCard = KitActionCard::make()
			->icon('cloud-download')
			->title($this->_('Install Remote GitHub Skills'))
			->cliCommand('boost:add-skill')
			->description($this->_('Fetches SKILL.md playbooks from a GitHub repository, audits them with SkillAuditor to block high/critical risk patterns, and installs safe skills.'))
			->confirm('confirm_boost_add_skill', 'INSTALL')
			->actionMethod('installRemoteBoostSkills')
			->button($this->_('Install Skills'), 'download')
			->controlsHtml(<<<HTML
			<input type="text"
				name="boost_remote_repo"
				class="wk-input wk-input--sm wk-input-short"
				placeholder="owner/repo"
				autocomplete="off"
				spellcheck="false"
				autocapitalize="off">
			<label class="wk-checkbox-inline">
				<input type="checkbox" name="boost_remote_force" value="1" class="wk-checkbox">
				<span>{$this->_('Overwrite')}</span>
			</label>
			HTML)
			->disabled(!$boostReady)
			->variant('neutral');

		$mcpTestCard = KitActionCard::make()
			->icon('terminal')
			->title($this->_('Test Read-Only MCP Tool'))
			->cliCommand('boost:mcp')
			->description($this->_('Executes an allowlisted read-only MCP tool via JSON-RPC 2.0 and displays the exact JSON payload returned to AI agents.'))
			->confirm('confirm_boost_mcp_test', 'QUERY')
			->actionMethod('runMcpReadOnlyTool')
			->button($this->_('Run MCP Query'), 'play')
			->controlsHtml(<<<HTML
			<select name="mcp_tool_name" class="wk-select wk-select--sm">
				{$mcpToolOptionsHtml}
			</select>
			<input type="text"
				name="mcp_tool_args"
				value="{}"
				class="wk-input wk-input--sm wk-input-short"
				placeholder='{"selector": "id>0, limit=5"}'
				autocomplete="off"
				spellcheck="false">
			HTML)
			->disabled(!$boostReady || !$mcpDiag['available'] || $mcpToolOptions === [])
			->variant('primary');

		$scaffoldModuleCard = KitActionCard::scaffold(
			$this->_('Scaffold ProcessWire Module'),
			$this->_('Generates a new ProcessWire module directory and strict-typed .module.php class inside site/modules/ and refreshes the module registry.'),
			'make_module_name',
			'HelloWorld',
			'confirm_boost_make_module',
			'makeModuleClass',
			$this->_('Create Module'),
			'cube',
			'make:module'
		);

		$actionsHtml = $isSuper
			? $this->renderActionGrid([$syncCard, $configureCard, $remoteSkillCard, $mcpTestCard, $scaffoldModuleCard])
			: $this->renderSuperuserRequiredBanner();

		$skillCount = count($status['installedSkills']);
		$agentCount = count($configuredAgents);
		$moduleCount = count($status['discoverableModules']);
		$enabledCount = (int) $status['enabledModuleCount'];

		$syncPercent = $moduleCount > 0 ? (int) round(($enabledCount / $moduleCount) * 100) : 100;
		$syncProgressHtml = KitProgressBar::make([
			'value' => $enabledCount,
			'max' => max(1, $moduleCount),
			'variant' => $enabledCount >= $moduleCount ? 'success' : 'warning',
			'label' => $this->_('Boost Module Synchronization Coverage'),
			'meta' => sprintf($this->_('%d / %d modules synced (%d%%)'), $enabledCount, $moduleCount, $syncPercent),
			'size' => 'md',
		])->render();

		$docFilesHtml = '<div class="wk-chip-list">'
			. $this->renderInspectTrigger('boost-doc', 'agents-md', 'AGENTS.md')
			. $this->renderInspectTrigger('boost-doc', 'boost-json', '.agents/boost.json')
			. $this->renderInspectTrigger('boost-doc', 'map-json', '.agents/map.json')
			. '</div>';

		$bodyHtml = <<<HTML
		{$syncProgressHtml}
		<h4 class="wk-subhead">{$this->_('Core Boost Artifacts & Guidelines')}</h4>
		{$docFilesHtml}
		<h4 class="wk-subhead">{$this->_('Configured AI Agents')}</h4>
		{$agentsHtml}
		<h4 class="wk-subhead">{$this->_('Discoverable Boost-Aware Modules')}</h4>
		{$modulesHtml}
		<h4 class="wk-subhead">{$this->_('Installed Domain Skills (.agents/skills/)')}</h4>
		{$skillsHtml}
		{$mcpSectionHtml}
		<div class="wc-onboarding-commands">
			{$cliUpdateBox}
		</div>
		HTML;

		$panelHtml = KitCard::make()
			->title($this->_('ProcessWire Boost & AI Ecosystem'))
			->subtitle(sprintf(
				'%s %d · %s %d · %s %d',
				$this->_('Configured Agents:'),
				$agentCount,
				$this->_('Installed Skills:'),
				$skillCount,
				$this->_('Boost-Aware Modules:'),
				$moduleCount
			))
			->icon('rocket')
			->headerActions("{$cfgBadge}\n{$mapBadge}\n{$mcpBadge}")
			->body($bodyHtml)
			->render();

		return [
			'actions' => $actionsHtml,
			'panel' => $panelHtml,
		];
	}
}
