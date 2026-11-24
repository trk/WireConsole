import type * as React from 'react';
import { DynamicIcon, useEffect, useState } from '@pw/react/core';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	CliBox,
	CodePreview,
	EmptyState,
	Input,
	Label,
	Select,
	StatCard,
	Switch,
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from '@pw/react/ui';
import { SchemaField } from '@pw/react/fields';
import { useTranslations } from '@pw/react/hooks';
import type { ActionHelperProps, BoostModuleInfo, BoostPayload, McpToolInfo } from '../types';

interface BoostPanelProps extends ActionHelperProps {
	boost: BoostPayload | null;
	canRunReadOnlyMcp?: boolean;
}

export function BoostPanel({
	boost,
	isSuperuser,
	canRunReadOnlyMcp,
	busyAction,
	openConfirm,
	inspectFile,
}: BoostPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const status = boost?.status;
	const mcp = boost?.mcp;
	const readOnlyTools: string[] = Array.isArray(boost?.readOnlyTools) ? boost.readOnlyTools : [];
	const mcpResult = boost?.mcpResult ?? null;

	const initialFeatures = [
		status?.config?.guidelines !== false ? 'guidelines' : null,
		status?.config?.skills !== false ? 'skills' : null,
		status?.config?.mcp ? 'mcp' : null,
	].filter((item): item is string => item !== null);
	const configFeaturesKey = (initialFeatures.length > 0 ? initialFeatures : ['guidelines', 'skills', 'mcp']).join(',');
	const configAgentsKey = (status?.config?.agents ?? []).join(',');
	const configModulesKey = (status?.config?.modules ?? []).join(',');

	const [discoverNewModules, setDiscoverNewModules] = useState(true);
	const [featuresTags, setFeaturesTags] = useState<string[]>(
		configFeaturesKey ? configFeaturesKey.split(',') : ['guidelines', 'skills', 'mcp']
	);
	const [agentsTags, setAgentsTags] = useState<string[]>(status?.config?.agents ?? []);
	const [modulesTags, setModulesTags] = useState<string[]>(status?.config?.modules ?? []);
	const [remoteRepo, setRemoteRepo] = useState('');
	const [remoteForce, setRemoteForce] = useState(false);
	const [mcpToolName, setMcpToolName] = useState(readOnlyTools[0] ?? 'pw_schema_read');
	const [mcpToolArgs, setMcpToolArgs] = useState('{}');
	const [newModuleName, setNewModuleName] = useState('');
	const [newModuleType, setNewModuleType] = useState('module');

	useEffect(() => {
		setFeaturesTags(configFeaturesKey ? configFeaturesKey.split(',') : ['guidelines', 'skills', 'mcp']);
	}, [configFeaturesKey]);

	useEffect(() => {
		setAgentsTags(configAgentsKey ? configAgentsKey.split(',') : []);
	}, [configAgentsKey]);

	useEffect(() => {
		setModulesTags(configModulesKey ? configModulesKey.split(',') : []);
	}, [configModulesKey]);

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
	const availableAgentSuggestions = (status.availableAgents ?? []).map((agent) => agent.name);
	const moduleTypeOptions = [
		{
			value: 'module',
			label: t('boost.moduleTypeStandardShort', 'Standard'),
			description: t('boost.moduleTypeStandardOption', 'Standard Module (module)'),
		},
		{
			value: 'process',
			label: t('boost.moduleTypeProcessShort', 'Process'),
			description: t('boost.moduleTypeProcessOption', 'Admin Process Module (process)'),
		},
		{
			value: 'fieldtype',
			label: t('boost.moduleTypeFieldtypeShort', 'Fieldtype'),
			description: t('boost.moduleTypeFieldtypeOption', 'Database Fieldtype (fieldtype)'),
		},
		{
			value: 'inputfield',
			label: t('boost.moduleTypeInputfieldShort', 'Inputfield'),
			description: t('boost.moduleTypeInputfieldOption', 'Admin Inputfield (inputfield)'),
		},
	];
	const activeModuleTypeOption = moduleTypeOptions.find((opt) => opt.value === newModuleType);

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
							<SchemaField
								field={{
									id: 'boost-features',
									name: 'boost_features',
									type: 'tags',
									label: t('boost.enabledFeaturesLabel', 'Enabled Features'),
									suggestions: ['guidelines', 'skills', 'mcp'],
									allowCustom: false,
									tagsPredefinedOnly: true,
								}}
								inlinePortal
								value={featuresTags}
								onChange={(nextVal) => setFeaturesTags(Array.isArray(nextVal) ? nextVal.map((item) => String(item)) : [])}
							/>
							<SchemaField
								field={{
									id: 'boost-agents',
									name: 'boost_agents',
									type: 'tags',
									label: t('boost.targetAgentsLabel', 'Target Agents'),
									placeholder: t('boost.targetAgentsPlaceholder', 'Add agent (e.g. claude_code)'),
									suggestions: availableAgentSuggestions,
									allowCustom: true,
								}}
								inlinePortal
								value={agentsTags}
								onChange={(nextVal) => setAgentsTags(Array.isArray(nextVal) ? nextVal.map((item) => String(item)) : [])}
							/>
							<SchemaField
								field={{
									id: 'boost-modules',
									name: 'boost_modules',
									type: 'tags',
									label: t('boost.enabledModulesLabel', 'Enabled Modules'),
									suggestions: discoverableModules.map((mod) => mod.name),
									allowCustom: true,
								}}
								inlinePortal
								value={modulesTags}
								onChange={(nextVal) => setModulesTags(Array.isArray(nextVal) ? nextVal.map((item) => String(item)) : [])}
							/>
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
												boost_features: featuresTags.join(','),
												boost_agents: agentsTags.join(','),
												boost_modules: modulesTags.join(','),
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
						<SchemaField
							field={{
								id: 'mcp-tool-args',
								name: 'mcp_tool_args',
								type: 'textarea',
								editorMode: 'json',
								monospace: true,
								rows: 3,
								label: t('boost.jsonRpcArgsLabel', 'JSON-RPC Arguments'),
							}}
							inlinePortal
							value={mcpToolArgs}
							onChange={(nextVal) => setMcpToolArgs(typeof nextVal === 'string' ? nextVal : '')}
						/>
						<CliBox
							wrap
							command="php vendor/bin/wire boost:mcp"
							label={t('boost.mcpServerEntryLabel', 'MCP Server Entry')}
						/>
						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!(canRunReadOnlyMcp ?? isSuperuser) || busyAction !== null || !mcp.available}
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
							<SchemaField
								field={{
									id: 'make-module-type',
									name: 'make_module_type',
									type: 'segmented',
									label: t('boost.moduleStubTypeLabel', 'Module Stub Type'),
									description: activeModuleTypeOption?.description,
									options: moduleTypeOptions,
									fullWidth: true,
								}}
								inlinePortal
								value={newModuleType}
								onChange={(nextVal) => {
									if (typeof nextVal === 'string' && nextVal !== '') {
										setNewModuleType(nextVal);
									}
								}}
							/>
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
						<div className="flex flex-wrap items-center justify-between gap-2">
							<div>
								<CardTitle>{t('boost.installedSkillsCount', { count: installedSkills.length }, 'Installed Skills ({count})')}</CardTitle>
								<CardDescription>{t('boost.installedSkillsDesc', 'Select any skill to inspect its SKILL.md playbook')}</CardDescription>
							</div>
							<div className="flex flex-wrap gap-1.5">
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
							<Table className="min-w-[560px] table-fixed">
								<TableHeader>
									<TableRow>
										<TableHead className="w-44">{t('boost.toolNameHead', 'Tool Name')}</TableHead>
										<TableHead className="w-26">{t('boost.modeHead', 'Mode')}</TableHead>
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
