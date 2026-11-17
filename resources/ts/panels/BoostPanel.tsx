import {
	React,
	useState,
	Button,
	Badge,
	Card,
	CardHeader,
	CardTitle,
	CardDescription,
	CardContent,
	Label,
	Input,
	Textarea,
	Select,
	Switch,
	Table,
	TableHeader,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
	StatCard,
	CliBox,
	CodePreview,
	EmptyState,
	DynamicIcon,
	useTranslations,
	type ActionHelperProps,
} from '../runtime';
import type { BoostModuleInfo, BoostPayload, McpToolInfo } from '../types';

interface BoostPanelProps extends ActionHelperProps {
	boost: BoostPayload | null;
}

export function BoostPanel({
	boost,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: BoostPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const status = boost?.status;
	const mcp = boost?.mcp;
	const readOnlyTools: string[] = Array.isArray(boost?.readOnlyTools) ? boost.readOnlyTools : [];
	const mcpResult = boost?.mcpResult ?? null;

	const [discoverNewModules, setDiscoverNewModules] = useState(true);
	const [featuresInput, setFeaturesInput] = useState(
		[
			status?.config?.guidelines !== false ? 'guidelines' : null,
			status?.config?.skills !== false ? 'skills' : null,
			status?.config?.mcp ? 'mcp' : null,
		]
			.filter(Boolean)
			.join(',') || 'guidelines,skills,mcp'
	);
	const [agentsInput, setAgentsInput] = useState((status?.config?.agents ?? []).join(','));
	const [modulesInput, setModulesInput] = useState((status?.config?.modules ?? []).join(','));
	const [remoteRepo, setRemoteRepo] = useState('');
	const [remoteForce, setRemoteForce] = useState(false);
	const [mcpToolName, setMcpToolName] = useState(readOnlyTools[0] ?? 'pw_schema_read');
	const [mcpToolArgs, setMcpToolArgs] = useState('{}');
	const [newModuleName, setNewModuleName] = useState('');
	const [newModuleType, setNewModuleType] = useState('module');

	if (!boost || !status || !mcp) {
		return (
			<EmptyState
				icon="sparkles"
				title={t('boost.unavailableTitle', 'Boost & AI Section Unavailable')}
				description={t('boost.unavailableDescription', 'You do not have permission to view ProcessWire Boost & MCP settings.')}
			/>
		);
	}

	const installedSkills: string[] = Array.isArray(status.installedSkills) ? status.installedSkills : [];
	const discoverableModules: BoostModuleInfo[] = Array.isArray(status.discoverableModules) ? status.discoverableModules : [];
	const mcpTools: McpToolInfo[] = Array.isArray(mcp.tools) ? mcp.tools : [];
	const readOnlyToolOptions = readOnlyTools.map((tool) => ({ value: tool, label: tool }));
	const moduleTypeOptions = [
		{ value: 'module', label: t('boost.moduleTypeStandardOption', 'Standard Module (module)') },
		{ value: 'process', label: t('boost.moduleTypeProcessOption', 'Admin Process Module (process)') },
		{ value: 'fieldtype', label: t('boost.moduleTypeFieldtypeOption', 'Database Fieldtype (fieldtype)') },
		{ value: 'inputfield', label: t('boost.moduleTypeInputfieldOption', 'Admin Inputfield (inputfield)') },
	];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					label={t('boost.boostEngine', 'Boost Engine')}
					value={status.available ? `v${status.version || '1.0'}` : t('workspace.unavailable', 'Unavailable')}
					hint={status.hasConfig ? t('boost.boostConfigActiveHint', '.agents/boost.json active') : t('boost.boostDefaultConfigHint', 'Default configuration')}
					icon="sparkles"
					tone={status.available ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('boost.installedSkills', 'Installed AI Skills')}
					value={installedSkills.length}
					hint=".agents/skills/*/SKILL.md"
					icon="book-2"
				/>
				<StatCard
					label={t('boost.mcpServer', 'MCP JSON-RPC Server')}
					value={t('boost.toolsCountValue', { count: mcp.toolCount ?? 0 }, '{count} tools')}
					hint={t(
						'boost.toolsBreakdownHint',
						{ readOnly: mcp.readOnlyCount ?? 0, mutation: mcp.mutationCount ?? 0 },
						'{readOnly} read-only • {mutation} mutation'
					)}
					icon="cpu"
					tone={mcp.available ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('boost.discoverableModules', 'Discoverable Modules')}
					value={`${status.enabledModuleCount ?? 0} / ${discoverableModules.length}`}
					hint={t('boost.discoverableModulesHint', 'Modules with AI guidelines or skills')}
					icon="stack-2"
				/>
			</div>

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
				{/* Sync & Configure Boost */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="sparkles" className="h-4 w-4 text-primary" />
							<CardTitle>{t('boost.syncConfigureTitle', 'Synchronize & Configure Boost')}</CardTitle>
						</div>
						<CardDescription>{t('boost.syncConfigureDesc', 'Regenerate AGENTS.md, skills, and schema map.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<Switch
							id="boost-discover-switch"
							checked={discoverNewModules}
							onCheckedChange={setDiscoverNewModules}
							label={t('boost.autoDiscoverSwitch', 'Auto-discover newly installed ProcessWire modules')}
						/>
						<div className="flex justify-end">
							<Button
								icon="refresh"
								disabled={!isSuperuser || busyAction !== null || !status.available}
								onClick={() =>
									openConfirm({
										action: 'syncBoost',
										guardKey: 'boost_sync',
										confirmWord: 'SYNC',
										variant: 'primary',
										title: t('boost.syncConfirmTitle', 'Synchronize ProcessWire Boost'),
										description: t('boost.syncConfirmDesc', 'Regenerate AI guidelines, skills, and .agents/map.json.'),
										payload: { boost_discover: discoverNewModules ? '1' : '0' },
									})
								}
							>
								{t('boost.syncNow', 'Sync Boost Now')}
							</Button>
						</div>

						<div className="border-t border-border pt-3 space-y-3">
							<div className="space-y-1.5">
								<Label htmlFor="boost-features">{t('boost.enabledFeaturesLabel', 'Enabled Features (CSV)')}</Label>
								<Input
									id="boost-features"
									value={featuresInput}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setFeaturesInput(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="boost-agents">{t('boost.targetAgentsLabel', 'Target Agents (CSV)')}</Label>
								<Input
									id="boost-agents"
									placeholder={t('boost.targetAgentsPlaceholder', 'claude_code,cursor,codex')}
									value={agentsInput}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setAgentsInput(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="boost-modules">{t('boost.enabledModulesLabel', 'Enabled Modules (CSV)')}</Label>
								<Input
									id="boost-modules"
									value={modulesInput}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setModulesInput(e.target.value)}
								/>
							</div>
							<div className="flex justify-end">
								<Button
									variant="secondary"
									size="sm"
									icon="check"
									disabled={!isSuperuser || busyAction !== null || !status.available}
									onClick={() =>
										openConfirm({
											action: 'configureBoost',
											guardKey: 'boost_configure',
											confirmWord: 'SAVE',
											variant: 'primary',
											title: t('boost.saveConfirmTitle', 'Save Boost Configuration'),
											description: t('boost.saveConfirmDesc', 'Persist .agents/boost.json and reinstall selected features.'),
											payload: {
												boost_features: featuresInput,
												boost_agents: agentsInput,
												boost_modules: modulesInput,
											},
										})
									}
								>
									{t('boost.saveConfiguration', 'Save Configuration')}
								</Button>
							</div>
						</div>
					</CardContent>
				</Card>

				{/* Read-Only MCP Tool Tester */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="cpu" className="h-4 w-4 text-primary" />
							<CardTitle>{t('boost.mcpInspectorTitle', 'MCP JSON-RPC Tool Inspector')}</CardTitle>
						</div>
						<CardDescription>{t('boost.mcpInspectorDescription', 'Execute allowlisted read-only MCP tools in-process.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="mcp-tool-name">{t('boost.readOnlyToolLabel', 'Read-Only MCP Tool')}</Label>
							<Select
								id="mcp-tool-name"
								value={mcpToolName}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setMcpToolName(e.target.value)}
								options={readOnlyToolOptions}
							/>
						</div>
						<div className="space-y-1.5">
							<Label htmlFor="mcp-tool-args">{t('boost.jsonRpcArgsLabel', 'JSON-RPC Arguments')}</Label>
							<Textarea
								id="mcp-tool-args"
								rows={3}
								className="font-mono text-xs"
								value={mcpToolArgs}
								onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setMcpToolArgs(e.target.value)}
							/>
						</div>
						<CliBox command="php vendor/bin/wire boost:mcp" label={t('boost.mcpServerEntryLabel', 'MCP Server Entry')} />
						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null || !mcp.available}
								onClick={() =>
									openConfirm({
										action: 'runMcpReadOnlyTool',
										guardKey: 'boost_mcp_test',
										confirmWord: 'QUERY',
										variant: 'primary',
										title: t('boost.runMcpConfirmTitle', { tool: mcpToolName }, 'Execute MCP Tool {tool}'),
										description: t('boost.runMcpConfirmDesc', { tool: mcpToolName }, 'Call {tool} via JSON-RPC 2.0 in-process.'),
										payload: {
											mcp_tool_name: mcpToolName,
											mcp_tool_args: mcpToolArgs,
										},
									})
								}
							>
								{t('boost.runMcpTool', 'Run MCP Tool')}
							</Button>
						</div>
					</CardContent>
				</Card>

				{/* Remote Skill Installer & Module Scaffolder */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="download" className="h-4 w-4 text-primary" />
							<CardTitle>{t('boost.remoteSkillsTitle', 'Remote Skills & Module Scaffold')}</CardTitle>
						</div>
						<CardDescription>{t('boost.remoteSkillsDescription', 'Audit & install GitHub skills or scaffold a new ProcessWire module.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="boost-remote-repo">{t('boost.githubRepoLabel', 'GitHub Repository (owner/repo)')}</Label>
							<Input
								id="boost-remote-repo"
								placeholder={t('boost.githubRepoPlaceholder', 'e.g. trk/processwire-skills')}
								value={remoteRepo}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setRemoteRepo(e.target.value)}
							/>
						</div>
						<Switch
							id="boost-overwrite-switch"
							checked={remoteForce}
							onCheckedChange={setRemoteForce}
							label={t('boost.overwriteSkillsSwitch', 'Overwrite existing skill directories')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								size="sm"
								icon="download"
								disabled={!isSuperuser || busyAction !== null || remoteRepo.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'installRemoteBoostSkills',
										guardKey: 'boost_add_skill',
										confirmWord: 'INSTALL',
										variant: remoteForce ? 'danger' : 'warning',
										title: t('boost.installSkillsConfirmTitle', 'Audit & Install Remote Skills'),
										description: t(
											'boost.installSkillsConfirmDesc',
											{ repo: remoteRepo.trim() },
											'Fetch and security-audit skills from "{repo}".'
										),
										payload: {
											boost_remote_repo: remoteRepo.trim(),
											boost_remote_force: remoteForce ? '1' : '0',
										},
									})
								}
							>
								{t('boost.installSkills', 'Install Skills')}
							</Button>
						</div>

						<div className="border-t border-border pt-3 space-y-2.5">
							<div className="space-y-1.5">
								<Label htmlFor="make-module-type">{t('boost.moduleStubTypeLabel', 'Module Stub Type')}</Label>
								<Select
									id="make-module-type"
									value={newModuleType}
									onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setNewModuleType(e.target.value)}
									options={moduleTypeOptions}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="make-module-name">{t('boost.scaffoldModuleLabel', 'Scaffold ProcessWire Module')}</Label>
								<div className="flex gap-2">
									<Input
										id="make-module-name"
										placeholder={t('boost.scaffoldModulePlaceholder', 'e.g. ProcessCustomDashboard')}
										value={newModuleName}
										onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewModuleName(e.target.value)}
									/>
									<Button
										variant="outline"
										icon="plus"
										disabled={!isSuperuser || busyAction !== null || newModuleName.trim() === ''}
										onClick={() =>
											openConfirm({
												action: 'makeModuleClass',
												guardKey: 'boost_make_module',
												confirmWord: 'CREATE',
												variant: 'primary',
												title: t('boost.scaffoldModuleLabel', 'Scaffold ProcessWire Module'),
												description: t(
													'boost.scaffoldModuleConfirmDesc',
													{ type: newModuleType, name: newModuleName.trim() },
													'Create {type} module at site/modules/{name}/{name}.module.php.'
												),
												payload: {
													make_module_name: newModuleName.trim(),
													make_module_type: newModuleType,
												},
											})
										}
									>
										{t('workspace.create', 'Create')}
									</Button>
								</div>
							</div>
						</div>
					</CardContent>
				</Card>
			</div>

			{mcpResult && (
				<CodePreview
					title={t('boost.mcpResponseTitle', { tool: mcpResult.tool ?? '' }, 'MCP JSON-RPC Response ({tool})')}
					subtitle={mcpResult.message}
					content={mcpResult.outputFormatted || '{}'}
					maxHeight="22rem"
				/>
			)}

			{/* Boost Documents & Installed Skills */}
			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center justify-between">
							<div>
								<CardTitle>{t('boost.installedSkillsCount', { count: installedSkills.length }, 'Installed Skills ({count})')}</CardTitle>
								<CardDescription>{t('boost.installedSkillsDesc', 'Select any skill to inspect its SKILL.md playbook')}</CardDescription>
							</div>
							<div className="flex gap-1.5">
								{[
									{ id: 'agents-md', label: 'AGENTS.md' },
									{ id: 'boost-json', label: 'boost.json' },
									{ id: 'map-json', label: 'map.json' },
								].map((doc) => (
									<Button
										key={doc.id}
										variant="outline"
										size="sm"
										onClick={() => inspectFile('boost-doc', doc.id)}
									>
										{doc.label}
									</Button>
								))}
							</div>
						</div>
					</CardHeader>
					<CardContent>
						<div className="flex flex-wrap gap-1.5">
							{installedSkills.map((skill) => (
								<Button
									key={skill}
									variant="outline"
									size="xs"
									onClick={() => inspectFile('skill', skill)}
									className="font-mono"
								>
									<DynamicIcon name="book-2" className="h-3 w-3 text-primary" />
									{skill}
								</Button>
							))}
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex flex-wrap items-start justify-between gap-2">
							<div>
								<CardTitle>{t('boost.mcpCatalogCount', { count: mcpTools.length }, 'MCP Tool Catalog ({count})')}</CardTitle>
								<CardDescription>
									{t(
										'boost.mcpProtocolSummary',
										{
											protocol: mcp.protocolVersion,
											server: mcp.serverName,
											version: mcp.serverVersion,
										},
										'Protocol {protocol} • {server} v{version}'
									)}
								</CardDescription>
							</div>
							<div className="flex flex-wrap gap-1.5">
								<Badge variant={mcp.allowExecute ? 'warning' : 'outline'}>
									{t(
										'boost.mcpExecuteGuardBadge',
										{
											state: mcp.allowExecute
												? t('boost.guardEnabled', 'Enabled')
												: t('boost.guardLocked', 'Locked'),
										},
										'Execute: {state}'
									)}
								</Badge>
								<Badge variant={mcp.allowRestore ? 'warning' : 'outline'}>
									{t(
										'boost.mcpRestoreGuardBadge',
										{
											state: mcp.allowRestore
												? t('boost.guardEnabled', 'Enabled')
												: t('boost.guardLocked', 'Locked'),
										},
										'Restore: {state}'
									)}
								</Badge>
							</div>
						</div>
					</CardHeader>
					<CardContent>
						<div
							className="max-h-72 overflow-y-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
							tabIndex={0}
							role="region"
							aria-label={t('boost.mcpCatalogCount', { count: mcpTools.length }, 'MCP Tool Catalog ({count})')}
						>
							<Table style={{ tableLayout: 'fixed' }}>
								<TableHeader>
									<TableRow>
										<TableHead style={{ width: '11rem' }}>{t('boost.toolNameHead', 'Tool Name')}</TableHead>
										<TableHead style={{ width: '6.5rem' }}>{t('boost.modeHead', 'Mode')}</TableHead>
										<TableHead>{t('boost.descriptionHead', 'Description')}</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{mcpTools.map((tool) => (
										<TableRow key={tool.name}>
											<TableCell className="truncate font-mono text-xs font-medium" title={tool.name}>{tool.name}</TableCell>
											<TableCell>
												<Badge variant={tool.isReadOnly ? 'success' : 'secondary'}>
													{tool.isReadOnly ? t('boost.readOnlyBadge', 'Read-Only') : t('boost.mutationBadge', 'Mutation')}
												</Badge>
											</TableCell>
											<TableCell className="break-words text-xs text-muted-foreground">{tool.description}</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>
					</CardContent>
				</Card>
			</div>
		</div>
	);
}
