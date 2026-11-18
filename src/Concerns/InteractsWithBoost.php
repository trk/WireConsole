<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Boost\BoostManager;
use Totoglu\Console\Boost\Install\Agents\Agent;
use Totoglu\Console\Boost\Install\SkillWriter;
use Totoglu\Console\Boost\Mcp\McpServer;
use Totoglu\Console\Boost\Skills\Remote\GitHubRepository;
use Totoglu\Console\Boost\Skills\Remote\GitHubSkillProvider;
use Totoglu\Console\Boost\Skills\Remote\SkillAuditor;

/**
 * ProcessWire Boost & MCP Server domain operations for ConsoleRuntime.
 */
trait InteractsWithBoost
{
	/**
	 * Instantiate BoostManager if trk/processwire-boost is installed.
	 */
	protected function createBoostManager(): ?BoostManager
	{
		if ($this->boostManagerInstance !== null) {
			return $this->boostManagerInstance;
		}
		if (!$this->isBoostPackageAvailable()) {
			return null;
		}

		$root = rtrim($this->getProjectRootPath(), '/');

		return $this->boostManagerInstance = new BoostManager($root);
	}

	/**
	 * Return the allowlist of strictly read-only MCP tool names exposed by ProcessWire Boost.
	 *
	 * @return list<string>
	 */
	public function getReadOnlyMcpToolNames(): array
	{
		return [
			'pw_query',
			'pw_schema_read',
			'pw_module_list',
			'pw_module_info',
			'pw_access_user_list',
			'pw_system_get_logs',
			'pw_system_logs_tail_last',
			'pw_system_backup_list',
		];
	}

	/**
	 * Execute a strictly allowlisted read-only MCP tool via `McpServer::handleRequest('tools/call')`
	 * so administrators can verify the exact JSON payload AI agents receive.
	 *
	 * @return array{ok: bool, tool: string, isError: bool, outputFormatted: string, message: string}
	 */
	public function executeReadOnlyMcpTool(string $toolName, string $rawJsonArguments = '{}'): array
	{
		$cleanTool = trim($toolName);
		$readOnlyTools = $this->getReadOnlyMcpToolNames();
		if (!in_array($cleanTool, $readOnlyTools, true)) {
			return [
				'ok' => false,
				'tool' => $cleanTool,
				'isError' => true,
				'outputFormatted' => '',
				'message' => sprintf(
					$this->_('MCP tool "%s" is not in the read-only allowlist. Only read-only inspection tools can be tested here.'),
					$cleanTool !== '' ? $cleanTool : 'none'
				),
			];
		}

		if (!$this->isBoostPackageAvailable()) {
			return [
				'ok' => false,
				'tool' => $cleanTool,
				'isError' => true,
				'outputFormatted' => '',
				'message' => $this->_('McpServer is unavailable because trk/processwire-boost is not installed.'),
			];
		}

		$parsedArgs = [];
		$trimmed = trim($rawJsonArguments);
		if ($trimmed !== '' && $trimmed !== '{}') {
			$decoded = strlen($trimmed) <= self::MAX_JSON_BODY_BYTES ? json_decode($trimmed, true, self::MAX_JSON_DEPTH) : null;
			if (
				!is_array($decoded)
				|| ($decoded !== [] && array_is_list($decoded))
				|| count($decoded, COUNT_RECURSIVE) > self::MAX_JSON_ELEMENTS
			) {
				return [
					'ok' => false,
					'tool' => $cleanTool,
					'isError' => true,
					'outputFormatted' => '',
					'message' => $this->_('MCP tool arguments must be a valid JSON object (e.g. {"selector": "id>0, limit=5"}).'),
				];
			}
			$parsedArgs = $decoded;
		}

		if ($cleanTool === 'pw_query') {
			$rawLimit = $parsedArgs['limit'] ?? null;
			$limit = is_int($rawLimit) ? $rawLimit : (is_string($rawLimit) && is_numeric(trim($rawLimit)) ? (int) trim($rawLimit) : 25);
			$parsedArgs['limit'] = max(1, min(50, $limit));
		}

		try {
			$server = new McpServer();
			$response = $server->handleRequest([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'tools/call',
				'params' => [
					'name' => $cleanTool,
					'arguments' => $parsedArgs,
				],
			]);

			$result = is_array($response['result'] ?? null) ? $response['result'] : [];
			$isError = (bool) ($result['isError'] ?? false) || isset($response['error']);
			$contentList = is_array($result['content'] ?? null) ? $result['content'] : [];
			$firstText = isset($contentList[0]['text']) && is_string($contentList[0]['text'])
				? $contentList[0]['text']
				: '';

			if ($firstText === '' && isset($response['error']['message']) && is_string($response['error']['message'])) {
				$firstText = $response['error']['message'];
			}

			$formatted = $firstText;
			$decodedPayload = $firstText !== '' ? json_decode($firstText, true) : null;
			if (is_array($decodedPayload)) {
				$pretty = json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if (is_string($pretty)) {
					$formatted = $pretty;
				}
			}

			if (strlen($formatted) > 24000) {
				$formatted = substr($formatted, 0, 24000) . "\n// ... (output truncated for browser preview)";
			}

			return [
				'ok' => !$isError,
				'tool' => $cleanTool,
				'isError' => $isError,
				'outputFormatted' => $formatted,
				'message' => !$isError
					? sprintf($this->_('Executed read-only MCP tool "%s" via JSON-RPC 2.0.'), $cleanTool)
					: sprintf($this->_('MCP tool "%s" returned an error response.'), $cleanTool),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'tool' => $cleanTool,
				'isError' => true,
				'outputFormatted' => '',
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Inspect the ProcessWire Boost MCP Server (`boost:mcp` / `McpServer`) protocol version,
	 * `PW_MCP_ALLOW_EXECUTE` guard state, and the 28-tool JSON-RPC catalog.
	 *
	 * @return array{
	 *   available: bool,
	 *   protocolVersion: string,
	 *   serverName: string,
	 *   serverVersion: string,
	 *   allowExecute: bool,
	 *   allowRestore: bool,
	 *   toolCount: int,
	 *   readOnlyCount: int,
	 *   mutationCount: int,
	 *   tools: list<array{name: string, description: string, isReadOnly: bool}>
	 * }
	 */
	public function getMcpServerDiagnostics(): array
	{
		if (!$this->isBoostPackageAvailable()) {
			return [
				'available' => false,
				'protocolVersion' => '—',
				'serverName' => 'processwire-boost',
				'serverVersion' => '0.0.0',
				'allowExecute' => false,
				'allowRestore' => false,
				'toolCount' => 0,
				'readOnlyCount' => 0,
				'mutationCount' => 0,
				'tools' => [],
			];
		}

		try {
			$server = new McpServer();
			$initResponse = $server->handleRequest([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'initialize',
			]);
			$result = is_array($initResponse['result'] ?? null) ? $initResponse['result'] : [];
			$serverInfo = is_array($result['serverInfo'] ?? null) ? $result['serverInfo'] : [];

			$protocolVersion = isset($result['protocolVersion']) && is_string($result['protocolVersion'])
				? $result['protocolVersion']
				: '2024-11-05';
			$serverName = isset($serverInfo['name']) && is_string($serverInfo['name'])
				? $serverInfo['name']
				: 'processwire-boost';
			$serverVersion = isset($serverInfo['version']) && is_string($serverInfo['version'])
				? $serverInfo['version']
				: BoostManager::VERSION;

			$readOnlyToolNames = $this->getReadOnlyMcpToolNames();

			$toolsRaw = $server->getToolsList();
			$tools = [];
			$readOnlyCount = 0;
			$mutationCount = 0;

			foreach ($toolsRaw as $toolDef) {
				$tName = isset($toolDef['name']) && is_string($toolDef['name']) ? $toolDef['name'] : '';
				if ($tName === '') {
					continue;
				}
				$tDesc = isset($toolDef['description']) && is_string($toolDef['description']) ? $toolDef['description'] : '';
				$isReadOnly = in_array($tName, $readOnlyToolNames, true);
				if ($isReadOnly) {
					$readOnlyCount++;
				} else {
					$mutationCount++;
				}
				$tools[] = [
					'name' => $tName,
					'description' => $tDesc,
					'isReadOnly' => $isReadOnly,
				];
			}

			$allowExecute = McpServer::isEnvEnabled('PW_MCP_ALLOW_EXECUTE');
			$allowRestore = McpServer::isEnvEnabled('PW_MCP_ALLOW_RESTORE');

			return [
				'available' => true,
				'protocolVersion' => $protocolVersion,
				'serverName' => $serverName,
				'serverVersion' => $serverVersion,
				'allowExecute' => $allowExecute,
				'allowRestore' => $allowRestore,
				'toolCount' => count($tools),
				'readOnlyCount' => $readOnlyCount,
				'mutationCount' => $mutationCount,
				'tools' => $tools,
			];
		} catch (\Throwable) {
			return [
				'available' => false,
				'protocolVersion' => '—',
				'serverName' => 'processwire-boost',
				'serverVersion' => '0.0.0',
				'allowExecute' => false,
				'allowRestore' => false,
				'toolCount' => 0,
				'readOnlyCount' => 0,
				'mutationCount' => 0,
				'tools' => [],
			];
		}
	}

	/**
	 * Inspect ProcessWire Boost configuration, detected AI agents, discoverable modules, and installed skills.
	 *
	 * @return array{
	 *   available: bool,
	 *   version: string,
	 *   hasConfig: bool,
	 *   hasMapJson: bool,
	 *   config: array{version: string, guidelines: bool, skills: bool, mcp: bool, modules: list<string>, agents: list<string>, generated_at: ?string},
	 *   detectedProjectAgents: list<string>,
	 *   detectedSystemAgents: list<string>,
	 *   availableAgents: list<array{name: string, displayName: string, guidelinesPath: string, enabled: bool, detected: bool}>,
	 *   discoverableModules: list<array{name: string, version: string, hasGuidelines: bool, hasSkills: bool, enabled: bool}>,
	 *   enabledModuleCount: int,
	 *   installedSkills: list<string>
	 * }
	 */
	public function getBoostStatus(): array
	{
		$manager = $this->createBoostManager();
		$root = $this->getProjectRootPath();
		$skillsDir = $root . '.agents/skills';
		$installedSkills = [];

		if (!is_link($root . '.agents') && is_dir($skillsDir) && !is_link($skillsDir)) {
			$dirs = glob($skillsDir . '/*', GLOB_ONLYDIR);
			if (is_array($dirs)) {
				foreach ($dirs as $dir) {
					$skillFile = $dir . '/SKILL.md';
					if (!is_link($dir) && !is_link($skillFile) && is_file($skillFile)) {
						$installedSkills[] = basename($dir);
					}
				}
				sort($installedSkills);
			}
		}

		$hasMapJson = !is_link($root . '.agents')
			&& !is_link($root . '.agents/map.json')
			&& is_file($root . '.agents/map.json');

		if ($manager === null) {
			return [
				'available' => false,
				'version' => '0.0.0',
				'hasConfig' => false,
				'hasMapJson' => $hasMapJson,
				'config' => [
					'version' => '0.0.0',
					'guidelines' => false,
					'skills' => false,
					'mcp' => false,
					'modules' => [],
					'agents' => [],
					'generated_at' => null,
				],
				'detectedProjectAgents' => [],
				'detectedSystemAgents' => [],
				'availableAgents' => [],
				'discoverableModules' => [],
				'enabledModuleCount' => 0,
				'installedSkills' => $installedSkills,
			];
		}

		$hasConfig = $manager->hasConfig();
		$config = $manager->loadConfig();
		$enabledModules = $config['modules'];
		$enabledAgents = $config['agents'];
		$detectedProject = $manager->detectProjectAgents();
		$detectedSystem = $manager->detectSystemAgents();
		$detectedMerged = array_values(array_unique(array_merge($detectedProject, $detectedSystem)));
		$discoverableRaw = $manager->getDiscoverableModules();
		$discoverableModules = [];
		$enabledModuleCount = 0;

		foreach ($discoverableRaw as $modName => $meta) {
			$isEnabled = in_array((string) $modName, $enabledModules, true);
			if ($isEnabled) {
				$enabledModuleCount++;
			}
			$discoverableModules[] = [
				'name' => (string) $modName,
				'version' => $this->resolveReadableModuleVersion((string) $modName),
				'hasGuidelines' => (bool) ($meta['has_guidelines'] ?? false),
				'hasSkills' => (bool) ($meta['has_skills'] ?? false),
				'enabled' => $isEnabled,
			];
		}

		$availableAgents = [];
		try {
			foreach ($manager->agentOptions(['guidelines', 'skills', 'mcp']) as $agent) {
				if (!$agent instanceof Agent) {
					continue;
				}
				$agentName = $agent->name();
				$availableAgents[] = [
					'name' => $agentName,
					'displayName' => $agent->displayName(),
					'guidelinesPath' => $agent->guidelinesPath(),
					'enabled' => in_array($agentName, $enabledAgents, true),
					'detected' => in_array($agentName, $detectedMerged, true),
				];
			}
		} catch (\Throwable) {
			$availableAgents = [];
		}

		return [
			'available' => true,
			'version' => BoostManager::VERSION,
			'hasConfig' => $hasConfig,
			'hasMapJson' => $hasMapJson,
			'config' => $config,
			'detectedProjectAgents' => $detectedProject,
			'detectedSystemAgents' => $detectedSystem,
			'availableAgents' => $availableAgents,
			'discoverableModules' => $discoverableModules,
			'enabledModuleCount' => $enabledModuleCount,
			'installedSkills' => $installedSkills,
		];
	}

	/**
	 * Synchronize ProcessWire Boost guidelines, skills, and schema map (`boost:update` equivalent).
	 *
	 * @return array{ok: bool, modules: list<string>, agents: list<string>, message: string}
	 */
	public function syncBoost(bool $discoverNewModules = true): array
	{
		$manager = $this->createBoostManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'modules' => [],
				'agents' => [],
				'message' => $this->_('BoostManager is unavailable because trk/processwire-boost is not installed.'),
			];
		}

		try {
			$config = $manager->loadConfig();
			$agentNames = $config['agents'];
			if ($agentNames === []) {
				$agentNames = $manager->detectProjectAgents();
				if ($agentNames === []) {
					$agentNames = $manager->detectAvailableAgents();
				}
			}

			if ($agentNames === []) {
				return [
					'ok' => false,
					'modules' => [],
					'agents' => [],
					'message' => $this->_('No AI agents configured or detected. Run php vendor/bin/wire boost:install first.'),
				];
			}

			$modules = $config['modules'];
			if ($discoverNewModules || !$manager->hasConfig()) {
				$discoverable = array_keys($manager->getDiscoverableModules());
				$modules = array_values(array_unique(array_merge($modules, $discoverable)));
			}

			$features = [];
			if (!$manager->hasConfig()) {
				$features = ['guidelines', 'skills'];
				$config['guidelines'] = true;
				$config['skills'] = true;
			} else {
				if ($config['guidelines']) {
					$features[] = 'guidelines';
				}
				if ($config['skills']) {
					$features[] = 'skills';
				}
				if ($config['mcp']) {
					$features[] = 'mcp';
				}
			}

			$normalizedAgents = $manager->normalizeAgentNames($agentNames);
			$manager->applyConfiguration($features, array_values($modules), $normalizedAgents);

			return [
				'ok' => true,
				'modules' => array_values($modules),
				'agents' => $normalizedAgents,
				'message' => sprintf(
					$this->_('Synchronized Boost resources for %d agent(s) and %d module(s).'),
					count($normalizedAgents),
					count($modules)
				),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'modules' => [],
				'agents' => [],
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Configure ProcessWire Boost features, enabled module guidelines/skills, and AI agents (`boost:install` equivalent).
	 *
	 * @param list<string> $features Subset of ['guidelines', 'skills', 'mcp']
	 * @param list<string> $modules Discoverable module names to enable
	 * @param list<string> $agentNames AI agent identifiers to configure
	 * @return array{ok: bool, features: list<string>, modules: list<string>, agents: list<string>, message: string}
	 */
	public function configureBoost(array $features, array $modules, array $agentNames): array
	{
		$manager = $this->createBoostManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'features' => [],
				'modules' => [],
				'agents' => [],
				'message' => $this->_('BoostManager is unavailable because trk/processwire-boost is not installed.'),
			];
		}

		try {
			$allowedFeatures = ['guidelines', 'skills', 'mcp'];
			$selectedFeatures = array_values(array_intersect($allowedFeatures, array_map('strval', $features)));
			if ($selectedFeatures === []) {
				$selectedFeatures = ['guidelines', 'skills'];
			}

			$discoverableNames = array_map('strval', array_keys($manager->getDiscoverableModules()));
			$rawModules = array_values(array_unique(array_filter(array_map(static fn(mixed $m): string => trim((string) $m), $modules), static fn(string $m): bool => $m !== '')));
			$unknownModules = array_values(array_diff($rawModules, $discoverableNames));
			if ($unknownModules !== []) {
				throw new \InvalidArgumentException('Unknown or non-discoverable module(s): ' . implode(', ', $unknownModules));
			}
			$selectedModules = array_values(array_intersect($discoverableNames, $rawModules));

			$rawAgents = array_values(array_unique(array_filter(array_map(static fn(mixed $a): string => trim((string) $a), $agentNames), static fn(string $a): bool => $a !== '')));
			$unknownAgents = [];
			foreach ($rawAgents as $rawAgentName) {
				if ($manager->normalizeAgentNames([$rawAgentName]) === []) {
					$unknownAgents[] = $rawAgentName;
				}
			}
			if ($unknownAgents !== []) {
				throw new \InvalidArgumentException('Invalid AI agent name(s): ' . implode(', ', $unknownAgents));
			}
			$normalizedAgents = $manager->normalizeAgentNames($rawAgents);
			if ($normalizedAgents === []) {
				$normalizedAgents = $manager->detectAvailableAgents();
			}

			$manager->applyConfiguration($selectedFeatures, $selectedModules, $normalizedAgents);

			return [
				'ok' => true,
				'features' => $selectedFeatures,
				'modules' => $selectedModules,
				'agents' => $normalizedAgents,
				'message' => sprintf(
					$this->_('Saved Boost configuration (%d feature(s), %d agent(s), %d module(s)).'),
					count($selectedFeatures),
					count($normalizedAgents),
					count($selectedModules)
				),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'features' => [],
				'modules' => [],
				'agents' => [],
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Fetch, security-audit, and install remote AI skills from a GitHub repository (`boost:add-skill` equivalent).
	 * Blocks any skill flagged with Medium, High, or Critical risk (`weight >= 3`) by `SkillAuditor` and stages downloads atomically.
	 *
	 * @return array{
	 *   ok: bool,
	 *   repo: string,
	 *   installed: list<string>,
	 *   skipped: list<string>,
	 *   blocked: array<string, string>,
	 *   details: string,
	 *   message: string
	 * }
	 */
	public function installRemoteBoostSkills(string $repoInput, bool $forceOverwrite = false): array
	{
		if (!$this->isBoostPackageAvailable()) {
			return [
				'ok' => false,
				'repo' => '',
				'installed' => [],
				'skipped' => [],
				'blocked' => [],
				'details' => '',
				'message' => $this->_('Remote skill installer is unavailable because trk/processwire-boost is not installed.'),
			];
		}

		try {
			$repo = GitHubRepository::fromInput(trim($repoInput));
		} catch (\InvalidArgumentException $e) {
			return [
				'ok' => false,
				'repo' => trim($repoInput),
				'installed' => [],
				'skipped' => [],
				'blocked' => [],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}

		try {
			$provider = new GitHubSkillProvider($repo);
			$discovered = $provider->discoverSkills();
			if ($discovered === []) {
				return [
					'ok' => false,
					'repo' => $repo->fullName(),
					'installed' => [],
					'skipped' => [],
					'blocked' => [],
					'details' => '',
					'message' => sprintf($this->_('No valid SKILL.md definitions found in %s.'), $repo->fullName()),
				];
			}

			$root = rtrim($this->getProjectRootPath(), '/');
			$skillsPath = $root . '/.agents/skills';
			if (is_link($root . '/.agents') || is_link($skillsPath)) {
				throw new \RuntimeException('Skills directory is a symbolic link.');
			}
			if (!is_dir($skillsPath)) {
				@mkdir($skillsPath, 0755, true);
			}

			$candidates = [];
			$skipped = [];
			foreach ($discovered as $skill) {
				$safeName = trim(SkillWriter::sanitizeSkillName(basename(str_replace('\\', '/', $skill->name))), '.-');
				if ($safeName === '') {
					continue;
				}
				if (!$forceOverwrite && is_dir($skillsPath . '/' . $safeName)) {
					$skipped[] = $skill->name;
					continue;
				}
				$candidates[$skill->name] = $skill;
			}

			if ($candidates === []) {
				$skippedLines = [];
				foreach ($skipped as $s) {
					$skippedLines[] = '= Skipped (existing): ' . $s;
				}

				return [
					'ok' => true,
					'repo' => $repo->fullName(),
					'installed' => [],
					'skipped' => $skipped,
					'blocked' => [],
					'details' => implode("\n", $skippedLines),
					'message' => sprintf($this->_('All %d skill(s) from %s are already installed.'), count($skipped), $repo->fullName()),
				];
			}

			$auditor = new SkillAuditor();
			$auditResults = $auditor->audit($provider, $candidates);
			$blocked = [];
			$installed = [];
			$downloadFailedMsg = $this->_('Download failed.');

			foreach ($candidates as $skillName => $skill) {
				$audit = $auditResults[$skillName] ?? null;
				if ($audit === null || $audit->risk->weight() >= 3) {
					$blocked[$skillName] = $audit !== null
						? $audit->risk->label() . ($audit->signals !== [] ? ': ' . implode(', ', $audit->signals) : '')
						: $this->_('Security audit result unavailable.');
					continue;
				}

				$safeName = trim(SkillWriter::sanitizeSkillName(basename(str_replace('\\', '/', $skill->name))), '.-');
				if ($safeName === '') {
					$blocked[$skillName] = $downloadFailedMsg;
					continue;
				}
				$targetPath = $skillsPath . '/' . $safeName;
				if (is_link($targetPath)) {
					$blocked[$skillName] = 'Target skill path is a symbolic link.';
					continue;
				}
				$stagingPath = $targetPath . '.staging_' . bin2hex(random_bytes(4));

				try {
					if ($provider->downloadSkill($skill, $stagingPath)) {
						if (is_dir($targetPath)) {
							SkillWriter::deleteTree($targetPath);
						}
						if (@rename($stagingPath, $targetPath)) {
							$installed[] = $skillName;
						} else {
							SkillWriter::deleteTree($stagingPath);
							$blocked[$skillName] = $downloadFailedMsg;
						}
					} else {
						SkillWriter::deleteTree($stagingPath);
						$blocked[$skillName] = $downloadFailedMsg;
					}
				} catch (\Throwable $dlErr) {
					SkillWriter::deleteTree($stagingPath);
					$blocked[$skillName] = $dlErr->getMessage();
				}
			}

			if ($installed !== []) {
				try {
					$this->syncBoost(false);
				} catch (\Throwable) {
				}
			}

			$detailsLines = [];
			foreach ($installed as $skill) {
				$detailsLines[] = '+ Installed: ' . $skill;
			}
			foreach ($skipped as $skill) {
				$detailsLines[] = '= Skipped (existing): ' . $skill;
			}
			foreach ($blocked as $skill => $reason) {
				$detailsLines[] = '! Blocked (' . $skill . '): ' . $reason;
			}

			$ok = $installed !== [] || $blocked === [];

			return [
				'ok' => $ok,
				'repo' => $repo->fullName(),
				'installed' => $installed,
				'skipped' => $skipped,
				'blocked' => $blocked,
				'details' => implode("\n", $detailsLines),
				'message' => sprintf(
					$this->_('Remote skills from %s: %d installed, %d skipped, %d blocked by audit.'),
					$repo->fullName(),
					count($installed),
					count($skipped),
					count($blocked)
				),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'repo' => $repo->fullName(),
				'installed' => [],
				'skipped' => [],
				'blocked' => [],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}
	}
}
