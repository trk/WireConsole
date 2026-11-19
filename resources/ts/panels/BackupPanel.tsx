import {
	React,
	useState,
	Button,
	Card,
	CardHeader,
	CardTitle,
	CardDescription,
	CardContent,
	Label,
	Input,
	Switch,
	Table,
	TableHeader,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
	StatCard,
	CliBox,
	EmptyState,
	DynamicIcon,
	useTranslations,
	type ActionHelperProps,
} from '../runtime';
import type { BackupItem, BackupPayload } from '../types';

interface BackupPanelProps extends ActionHelperProps {
	backup: BackupPayload | null;
	downloadBackupUrl: string;
}

export function BackupPanel({
	backup,
	downloadBackupUrl,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: BackupPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [description, setDescription] = useState('');
	const [purgeDays, setPurgeDays] = useState('30');
	const [restorePreBackup, setRestorePreBackup] = useState(true);

	if (!backup) {
		return (
			<EmptyState
				icon="server"
				title={t('backup.unavailableTitle', 'Backups Section Unavailable')}
				description={t('backup.unavailableDescription', 'You do not have permission to view SQL database backups.')}
			/>
		);
	}

	const items: BackupItem[] = Array.isArray(backup.items) ? backup.items : [];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
				<StatCard
					label={t('backup.sqlSnapshots', 'SQL Snapshots')}
					value={items.length}
					hint={t('backup.storedInHint', 'Stored in site/assets/backups/database/')}
					icon="server"
				/>
				<StatCard
					label={t('backup.storageDirectory', 'Storage Directory')}
					value={backup.writable ? t('backup.writable', 'Writable') : t('backup.readOnly', 'Read-Only')}
					hint={backup.directory ?? 'site/assets/backups/database/'}
					icon="folder"
					tone={backup.writable ? 'success' : 'danger'}
				/>
				<StatCard
					label={t('backup.latestSnapshot', 'Latest Snapshot')}
					value={items[0]?.sizeFormatted ?? '—'}
					hint={items[0]?.modifiedFormatted ?? t('backup.noBackupsYetHint', 'No backups created yet')}
					icon="clock"
				/>
			</div>

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="server" className="h-4 w-4 text-primary" />
							<CardTitle>{t('backup.createTitle', 'Create Database Snapshot')}</CardTitle>
						</div>
						<CardDescription>{t('backup.createDesc', 'Generate a full SQL dump via WireDatabaseBackup.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="backup-description">{t('backup.descriptionLabel', 'Snapshot Description (optional)')}</Label>
							<Input
								id="backup-description"
								placeholder={t('backup.descriptionPlaceholder', 'e.g. Pre-release schema snapshot')}
								value={description}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setDescription(e.target.value)}
							/>
						</div>
						<CliBox
							command="php vendor/bin/wire db:backup"
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
							className="[&_code]:overflow-visible! [&_code]:whitespace-normal! [&_code]:break-all!"
						/>
						<div className="flex justify-end">
							<Button
								icon="server"
								disabled={!isSuperuser || busyAction !== null || !backup.writable}
								onClick={() =>
									openConfirm({
										action: 'createBackup',
										guardKey: 'backup_create',
										confirmWord: 'BACKUP',
										variant: 'primary',
										title: t('backup.createConfirmTitle', 'Create SQL Database Backup'),
										description: t('backup.createConfirmDesc', 'Dump all database tables to a new .sql snapshot in site/assets/backups/database/.'),
										payload: { backup_description: description.trim() },
									})
								}
							>
								{t('backup.createSnapshot', 'Create Snapshot')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="adjustments-horizontal" className="h-4 w-4 text-primary" />
							<CardTitle>{t('backup.retentionTitle', 'Retention & Restore Policy')}</CardTitle>
						</div>
						<CardDescription>{t('backup.retentionDescription', 'Purge aged SQL dumps and configure pre-restore safety snapshots.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="purge-days">{t('backup.purgeDaysLabel', 'Purge Backups Older Than (Days)')}</Label>
							<div className="flex gap-2">
								<Input
									id="purge-days"
									type="number"
									min={1}
									value={purgeDays}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setPurgeDays(e.target.value)}
								/>
								<Button
									variant="destructive"
									icon="trash"
									disabled={!isSuperuser || busyAction !== null || items.length === 0}
									onClick={() =>
										openConfirm({
											action: 'purgeBackups',
											guardKey: 'backup_purge',
											confirmWord: 'PURGE',
											variant: 'danger',
											title: t('backup.purgeConfirmTitle', 'Purge Old SQL Backups'),
											description: t('backup.purgeConfirmDesc', { days: purgeDays }, 'Delete SQL backup files older than {days} day(s).'),
											payload: { purge_days: Number(purgeDays) || 30 },
										})
									}
								>
									{t('workspace.purge', 'Purge')}
								</Button>
							</div>
						</div>

						<Switch
							id="restore-pre-backup-switch"
							checked={restorePreBackup}
							onCheckedChange={setRestorePreBackup}
							label={t('backup.safetyRestoreSwitch', 'Automatically create a safety backup before restoring any SQL file')}
						/>
						<CliBox
							command={`php vendor/bin/wire backup:purge --days=${purgeDays || 30}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
							className="[&_code]:overflow-visible! [&_code]:whitespace-normal! [&_code]:break-all!"
						/>
					</CardContent>
				</Card>
			</div>

			<Card>
				<CardHeader className="pb-3">
					<CardTitle>{t('backup.backupFilesCount', { count: items.length }, 'SQL Backup Files ({count})')}</CardTitle>
					<CardDescription>{t('backup.availableSnapshotsDesc', 'Available snapshots in site/assets/backups/database/')}</CardDescription>
				</CardHeader>
				<CardContent>
					{items.length === 0 ? (
						<EmptyState
							icon="server"
							title={t('backup.emptyTitle', 'No SQL Backups Found')}
							description={t('backup.emptyDescription', 'Create your first database snapshot above.')}
						/>
					) : (
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('backup.filenameHead', 'Filename')}</TableHead>
									<TableHead>{t('backup.sizeHead', 'Size')}</TableHead>
									<TableHead>{t('backup.createdAtHead', 'Created At')}</TableHead>
									<TableHead className="text-right">{t('workspace.actionsHead', 'Actions')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{items.map((item) => (
									<TableRow key={item.name}>
										<TableCell className="font-mono text-xs font-medium">{item.name}</TableCell>
										<TableCell className="font-mono text-xs text-muted-foreground">{item.sizeFormatted}</TableCell>
										<TableCell className="text-xs text-muted-foreground">{item.modifiedFormatted}</TableCell>
										<TableCell className="text-right">
											<div className="inline-flex items-center gap-1">
												<Button
													variant="ghost"
													size="sm"
													icon="eye"
													disabled={!isSuperuser || busyAction !== null}
													onClick={() => inspectFile('backup', item.name)}
												>
													{t('workspace.preview', 'Preview')}
												</Button>
												{isSuperuser && downloadBackupUrl && (
													<a
														href={`${downloadBackupUrl}?file=${encodeURIComponent(item.name)}`}
														className="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-foreground hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
													>
														<DynamicIcon name="download" className="h-3.5 w-3.5" />
														{t('workspace.download', 'Download')}
													</a>
												)}
												<Button
													variant="destructive"
													size="sm"
													icon="rotate-2"
													disabled={!isSuperuser || busyAction !== null}
													onClick={() =>
														openConfirm({
															action: 'restoreBackup',
															guardKey: 'backup_restore',
															confirmWord: 'RESTORE',
															variant: 'danger',
															title: t('backup.restoreConfirmTitle', { file: item.name }, 'Restore {file}'),
															description: restorePreBackup
																? t('backup.restoreWithBackupDesc', { file: item.name }, 'Overwrite the current database with "{file}" (a safety snapshot will be created first).')
																: t('backup.restoreWithoutBackupDesc', { file: item.name }, 'Overwrite the current database with "{file}" without a pre-restore safety snapshot.'),
															payload: {
																restore_file: item.name,
																restore_pre_backup: restorePreBackup ? '1' : '0',
															},
														})
													}
												>
													{t('backup.restore', 'Restore')}
												</Button>
											</div>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					)}
				</CardContent>
			</Card>
		</div>
	);
}
