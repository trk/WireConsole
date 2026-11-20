import type * as React from 'react';
import { DynamicIcon, useState } from '@pw/react/core';
import {
	Alert,
	AlertDescription,
	AlertTitle,
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	CliBox,
	Input,
	Label,
	Select,
	StatCard,
} from '@pw/react/ui';
import { useTranslations } from '@pw/react/hooks';
import type { ActionHelperProps, OverviewPayload } from '../types';

interface OverviewPanelProps extends ActionHelperProps {
	overview: OverviewPayload;
	accessibleTabs?: string[];
	onNavigateTab: (tab: string) => void;
}

export function OverviewPanel({
	overview,
	accessibleTabs,
	isSuperuser,
	busyAction,
	openConfirm,
	onNavigateTab,
}: OverviewPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const canAccessTab = (tab: string) => !accessibleTabs || accessibleTabs.includes(tab);
	const [maintenanceSecret, setMaintenanceSecret] = useState('');
	const [maintenanceRetry, setMaintenanceRetry] = useState('3600');
	const [cacheMode, setCacheMode] = useState('all');

	const cacheModeOptions = [
		{ value: 'all', label: t('overview.cacheAll', 'All Caches (Modules + FileCompiler + WireCache)') },
		{ value: 'core', label: t('overview.cacheCore', 'Core & FileCompiler Only (cache:clear)') },
		{ value: 'wire', label: t('overview.cacheWire', 'WireCache Database Entries Only (cache:wire:clear)') },
		{ value: 'modules', label: t('overview.cacheModules', 'Refresh Module Registry (module:refresh)') },
	];

	const {
		maintenance,
		packages,
		versions,
		migrations,
		queues,
		seeding,
		scheduling,
		backup,
		testing,
		boost,
	} = overview;

	return (
		<div className="space-y-6">
			{/* KPI Grid */}
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					label={t('overview.schemaMigrations', 'Schema Migrations')}
					value={`${migrations.ran ?? 0} / ${migrations.total ?? 0}`}
					hint={
						(migrations.pending ?? 0) > 0
							? t('overview.pendingMigrationsCount', { count: migrations.pending }, '{count} pending migration(s)')
							: t('overview.allMigrationsApplied', 'All schema migrations applied')
					}
					icon="database"
					tone={(migrations.pending ?? 0) > 0 ? 'warning' : 'success'}
					onClick={canAccessTab('migrations') ? () => onNavigateTab('migrations') : undefined}
				/>

				<StatCard
					label={t('overview.backgroundQueues', 'Background Queues')}
					value={t('overview.queuedCount', { count: queues.queuedCount ?? 0 }, '{count} queued')}
					hint={
						(queues.failedCount ?? 0) > 0
							? t('overview.failedJobsAttention', { count: queues.failedCount }, '{count} failed job(s) require attention')
							: t('overview.discoveredHandlersCount', { count: queues.discoveredCount ?? 0 }, '{count} discovered handler(s)')
					}
					icon="stack-2"
					tone={(queues.failedCount ?? 0) > 0 ? 'danger' : 'default'}
					onClick={canAccessTab('queues') ? () => onNavigateTab('queues') : undefined}
				/>

				<StatCard
					label={t('overview.databaseBackups', 'Database Backups')}
					value={t('overview.snapshotsCount', { count: backup.totalCount ?? 0 }, '{count} snapshots')}
					hint={
						backup.latestFile
							? t('overview.latestSnapshot', { file: backup.latestFile }, 'Latest: {file}')
							: t('overview.noSnapshotsYet', 'No SQL snapshots yet')
					}
					icon="server"
					tone={backup.writable ? 'success' : 'warning'}
					onClick={canAccessTab('backup') ? () => onNavigateTab('backup') : undefined}
				/>

				<StatCard
					label={t('overview.boostAndSkills', 'Boost & AI Skills')}
					value={t('overview.skillsCount', { count: boost.skillCount ?? 0 }, '{count} skills')}
					hint={t(
						'overview.boostSummaryHint',
						{
							agents: boost.agentCount ?? 0,
							enabled: boost.enabledModuleCount ?? 0,
							total: boost.moduleCount ?? 0,
						},
						'{agents} agent(s), {enabled}/{total} module(s)'
					)}
					icon="sparkles"
					tone={boost.available ? 'success' : 'warning'}
					onClick={canAccessTab('boost') ? () => onNavigateTab('boost') : undefined}
				/>
			</div>

			{/* Operations Control Row: Maintenance Mode & System Caches */}
			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card className="min-w-0">
					<CardHeader className="pb-3">
						<div className="flex items-center justify-between gap-2">
							<div className="flex items-center gap-2">
								<DynamicIcon name="shield" className="h-4 w-4 text-primary" />
								<CardTitle>{t('overview.maintenanceTitle', 'Maintenance Mode')}</CardTitle>
							</div>
							<Badge variant={maintenance.isDown ? 'warning' : 'success'}>
								{maintenance.isDown
									? t('overview.maintenanceActiveBadge', 'Maintenance Active (503)')
									: t('overview.applicationOnlineBadge', 'Application Online')}
							</Badge>
						</div>
						<CardDescription>
							{t('overview.maintenanceDescription', 'Temporarily pause public site access with a friendly maintenance screen.')}
						</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						{maintenance.isDown ? (
							<div className="space-y-3">
								<Alert variant="warning" className="text-xs">
									<AlertTitle>{t('overview.maintenanceAlertTitle', 'Application is currently in maintenance mode')}</AlertTitle>
									<AlertDescription className="mt-1 opacity-90">
										{t(
											'overview.maintenanceSinceSummary',
											{
												since: maintenance.sinceFormatted ?? t('overview.unknownTime', 'Unknown'),
												status: maintenance.status ?? 503,
											},
											'Since: {since} • Status: HTTP {status}'
										)}
										{maintenance.retry
											? ` • ${t('overview.maintenanceRetrySuffix', { seconds: maintenance.retry }, 'Retry-After: {seconds}s')}`
											: ''}
									</AlertDescription>
								</Alert>
								<CliBox
									wrap
									command="php vendor/bin/wire up"
									label={t('workspace.cliEquivalent', 'CLI Equivalent')}
								/>
								<div className="flex justify-end">
									<Button
										variant="default"
										icon="player-play"
										disabled={!isSuperuser || busyAction !== null}
										onClick={() =>
											openConfirm({
												action: 'disableMaintenanceMode',
												guardKey: 'system_up',
												confirmWord: 'UP',
												variant: 'primary',
												title: t('overview.bringOnline', 'Bring Application Online'),
												description: t('overview.bringOnlineDesc', 'Remove site/down.json and restore normal HTTP traffic immediately.'),
											})
										}
									>
										{t('overview.bringOnline', 'Bring Application Online')}
									</Button>
								</div>
							</div>
						) : (
							<div className="space-y-3">
								<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
									<div className="space-y-1.5">
										<Label htmlFor="maintenance-secret">{t('overview.bypassSecretLabel', 'Bypass Secret Token (optional)')}</Label>
										<Input
											id="maintenance-secret"
											placeholder={t('overview.bypassSecretPlaceholder', 'e.g. deploy-preview-token')}
											value={maintenanceSecret}
											onChange={(e: React.ChangeEvent<HTMLInputElement>) => setMaintenanceSecret(e.target.value)}
										/>
									</div>
									<div className="space-y-1.5">
										<Label htmlFor="maintenance-retry">{t('overview.retryAfterLabel', 'Retry-After Seconds')}</Label>
										<Input
											id="maintenance-retry"
											type="number"
											min={60}
											value={maintenanceRetry}
											onChange={(e: React.ChangeEvent<HTMLInputElement>) => setMaintenanceRetry(e.target.value)}
										/>
									</div>
								</div>
								<CliBox
									wrap
									command={`php vendor/bin/wire down --retry=${maintenanceRetry || 3600}`}
									label={t('workspace.cliEquivalent', 'CLI Equivalent')}
								/>
								<div className="flex justify-end">
									<Button
										variant="destructive"
										icon="power"
										disabled={!isSuperuser || busyAction !== null}
										onClick={() =>
											openConfirm({
												action: 'enableMaintenanceMode',
												guardKey: 'system_down',
												confirmWord: 'DOWN',
												variant: 'warning',
												title: t('overview.enableMaintenance', 'Enable Maintenance Mode'),
												description: t('overview.enableMaintenanceDesc', 'Create site/down.json and intercept public HTTP traffic with a 503 response.'),
												payload: {
													maintenance_secret: maintenanceSecret,
													maintenance_retry: Number(maintenanceRetry) || 3600,
												},
											})
										}
									>
										{t('overview.enableMaintenance', 'Enable Maintenance Mode')}
									</Button>
								</div>
							</div>
						)}
					</CardContent>
				</Card>

				<Card className="min-w-0">
					<CardHeader className="pb-3">
						<div className="flex items-center justify-between gap-2">
							<div className="flex items-center gap-2">
								<DynamicIcon name="bolt" className="h-4 w-4 text-primary" />
								<CardTitle>{t('overview.cacheFlushTitle', 'System & WireCache Flush')}</CardTitle>
							</div>
							<Badge variant="outline">{t('overview.cacheControlsBadge', 'Cache Controls')}</Badge>
						</div>
						<CardDescription>
							{t('overview.cacheFlushDescription', 'Clear template, module, and database caches when updates do not appear right away.')}
						</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="cache-clear-mode">{t('overview.cacheTargetScope', 'Cache Target Scope')}</Label>
							<Select
								id="cache-clear-mode"
								value={cacheMode}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setCacheMode(e.target.value)}
								options={cacheModeOptions}
							/>
						</div>
						<CliBox
							wrap
							command={
								cacheMode === 'wire'
									? "php vendor/bin/wire cache:wire:clear --pattern='%'"
									: cacheMode === 'modules'
										? 'php vendor/bin/wire module:refresh'
										: 'php vendor/bin/wire cache:clear'
							}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								icon="refresh"
								disabled={!isSuperuser || busyAction !== null}
								onClick={() =>
									openConfirm({
										action: 'clearSystemCaches',
										guardKey: 'system_cache_clear',
										confirmWord: 'CACHE',
										variant: 'warning',
										title: t('overview.flushSelectedCaches', 'Flush Selected Caches'),
										description: t(
											'overview.flushSelectedCachesDesc',
											{ scope: cacheMode },
											'Clear cache scope "{scope}" across the ProcessWire runtime.'
										),
										payload: {
											cache_clear_mode: cacheMode,
										},
									})
								}
							>
								{t('overview.flushSelectedCaches', 'Flush Selected Caches')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			{/* Subsystem Quick Summary & Runtime Version Matrix */}
			<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
				<Card className="min-w-0 lg:col-span-2">
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="package" className="h-4 w-4 text-primary" />
							<CardTitle>{t('overview.packagesReadinessTitle', 'Composer Packages & Subsystem Readiness')}</CardTitle>
						</div>
						<CardDescription>
							{t(
								'overview.packagesReadinessDesc',
								{ path: packages.rootPath ?? '/' },
								'Installed CLI and AI tooling status in {path}'
							)}
						</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
							<div className="rounded-lg border border-border bg-muted/20 p-3.5">
								<div className="flex items-center justify-between">
									<span className="font-mono text-xs font-semibold text-foreground">trk/processwire-console</span>
									<Badge variant={packages.console?.installed ? 'success' : 'warning'}>
										{packages.console?.version ?? t('migrations.notInstalled', 'Not Installed')}
									</Badge>
								</div>
								<p className="mt-1.5 text-xs text-muted-foreground">
									{t(
										'overview.cliBinaryStatus',
										{
											status: packages.hasWireBinary
												? t('queues.ready', 'Ready')
												: t('workspace.missing', 'Missing'),
										},
										'CLI binary: vendor/bin/wire ({status})'
									)}
								</p>
							</div>

							<div className="rounded-lg border border-border bg-muted/20 p-3.5">
								<div className="flex items-center justify-between">
									<span className="font-mono text-xs font-semibold text-foreground">trk/processwire-boost</span>
									<Badge variant={packages.boost?.installed ? 'success' : 'warning'}>
										{packages.boost?.version ?? t('migrations.notInstalled', 'Not Installed')}
									</Badge>
								</div>
								<p className="mt-1.5 text-xs text-muted-foreground">
									{t('overview.boostPackageDesc', 'AI Guidelines, Skills & 28-Tool JSON-RPC MCP Server')}
								</p>
							</div>
						</div>

						<div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
							<button
								type="button"
								disabled={!canAccessTab('seeding')}
								onClick={() => onNavigateTab('seeding')}
								className="cursor-pointer rounded-lg border border-border p-3 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
							>
								<div className="text-xs text-muted-foreground">{t('overview.databaseSeedersCard', 'Database Seeders')}</div>
								<div className="mt-1 text-lg font-semibold tabular-nums">{seeding.discoveredCount ?? 0}</div>
								<div className="text-[11px] text-muted-foreground">
									{t(
										'overview.fakerStatus',
										{
											status: seeding.hasFaker
												? t('workspace.available', 'Available')
												: t('workspace.missing', 'Missing'),
										},
										'FakerPHP: {status}'
									)}
								</div>
							</button>

							<button
								type="button"
								disabled={!canAccessTab('scheduling')}
								onClick={() => onNavigateTab('scheduling')}
								className="cursor-pointer rounded-lg border border-border p-3 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
							>
								<div className="text-xs text-muted-foreground">{t('overview.scheduledTasksCard', 'Scheduled Tasks')}</div>
								<div className="mt-1 text-lg font-semibold tabular-nums">{scheduling.discoveredCount ?? 0}</div>
								<div className="text-[11px] text-muted-foreground">
									{t('overview.dueNowCount', { count: scheduling.dueCount ?? 0 }, 'Due now: {count}')}
								</div>
							</button>

							<button
								type="button"
								disabled={!canAccessTab('testing')}
								onClick={() => onNavigateTab('testing')}
								className="cursor-pointer rounded-lg border border-border p-3 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
							>
								<div className="text-xs text-muted-foreground">{t('overview.testSuitesCard', 'Automated Tests')}</div>
								<div className="mt-1 text-lg font-semibold tabular-nums">
									{t(
										'overview.testSuitesBreakdown',
										{
											pest: testing.testFileCount ?? 0,
											e2e: testing.e2eFileCount ?? 0,
										},
										'{pest} Pest • {e2e} E2E'
									)}
								</div>
								<div className="text-[11px] text-muted-foreground">
									{t(
										'overview.runnersStatusSummary',
										{
											pest: testing.ready
												? t('queues.ready', 'Ready')
												: t('workspace.unavailable', 'Unavailable'),
											e2e: testing.e2eReady
												? t('queues.ready', 'Ready')
												: t('workspace.unavailable', 'Unavailable'),
										},
										'Pest: {pest} • E2E: {e2e}'
									)}
								</div>
							</button>

							<button
								type="button"
								disabled={!canAccessTab('logs')}
								onClick={() => onNavigateTab('logs')}
								className="cursor-pointer rounded-lg border border-border p-3 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
							>
								<div className="text-xs text-muted-foreground">{t('overview.securityAuditCard', 'Security & Audit')}</div>
								<div className="mt-1 text-lg font-semibold">{t('overview.rbacRateLimitLabel', 'RBAC + Rate Limit')}</div>
								<div className="text-[11px] text-muted-foreground">wire-console-audit</div>
							</button>
						</div>
					</CardContent>
				</Card>

				<Card className="min-w-0">
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="cpu" className="h-4 w-4 text-primary" />
							<CardTitle>{t('overview.runtimeVersionsTitle', 'Runtime Stack Versions')}</CardTitle>
						</div>
						<CardDescription>{t('overview.runtimeVersionsDescription', 'Active environment version matrix')}</CardDescription>
					</CardHeader>
					<CardContent>
						<dl className="divide-y divide-border text-xs">
							{[
								{ label: t('overview.phpRuntimeLabel', 'PHP Runtime'), value: versions.php ?? '—' },
								{ label: t('overview.processwireCoreLabel', 'ProcessWire Core'), value: versions.processwire ?? '—' },
								{ label: t('overview.databaseEngineLabel', 'Database Engine'), value: versions.database ?? '—' },
								{ label: t('overview.inertiaRuntimeLabel', 'Inertia Runtime'), value: versions.inertia ?? '—' },
								{ label: t('overview.workspaceRuntimeLabel', 'React Workspace Runtime'), value: versions.workspace ?? '—' },
								{ label: t('overview.consolePackageLabel', 'Console Package'), value: versions.console ?? '—' },
								{ label: t('overview.boostPackageLabel', 'Boost Package'), value: versions.boost ?? '—' },
							].map((row) => (
								<div key={row.label} className="flex items-center justify-between py-2">
									<dt className="text-muted-foreground">{row.label}</dt>
									<dd className="font-mono font-medium text-foreground">{row.value}</dd>
								</div>
							))}
						</dl>
					</CardContent>
				</Card>
			</div>
		</div>
	);
}
