<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Boost\BoostManager;
use Totoglu\Console\Boost\Install\Agents\Agent;
use Totoglu\Console\Boost\Install\SkillWriter;
use Totoglu\Console\Boost\Mcp\McpServer;
use Totoglu\Console\Boost\Skills\Remote\GitHubRepository;
use Totoglu\Console\Boost\Skills\Remote\GitHubSkillProvider;
use Totoglu\Console\Boost\Skills\Remote\Risk;
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
		$this->ensureAutoload();
		if (!class_exists(BoostManager::class)) {
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

		$this->ensureAutoload();
		if (!class_exists(McpServer::class)) {
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
			$decoded = json_decode($trimmed, true);
			if (!is_array($decoded)) {
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
			$limit = isset($parsedArgs['limit']) ? (int) $parsedArgs['limit'] : 25;
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
	 *   toolCount: int,
	 *   readOnlyCount: int,
	 *   mutationCount: int,
	 *   tools: list<array{name: string, description: string, isReadOnly: bool}>
	 * }
	 */
	public function getMcpServerDiagnostics(): array
	{
		$this->ensureAutoload();
		if (!class_exists(McpServer::class)) {
			return [
				'available' => false,
				'protocolVersion' => '—',
				'serverName' => 'processwire-boost',
				'serverVersion' => '0.0.0',
				'allowExecute' => false,
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
				: (class_exists(BoostManager::class) ? BoostManager::VERSION : '0.0.0');

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

			$allowExecEnv = getenv('PW_MCP_ALLOW_EXECUTE');
			$allowExecute = $allowExecEnv === '1'
				|| (isset($_ENV['PW_MCP_ALLOW_EXECUTE']) && (string) $_ENV['PW_MCP_ALLOW_EXECUTE'] === '1');

			return [
				'available' => true,
				'protocolVersion' => $protocolVersion,
				'serverName' => $serverName,
				'serverVersion' => $serverVersion,
				'allowExecute' => $allowExecute,
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

		if (is_dir($skillsDir)) {
			$dirs = glob($skillsDir . '/*', GLOB_ONLYDIR);
			if (is_array($dirs)) {
				foreach ($dirs as $dir) {
					if (is_file($dir . '/SKILL.md')) {
						$installedSkills[] = basename($dir);
					}
				}
				sort($installedSkills);
			}
		}

		if ($manager === null) {
			return [
				'available' => false,
				'version' => '0.0.0',
				'hasConfig' => false,
				'hasMapJson' => is_file($root . '.agents/map.json'),
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
			'hasMapJson' => is_file($root . '.agents/map.json'),
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

			$agents = $manager->resolveConfiguredAgents($agentNames);
			$manager->install(
				features: $features,
				modules: $modules,
				agents: $agents,
			);

			$config['modules'] = array_values($modules);
			$config['agents'] = $manager->normalizeAgentNames($agentNames);
			$manager->saveConfig($config);

			return [
				'ok' => true,
				'modules' => $config['modules'],
				'agents' => $config['agents'],
				'message' => sprintf(
					$this->_('Synchronized Boost resources for %d agent(s) and %d module(s).'),
					count($config['agents']),
					count($config['modules'])
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

			$discoverableNames = array_keys($manager->getDiscoverableModules());
			$selectedModules = array_values(array_intersect($discoverableNames, array_map('strval', $modules)));

			$normalizedAgents = $manager->normalizeAgentNames(array_values(array_filter(array_map('strval', $agentNames))));
			if ($normalizedAgents === []) {
				$normalizedAgents = $manager->detectAvailableAgents();
			}

			$currentConfig = $manager->loadConfig();
			$installedAgentInstances = $manager->resolveConfiguredAgents($currentConfig['agents']);

			foreach ($allowedFeatures as $featureKey) {
				if (!in_array($featureKey, $selectedFeatures, true) && ($currentConfig[$featureKey] ?? false) === true) {
					$manager->uninstallFeature($featureKey, $installedAgentInstances);
				}
			}

			$agentsToRemove = array_values(array_diff($currentConfig['agents'], $normalizedAgents));
			foreach ($manager->resolveConfiguredAgents($agentsToRemove) as $agent) {
				$manager->uninstallAgent($agent);
			}

			$resolvedAgents = $manager->resolveConfiguredAgents($normalizedAgents);
			$manager->install(
				features: $selectedFeatures,
				modules: $selectedModules,
				agents: $resolvedAgents,
			);

			$manager->saveConfig([
				'version' => BoostManager::VERSION,
				'guidelines' => in_array('guidelines', $selectedFeatures, true),
				'skills' => in_array('skills', $selectedFeatures, true),
				'mcp' => in_array('mcp', $selectedFeatures, true),
				'modules' => $selectedModules,
				'agents' => $normalizedAgents,
				'generated_at' => date('Y-m-d H:i:s'),
			]);

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
	 * Blocks any skill flagged with High or Critical risk by `SkillAuditor`.
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
		$this->ensureAutoload();
		if (!class_exists(GitHubRepository::class) || !class_exists(GitHubSkillProvider::class) || !class_exists(SkillAuditor::class)) {
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
			if (!is_dir($skillsPath)) {
				@mkdir($skillsPath, 0755, true);
			}

			$candidates = [];
			$skipped = [];
			foreach ($discovered as $skill) {
				$safeName = (string) preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($skill->name));
				$safeName = trim($safeName, '.-');
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

			foreach ($candidates as $skillName => $skill) {
				$audit = $auditResults[$skillName] ?? null;
				if ($audit !== null && in_array($audit->risk, [Risk::Critical, Risk::High], true)) {
					$blocked[$skillName] = $audit->risk->label() . ($audit->signals !== [] ? ': ' . implode(', ', $audit->signals) : '');
					continue;
				}

				$safeName = (string) preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($skill->name));
				$safeName = trim($safeName, '.-');
				$targetPath = $skillsPath . '/' . $safeName;
				if (is_dir($targetPath) && class_exists(SkillWriter::class)) {
					SkillWriter::deleteTree($targetPath);
				}

				if ($provider->downloadSkill($skill, $targetPath)) {
					$installed[] = $skillName;
				} else {
					$blocked[$skillName] = $this->_('Download failed.');
				}
			}

			if ($installed !== []) {
				$this->syncBoost(false);
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
