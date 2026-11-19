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
import type {
	DiscoveredQueueHandler,
	FailedQueueJob,
	PendingQueueJob,
	QueuesPayload,
} from '../types';

interface QueuesPanelProps extends ActionHelperProps {
	queues: QueuesPayload | null;
}

export function QueuesPanel({
	queues,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: QueuesPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [workQueueName, setWorkQueueName] = useState('default');
	const [workMaxJobs, setWorkMaxJobs] = useState('5');
	const [workMaxTries, setWorkMaxTries] = useState('3');
	const [dispatchClass, setDispatchClass] = useState('');
	const [dispatchQueue, setDispatchQueue] = useState('default');
	const [dispatchDelay, setDispatchDelay] = useState('0');
	const [dispatchPayload, setDispatchPayload] = useState('{}');
	const [newQueueName, setNewQueueName] = useState('');
	const [purgeQueueName, setPurgeQueueName] = useState('all');

	if (!queues) {
		return (
			<EmptyState
				icon="stack-2"
				title={t('queues.unavailableTitle', 'Queues Section Unavailable')}
				description={t('queues.unavailableDescription', 'You do not have permission to view background queues.')}
			/>
		);
	}

	const discovered: DiscoveredQueueHandler[] = Array.isArray(queues.discoveredQueues) ? queues.discoveredQueues : [];
	const pendingJobs: PendingQueueJob[] = Array.isArray(queues.pendingJobs) ? queues.pendingJobs : [];
	const failedJobs: FailedQueueJob[] = Array.isArray(queues.failedJobs) ? queues.failedJobs : [];
	const dispatchOptions = [
		{ value: '', label: t('queues.selectHandlerPlaceholder', 'Select queue handler...') },
		...discovered.map((q) => ({ value: q.name, label: q.name })),
	];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					label={t('queues.queueTables', 'Queue Tables')}
					value={queues.tablesExist ? t('queues.ready', 'Ready') : t('migrations.notInstalled', 'Not Installed')}
					hint={t('queues.queueTablesHint', 'queue_jobs & failed_jobs')}
					icon="database"
					tone={queues.tablesExist ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('queues.pendingJobs', 'Pending Jobs')}
					value={queues.queuedCount ?? 0}
					hint={t('queues.pendingJobsHint', 'Waiting or reserved in queue_jobs')}
					icon="stack-2"
					tone={(queues.queuedCount ?? 0) > 0 ? 'warning' : 'default'}
				/>
				<StatCard
					label={t('queues.failedJobs', 'Failed Jobs')}
					value={queues.failedCount ?? 0}
					hint={
						(queues.failedCount ?? 0) > 0
							? t('queues.failedJobsAttentionHint', 'Requires retry or inspection')
							: t('queues.zeroFailedJobsHint', 'Zero failed jobs')
					}
					icon="alert-triangle"
					tone={(queues.failedCount ?? 0) > 0 ? 'danger' : 'success'}
				/>
				<StatCard
					label={t('queues.discoveredHandlers', 'Discovered Handlers')}
					value={discovered.length}
					hint={t('queues.discoveredHandlersHint', 'Classes in site/queue/')}
					icon="code"
				/>
			</div>

			{!queues.tablesExist && (
				<Card>
					<CardContent className="flex flex-wrap items-center justify-between gap-4 pt-5">
						<div>
							<div className="text-sm font-semibold">{t('queues.tablesRequiredTitle', 'Queue Database Tables Required')}</div>
							<div className="text-xs text-muted-foreground">
								{t('queues.tablesRequiredDesc', 'Install queue_jobs and failed_jobs before dispatching or processing jobs.')}
							</div>
						</div>
						<Button
							icon="database"
							disabled={!isSuperuser || busyAction !== null}
							onClick={() =>
								openConfirm({
									action: 'installQueueTables',
									guardKey: 'queues_install',
									confirmWord: 'RUN',
									variant: 'primary',
									title: t('queues.installQueueTables', 'Install Queue Tables'),
									description: t('queues.installQueueTablesDesc', 'Create the queue_jobs and failed_jobs database tables.'),
								})
							}
						>
							{t('queues.installQueueTables', 'Install Queue Tables')}
						</Button>
					</CardContent>
				</Card>
			)}

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
				{/* Work Queue Batch */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="player-play" className="h-4 w-4 text-primary" />
							<CardTitle>{t('queues.processBatchTitle', 'Process Queue Batch')}</CardTitle>
						</div>
						<CardDescription>{t('queues.processBatchDesc', 'Run a bounded synchronous worker batch (queue:work --once).')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="work-queue-name">{t('queues.queueNameLabel', 'Queue Name')}</Label>
							<Input
								id="work-queue-name"
								value={workQueueName}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setWorkQueueName(e.target.value)}
							/>
						</div>
						<div className="grid grid-cols-2 gap-3">
							<div className="space-y-1.5">
								<Label htmlFor="work-max-jobs">{t('queues.maxJobsLabel', 'Max Jobs (1-50)')}</Label>
								<Input
									id="work-max-jobs"
									type="number"
									min={1}
									max={50}
									value={workMaxJobs}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setWorkMaxJobs(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="work-max-tries">{t('queues.maxTriesLabel', 'Max Tries')}</Label>
								<Input
									id="work-max-tries"
									type="number"
									min={1}
									max={10}
									value={workMaxTries}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setWorkMaxTries(e.target.value)}
								/>
							</div>
						</div>
						<CliBox
							command={`php vendor/bin/wire queue:work --queue=${workQueueName || 'default'} --once`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
							className="[&_code]:overflow-visible! [&_code]:whitespace-normal! [&_code]:break-all!"
						/>
						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null || !queues.tablesExist}
								onClick={() =>
									openConfirm({
										action: 'workQueueBatch',
										guardKey: 'queues_work',
										confirmWord: 'WORK',
										variant: 'warning',
										title: t('queues.processBatchTitle', 'Process Queue Batch'),
										description: t(
											'queues.processBatchConfirmDesc',
											{ max: workMaxJobs, queue: workQueueName || 'default' },
											'Process up to {max} job(s) on queue "{queue}".'
										),
										payload: {
											work_queue_name: workQueueName || 'default',
											work_max_jobs: Number(workMaxJobs) || 5,
											work_max_tries: Number(workMaxTries) || 3,
										},
									})
								}
							>
								{t('queues.workBatch', 'Work Batch')}
							</Button>
						</div>
					</CardContent>
				</Card>

				{/* Dispatch Queue Job */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="send" className="h-4 w-4 text-primary" />
							<CardTitle>{t('queues.dispatchJobTitle', 'Dispatch Queue Job')}</CardTitle>
						</div>
						<CardDescription>{t('queues.dispatchJobDesc', 'Push a discovered queue handler onto queue_jobs.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-3">
						<div className="space-y-1.5">
							<Label htmlFor="dispatch-job-class">{t('queues.handlerClassLabel', 'Queue Handler Class')}</Label>
							<Select
								id="dispatch-job-class"
								value={dispatchClass}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setDispatchClass(e.target.value)}
								options={dispatchOptions}
							/>
						</div>
						<div className="grid grid-cols-2 gap-3">
							<div className="space-y-1.5">
								<Label htmlFor="dispatch-job-queue">{t('queues.queueShortLabel', 'Queue')}</Label>
								<Input
									id="dispatch-job-queue"
									value={dispatchQueue}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setDispatchQueue(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="dispatch-job-delay">{t('queues.delayLabel', 'Delay (sec)')}</Label>
								<Input
									id="dispatch-job-delay"
									type="number"
									min={0}
									value={dispatchDelay}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setDispatchDelay(e.target.value)}
								/>
							</div>
						</div>
						<div className="space-y-1.5">
							<Label htmlFor="dispatch-job-payload">{t('queues.jsonPayloadLabel', 'JSON Data')}</Label>
							<Textarea
								id="dispatch-job-payload"
								rows={2}
								className="font-mono text-xs"
								value={dispatchPayload}
								onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setDispatchPayload(e.target.value)}
							/>
						</div>
						<div className="flex justify-end">
							<Button
								icon="send"
								disabled={!isSuperuser || busyAction !== null || dispatchClass.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'dispatchQueueJob',
										guardKey: 'queues_dispatch',
										confirmWord: 'DISPATCH',
										variant: 'primary',
										title: t('queues.dispatchJobTitle', 'Dispatch Queue Job'),
										description: t(
											'queues.dispatchJobConfirmDesc',
											{ handler: dispatchClass, queue: dispatchQueue || 'default' },
											'Push {handler} onto queue "{queue}".'
										),
										payload: {
											dispatch_job_class: dispatchClass,
											dispatch_job_queue: dispatchQueue || 'default',
											dispatch_job_delay: Number(dispatchDelay) || 0,
											dispatch_job_payload: dispatchPayload,
										},
									})
								}
							>
								{t('queues.dispatchJob', 'Dispatch Job')}
							</Button>
						</div>
					</CardContent>
				</Card>

				{/* Scaffold & Maintenance */}
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="code" className="h-4 w-4 text-primary" />
							<CardTitle>{t('queues.scaffoldCleanupTitle', 'Scaffold & Queue Cleanup')}</CardTitle>
						</div>
						<CardDescription>{t('queues.scaffoldCleanupDesc', 'Create queue handlers in site/queue/ or purge queues.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="make-queue-name">{t('queues.newQueueClassLabel', 'New Queue Class Name')}</Label>
							<div className="flex gap-2">
								<Input
									id="make-queue-name"
									placeholder={t('queues.newQueuePlaceholder', 'e.g. SendWelcomeEmail')}
									value={newQueueName}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewQueueName(e.target.value)}
								/>
								<Button
									variant="secondary"
									icon="plus"
									disabled={!isSuperuser || busyAction !== null || newQueueName.trim() === ''}
									onClick={() =>
										openConfirm({
											action: 'makeQueueClass',
											guardKey: 'queues_make',
											confirmWord: 'CREATE',
											variant: 'primary',
											title: t('queues.scaffoldConfirmTitle', 'Scaffold Queue Class'),
											description: t(
												'queues.scaffoldConfirmDesc',
												{ name: newQueueName.trim() },
												'Create queue handler "{name}" in site/queue/.'
											),
											payload: { make_queue_name: newQueueName.trim() },
										})
									}
								>
									{t('workspace.create', 'Create')}
								</Button>
							</div>
						</div>

						<div className="space-y-1.5">
							<Label htmlFor="purge-queue-name">{t('queues.purgePendingLabel', 'Purge Pending Queue')}</Label>
							<div className="flex gap-2">
								<Input
									id="purge-queue-name"
									placeholder={t('queues.purgeQueuePlaceholder', 'all or queue name')}
									value={purgeQueueName}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setPurgeQueueName(e.target.value)}
								/>
								<Button
									variant="outline"
									icon="trash"
									disabled={!isSuperuser || busyAction !== null || (queues.queuedCount ?? 0) === 0}
									onClick={() =>
										openConfirm({
											action: 'purgePendingJobs',
											guardKey: 'queues_purge_pending',
											confirmWord: 'PURGE',
											variant: 'danger',
											title: t('queues.purgeConfirmTitle', 'Purge Pending Jobs'),
											description: t(
												'queues.purgeConfirmDesc',
												{ queue: purgeQueueName || 'all' },
												'Delete pending jobs from queue "{queue}".'
											),
											payload: { purge_queue_name: purgeQueueName || 'all' },
										})
									}
								>
									{t('workspace.purge', 'Purge')}
								</Button>
							</div>
						</div>

						<div className="flex flex-wrap items-center justify-between gap-2 pt-2">
							<Button
								variant="outline"
								size="sm"
								icon="rotate-2"
								disabled={!isSuperuser || busyAction !== null || (queues.failedCount ?? 0) === 0}
								onClick={() =>
									openConfirm({
										action: 'retryFailedJobs',
										guardKey: 'queues_retry',
										confirmWord: 'RETRY',
										variant: 'warning',
										title: t('queues.retryAllConfirmTitle', 'Retry All Failed Jobs'),
										description: t('queues.retryAllConfirmDesc', 'Push all failed jobs back onto their queues for retry.'),
										payload: { retry_target: 'all' },
									})
								}
							>
								{t('queues.retryAllFailed', 'Retry All Failed')}
							</Button>
							<Button
								variant="destructive"
								size="sm"
								icon="trash"
								disabled={!isSuperuser || busyAction !== null || (queues.failedCount ?? 0) === 0}
								onClick={() =>
									openConfirm({
										action: 'clearFailedJobs',
										guardKey: 'queues_clear',
										confirmWord: 'CLEAR',
										variant: 'danger',
										title: t('queues.clearFailedConfirmTitle', 'Clear All Failed Jobs'),
										description: t('queues.clearFailedConfirmDesc', 'Permanently delete all records in the failed_jobs table.'),
									})
								}
							>
								{t('queues.clearFailed', 'Clear Failed')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			{/* Failed Jobs Table */}
			{failedJobs.length > 0 && (
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center justify-between">
							<div>
								<CardTitle>{t('queues.failedJobsCountTitle', { count: failedJobs.length }, 'Failed Jobs ({count})')}</CardTitle>
								<CardDescription>{t('queues.failedJobsDesc', 'Failed queue executions recorded in failed_jobs')}</CardDescription>
							</div>
							<Badge variant="danger">{t('queues.failedCountBadge', { count: failedJobs.length }, '{count} Failed')}</Badge>
						</div>
					</CardHeader>
					<CardContent>
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('workspace.idHead', 'ID')}</TableHead>
									<TableHead>{t('queues.queueShortLabel', 'Queue')}</TableHead>
									<TableHead>{t('queues.handlerHead', 'Handler')}</TableHead>
									<TableHead>{t('queues.exceptionHead', 'Exception')}</TableHead>
									<TableHead>{t('queues.failedAtHead', 'Failed At')}</TableHead>
									<TableHead className="text-right">{t('workspace.actionsHead', 'Actions')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{failedJobs.map((job) => (
									<TableRow key={job.id}>
										<TableCell className="font-mono text-xs">#{job.id}</TableCell>
										<TableCell><Badge variant="outline">{job.queue}</Badge></TableCell>
										<TableCell className="font-mono text-xs font-medium">{job.jobClass}</TableCell>
										<TableCell className="max-w-md truncate font-mono text-xs text-destructive" title={job.exceptionFull}>
											{job.exceptionPreview}
										</TableCell>
										<TableCell className="text-xs text-muted-foreground">{job.failedAt}</TableCell>
										<TableCell className="text-right">
											<div className="inline-flex items-center gap-1">
												<Button
													variant="ghost"
													size="sm"
													icon="rotate-2"
													disabled={!isSuperuser || busyAction !== null}
													onClick={() =>
														openConfirm({
															action: 'retryFailedJobs',
															guardKey: 'queues_retry',
															confirmWord: 'RETRY',
															variant: 'warning',
															title: t('queues.retryOneConfirmTitle', { id: job.id }, 'Retry Failed Job #{id}'),
															description: t(
																'queues.retryOneConfirmDesc',
																{ id: job.id, handler: job.jobClass },
																'Re-queue failed job #{id} ({handler}).'
															),
															payload: { retry_target: String(job.id) },
														})
													}
												>
													{t('workspace.retry', 'Retry')}
												</Button>
												<Button
													variant="ghost"
													size="sm"
													icon="trash"
													disabled={!isSuperuser || busyAction !== null}
													onClick={() =>
														openConfirm({
															action: 'deleteFailedJob',
															guardKey: 'queues_delete_one',
															confirmWord: 'DELETE',
															variant: 'danger',
															title: t('queues.deleteOneConfirmTitle', { id: job.id }, 'Delete Failed Job #{id}'),
															description: t('queues.deleteOneConfirmDesc', { id: job.id }, 'Delete failed job #{id} from failed_jobs.'),
															payload: { delete_failed_id: job.id },
														})
													}
												>
													{t('workspace.delete', 'Delete')}
												</Button>
											</div>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</CardContent>
				</Card>
			)}

			{/* Pending Jobs & Discovered Handlers */}
			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card>
					<CardHeader className="pb-3">
						<CardTitle>{t('queues.pendingJobsTitle', { count: pendingJobs.length }, 'Pending Queue Jobs ({count})')}</CardTitle>
						<CardDescription>{t('queues.pendingJobsDesc', 'Active rows in queue_jobs')}</CardDescription>
					</CardHeader>
					<CardContent>
						{pendingJobs.length === 0 ? (
							<EmptyState
								icon="circle-check"
								title={t('queues.emptyQueueTitle', 'Queue is Empty')}
								description={t('queues.emptyQueueDescription', 'No pending or delayed jobs waiting in queue_jobs.')}
							/>
						) : (
							<Table style={{ tableLayout: 'fixed' }}>
								<TableHeader>
									<TableRow>
										<TableHead style={{ width: '4rem' }}>{t('workspace.idHead', 'ID')}</TableHead>
										<TableHead style={{ width: '5.5rem' }}>{t('queues.queueShortLabel', 'Queue')}</TableHead>
										<TableHead>{t('queues.handlerHead', 'Handler')}</TableHead>
										<TableHead style={{ width: '7.5rem' }}>{t('workspace.statusHead', 'Status')}</TableHead>
										<TableHead style={{ width: '8.5rem' }}>{t('queues.availableAtHead', 'Available At')}</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{pendingJobs.map((job) => (
										<TableRow key={job.id}>
											<TableCell className="truncate font-mono text-xs">#{job.id}</TableCell>
											<TableCell className="truncate"><Badge variant="outline">{job.queue}</Badge></TableCell>
											<TableCell className="truncate font-mono text-xs" title={job.jobClass}>{job.jobClass}</TableCell>
											<TableCell className="truncate">
												<Badge variant={job.status === 'ready' ? 'success' : 'warning'}>
													{t('queues.jobStatusTries', { status: job.status, attempts: job.attempts }, '{status} (tries: {attempts})')}
												</Badge>
											</TableCell>
											<TableCell className="truncate text-xs text-muted-foreground" title={job.availableAt}>{job.availableAt}</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						)}
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<CardTitle>{t('queues.discoveredHandlersTitle', { count: discovered.length }, 'Discovered Queue Handlers ({count})')}</CardTitle>
						<CardDescription>{t('queues.discoveredHandlersDesc', 'Classes discovered in site/queue/')}</CardDescription>
					</CardHeader>
					<CardContent>
						{discovered.length === 0 ? (
							<EmptyState
								icon="code"
								title={t('queues.noHandlersTitle', 'No Queue Handlers Discovered')}
								description={t('queues.noHandlersDescription', 'Scaffold a Queue class above or run php vendor/bin/wire make:queue.')}
							/>
						) : (
							<Table>
								<TableHeader>
									<TableRow>
										<TableHead>{t('queues.handlerNameHead', 'Handler Name')}</TableHead>
										<TableHead className="text-right">{t('workspace.inspectHead', 'Inspect')}</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{discovered.map((item) => (
										<TableRow key={item.name}>
											<TableCell className="font-mono text-xs font-medium">{item.name}</TableCell>
											<TableCell className="text-right">
												<Button
													variant="ghost"
													size="sm"
													icon="eye"
													onClick={() => inspectFile('queue', item.name)}
												>
													{t('workspace.source', 'Source')}
												</Button>
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						)}
					</CardContent>
				</Card>
			</div>
		</div>
	);
}
