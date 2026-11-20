import type * as React from 'react';
import { DynamicIcon, useState } from '@pw/react/core';
import {
	Alert,
	AlertDescription,
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	CliBox,
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
import { useTranslations } from '@pw/react/hooks';
import type { ActionHelperProps, MigrationItem, MigrationsPayload } from '../types';

interface MigrationsPanelProps extends ActionHelperProps {
	migrations: MigrationsPayload | null;
}

export function MigrationsPanel({
	migrations,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: MigrationsPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [steps, setSteps] = useState('0');
	const [rollbackSteps, setRollbackSteps] = useState('1');
	const [resetMode, setResetMode] = useState('refresh');
	const [resetPreBackup, setResetPreBackup] = useState(true);
	const [newMigrationName, setNewMigrationName] = useState('');
	const [migrationType, setMigrationType] = useState('blank');

	const resetModeOptions = [
		{ value: 'refresh', label: t('migrations.modeRefreshOption', 'Refresh (Rollback All + Re-run All)') },
		{ value: 'reset', label: t('migrations.modeResetOption', 'Reset (Rollback All Applied Migrations)') },
		{ value: 'fresh', label: t('migrations.modeFreshOption', 'Fresh (Drop Tracking Table + Re-run All)') },
	];

	const migrationStubOptions = [
		{ value: 'blank', label: t('migrations.stubBlankOption', 'Blank Migration (up / down)') },
		{ value: 'create-field', label: t('migrations.stubCreateFieldOption', 'Create Field (create-field)') },
		{ value: 'create-template', label: t('migrations.stubCreateTemplateOption', 'Create Template (create-template)') },
		{ value: 'attach-field', label: t('migrations.stubAttachFieldOption', 'Attach Field to Template (attach-field)') },
		{ value: 'create-page', label: t('migrations.stubCreatePageOption', 'Create Page (create-page)') },
		{ value: 'create-role', label: t('migrations.stubCreateRoleOption', 'Create Role (create-role)') },
		{ value: 'install-module', label: t('migrations.stubInstallModuleOption', 'Install Module (install-module)') },
	];

	if (!migrations) {
		return (
			<EmptyState
				icon="database"
				title={t('migrations.unavailableTitle', 'Migrations Section Unavailable')}
				description={t('migrations.unavailableDescription', 'You do not have permission to view schema migrations.')}
			/>
		);
	}

	const items: MigrationItem[] = Array.isArray(migrations.items) ? migrations.items : [];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					label={t('migrations.trackingTable', 'Tracking Table')}
					value={migrations.tableExists ? t('migrations.installed', 'Installed') : t('migrations.notInstalled', 'Not Installed')}
					hint={t('migrations.trackingTableHint', 'wire_migrations table')}
					icon="database"
					tone={migrations.tableExists ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('migrations.appliedMigrations', 'Applied Migrations')}
					value={migrations.ran ?? 0}
					hint={t('migrations.totalDiscoveredHint', { count: migrations.total ?? 0 }, 'Total discovered: {count}')}
					icon="circle-check"
					tone="success"
				/>
				<StatCard
					label={t('migrations.pendingMigrations', 'Pending Migrations')}
					value={migrations.pending ?? 0}
					hint={
						(migrations.pending ?? 0) > 0
							? t('migrations.readyToMigrate', 'Ready to migrate')
							: t('migrations.schemaUpToDate', 'Schema is up to date')
					}
					icon="clock"
					tone={(migrations.pending ?? 0) > 0 ? 'warning' : 'default'}
				/>
				<StatCard
					label={t('migrations.lastBatchNumber', 'Last Batch Number')}
					value={`#${migrations.lastBatch ?? 0}`}
					hint={t('migrations.rollbackPreviewHint', { count: (migrations.rollbackPreview ?? []).length }, '{count} in rollback preview')}
					icon="stack-2"
				/>
			</div>

			{/* Action Cards */}
			<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="database" className="h-4 w-4 text-primary" />
							<CardTitle>{t('migrations.runAndRollback', 'Run & Rollback')}</CardTitle>
						</div>
						<CardDescription>{t('migrations.runAndRollbackDesc', 'Execute pending migrations or roll back recent batches.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						{!migrations.tableExists && (
							<Alert variant="warning" className="flex items-center justify-between gap-3 p-3 text-xs">
								<AlertDescription>
									{t('migrations.tableMissingNotice', 'Tracking table wire_migrations is not installed.')}
								</AlertDescription>
								<Button
									size="sm"
									icon="database"
									disabled={!isSuperuser || busyAction !== null}
									onClick={() =>
										openConfirm({
											action: 'installMigrationsTable',
											guardKey: 'migrations_install',
											confirmWord: 'RUN',
											variant: 'primary',
											title: t('migrations.installTableConfirmTitle', 'Install Migrations Table'),
											description: t('migrations.installTableConfirmDesc', 'Create the wire_migrations tracking table in the database.'),
										})
									}
								>
									{t('migrations.installTable', 'Install Table')}
								</Button>
							</Alert>
						)}

						<div className="grid grid-cols-2 gap-3">
							<div className="space-y-1.5">
								<Label htmlFor="migration-steps">{t('migrations.migrateStepsLabel', 'Migrate Steps (0 = all)')}</Label>
								<Input
									id="migration-steps"
									type="number"
									min={0}
									value={steps}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSteps(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="rollback-steps">{t('migrations.rollbackStepsLabel', 'Rollback Steps')}</Label>
								<Input
									id="rollback-steps"
									type="number"
									min={1}
									value={rollbackSteps}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setRollbackSteps(e.target.value)}
								/>
							</div>
						</div>

						<CliBox
							wrap
							command="php vendor/bin/wire migrate"
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>

						<div className="flex flex-wrap items-center justify-end gap-2">
							<Button
								variant="outline"
								icon="rotate-2"
								disabled={!isSuperuser || busyAction !== null || (migrations.ran ?? 0) === 0}
								onClick={() =>
									openConfirm({
										action: 'rollbackMigrations',
										guardKey: 'migrations_rollback',
										confirmWord: 'ROLLBACK',
										variant: 'warning',
										title: t('migrations.rollbackConfirmTitle', 'Rollback Schema Migrations'),
										description: t('migrations.rollbackConfirmDesc', { steps: rollbackSteps }, 'Roll back {steps} migration step(s).'),
										payload: { rollback_steps: Number(rollbackSteps) || 1 },
									})
								}
							>
								{t('migrations.rollback', 'Rollback')}
							</Button>
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null}
								onClick={() =>
									openConfirm({
										action: 'runMigrations',
										guardKey: 'migrations_run',
										confirmWord: 'RUN',
										variant: 'warning',
										title: t('migrations.runPendingConfirmTitle', 'Run Pending Migrations'),
										description: t('migrations.runPendingConfirmDesc', 'Execute pending schema migrations in site/migrations/.'),
										payload: { migration_steps: Number(steps) || 0 },
									})
								}
							>
								{t('migrations.runPending', 'Run Pending')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="refresh" className="h-4 w-4 text-primary" />
							<CardTitle>{t('migrations.resetRefreshTitle', 'Reset, Refresh & Fresh')}</CardTitle>
						</div>
						<CardDescription>{t('migrations.resetRefreshDesc', 'Full migration cycle controls with optional SQL safety snapshot.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="reset-mode">{t('migrations.operationMode', 'Operation Mode')}</Label>
							<Select
								id="reset-mode"
								value={resetMode}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setResetMode(e.target.value)}
								options={resetModeOptions}
							/>
						</div>
						<Switch
							id="reset-pre-backup-switch"
							checked={resetPreBackup}
							onCheckedChange={setResetPreBackup}
							label={t('migrations.safetyBackupSwitch', 'Create SQL safety backup before running')}
						/>
						<CliBox
							wrap
							command={`php vendor/bin/wire migrate:${resetMode}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="destructive"
								icon="refresh"
								disabled={!isSuperuser || busyAction !== null}
								onClick={() =>
									openConfirm({
										action: 'resetOrRefreshMigrations',
										guardKey: 'migrations_reset',
										confirmWord: 'RESET',
										variant: 'danger',
										title: t('migrations.executeModeConfirmTitle', { mode: resetMode }, 'Execute migrate:{mode}'),
										description: resetPreBackup
											? t('migrations.executeModeWithBackupDesc', { mode: resetMode }, 'Run migrate:{mode} across all schema migrations with a pre-backup snapshot.')
											: t('migrations.executeModeWithoutBackupDesc', { mode: resetMode }, 'Run migrate:{mode} across all schema migrations without a pre-backup snapshot.'),
										payload: {
											reset_mode: resetMode,
											reset_pre_backup: resetPreBackup ? '1' : '0',
										},
									})
								}
							>
								{t('migrations.executeModeButton', { mode: resetMode }, 'Execute {mode}')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="plus" className="h-4 w-4 text-primary" />
							<CardTitle>{t('migrations.scaffoldMigration', 'Scaffold Migration')}</CardTitle>
						</div>
						<CardDescription>{t('migrations.scaffoldMigrationDesc', 'Create a new timestamped migration in site/migrations/.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="make-migration-name">{t('migrations.migrationIdentifier', 'Migration Identifier')}</Label>
							<Input
								id="make-migration-name"
								placeholder={t('migrations.migrationPlaceholder', 'e.g. create_product_fields')}
								value={newMigrationName}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewMigrationName(e.target.value)}
							/>
						</div>
						<div className="space-y-1.5">
							<Label htmlFor="make-migration-type">{t('migrations.migrationStubTypeLabel', 'Migration Stub Type')}</Label>
							<Select
								id="make-migration-type"
								value={migrationType}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setMigrationType(e.target.value)}
								options={migrationStubOptions}
							/>
						</div>
						<CliBox
							wrap
							command={`php vendor/bin/wire make:migration ${newMigrationName || 'create_product_fields'}${migrationType !== 'blank' ? ` --type=${migrationType}` : ''}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								icon="plus"
								disabled={!isSuperuser || busyAction !== null || newMigrationName.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'makeMigration',
										guardKey: 'migrations_make',
										confirmWord: 'CREATE',
										variant: 'primary',
										title: t('migrations.createMigrationConfirmTitle', 'Scaffold New Migration'),
										description: t(
											'migrations.createMigrationConfirmDesc',
											{ name: newMigrationName.trim() },
											'Create a new migration file for "{name}" in site/migrations/.'
										),
										payload: {
											make_migration_name: newMigrationName.trim(),
											make_migration_type: migrationType,
										},
									})
								}
							>
								{t('migrations.createMigration', 'Create Migration')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			{/* Migrations Status Table */}
			<Card>
				<CardHeader className="pb-3">
					<div className="flex items-center justify-between">
						<div>
							<CardTitle>{t('migrations.discoveredTitle', 'Discovered Schema Migrations')}</CardTitle>
							<CardDescription>{t('migrations.discoveredDesc', 'Files located in site/migrations/')}</CardDescription>
						</div>
						<Badge variant="outline">{t('migrations.filesBadgeCount', { count: items.length }, '{count} file(s)')}</Badge>
					</div>
				</CardHeader>
				<CardContent>
					{items.length === 0 ? (
						<EmptyState
							icon="database"
							title={t('migrations.emptyTitle', 'No Schema Migrations Found')}
							description={t('migrations.emptyDescription', 'Scaffold your first migration above or run php vendor/bin/wire make:migration.')}
						/>
					) : (
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('migrations.nameHead', 'Migration Name')}</TableHead>
									<TableHead>{t('workspace.statusHead', 'Status')}</TableHead>
									<TableHead>{t('migrations.batchHead', 'Batch')}</TableHead>
									<TableHead>{t('migrations.appliedAtHead', 'Applied At')}</TableHead>
									<TableHead className="text-right">{t('workspace.inspectHead', 'Inspect')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{items.map((item) => {
									const isRan = item.status === 'Ran';
									const isMissing = item.status === 'Missing';
									return (
										<TableRow key={item.name}>
											<TableCell className="font-mono text-xs font-medium">{item.name}</TableCell>
											<TableCell>
												<Badge variant={isRan ? 'success' : isMissing ? 'danger' : 'warning'}>
													{isRan
														? t('migrations.ranBadge', 'Ran')
														: isMissing
															? t('migrations.missingBadge', 'Missing')
															: t('migrations.pendingBadge', 'Pending')}
												</Badge>
											</TableCell>
											<TableCell className="font-mono text-xs text-muted-foreground">
												{item.batch ? `#${item.batch}` : '—'}
											</TableCell>
											<TableCell className="text-xs text-muted-foreground">
												{item.migrated_at ?? '—'}
											</TableCell>
											<TableCell className="text-right">
												<Button
													variant="ghost"
													size="sm"
													icon="eye"
													disabled={isMissing}
													onClick={() => inspectFile('migration', item.name)}
												>
													{t('workspace.source', 'Source')}
												</Button>
											</TableCell>
										</TableRow>
									);
								})}
							</TableBody>
						</Table>
					)}
				</CardContent>
			</Card>
		</div>
	);
}
