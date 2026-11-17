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
import type { ScheduledTaskItem, SchedulingPayload } from '../types';

interface SchedulingPanelProps extends ActionHelperProps {
	scheduling: SchedulingPayload | null;
}

export function SchedulingPanel({
	scheduling,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: SchedulingPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [selectedTask, setSelectedTask] = useState('due');
	const [newTaskName, setNewTaskName] = useState('');

	if (!scheduling) {
		return (
			<EmptyState
				icon="clock"
				title={t('scheduling.unavailableTitle', 'Scheduling Section Unavailable')}
				description={t('scheduling.unavailableDescription', 'You do not have permission to view scheduled tasks.')}
			/>
		);
	}

	const tasks: ScheduledTaskItem[] = Array.isArray(scheduling.tasks) ? scheduling.tasks : [];
	const dueCount = tasks.filter((taskItem) => taskItem.isDue).length;
	const taskOptions = [
		{ value: 'due', label: t('scheduling.allDueOption', 'All Currently Due Tasks (schedule:run)') },
		...tasks.map((taskItem) => ({ value: taskItem.name, label: `${taskItem.name} (${taskItem.expression})` })),
	];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
				<StatCard
					label={t('scheduling.scheduledTasks', 'Scheduled Tasks')}
					value={tasks.length}
					hint={t('scheduling.classesInScheduleHint', 'Classes in site/schedule/')}
					icon="clock"
				/>
				<StatCard
					label={t('scheduling.dueRightNow', 'Due Right Now')}
					value={dueCount}
					hint={dueCount > 0 ? t('scheduling.dueReadyHint', 'Ready to execute on schedule:run') : t('scheduling.noneDueHint', 'No tasks due at this minute')}
					icon="player-play"
					tone={dueCount > 0 ? 'warning' : 'success'}
				/>
				<StatCard
					label={t('scheduling.cronRunner', 'Cron Runner')}
					value="* * * * *"
					hint="php vendor/bin/wire schedule:run"
					icon="terminal"
				/>
			</div>

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="clock" className="h-4 w-4 text-primary" />
							<CardTitle>{t('scheduling.runTitle', 'Run Scheduled Tasks')}</CardTitle>
						</div>
						<CardDescription>{t('scheduling.runDescription', 'Trigger due cron tasks or run a specific task class immediately.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="schedule-task">{t('scheduling.targetScopeLabel', 'Target Scope')}</Label>
							<Select
								id="schedule-task"
								value={selectedTask}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setSelectedTask(e.target.value)}
								options={taskOptions}
							/>
						</div>
						<CliBox command="php vendor/bin/wire schedule:run" label={t('scheduling.cliCronEntryLabel', 'CLI Cron Entry')} />
						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null}
								onClick={() =>
									openConfirm({
										action: 'runScheduledTasks',
										guardKey: 'scheduling_run',
										confirmWord: 'RUN',
										variant: 'warning',
										title: t('scheduling.runTitle', 'Run Scheduled Tasks'),
										description: t('scheduling.runConfirmDesc', { target: selectedTask }, 'Execute scheduled task target "{target}".'),
										payload: { schedule_task: selectedTask },
									})
								}
							>
								{t('scheduling.runScheduler', 'Run Scheduler')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="plus" className="h-4 w-4 text-primary" />
							<CardTitle>{t('scheduling.scaffoldTitle', 'Scaffold Scheduled Task')}</CardTitle>
						</div>
						<CardDescription>{t('scheduling.scaffoldDesc', 'Create a new scheduled task class in site/schedule/.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="make-task-name">{t('scheduling.taskClassNameLabel', 'Task Class Name')}</Label>
							<Input
								id="make-task-name"
								placeholder={t('scheduling.taskPlaceholder', 'e.g. PruneExpiredSessionsTask')}
								value={newTaskName}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewTaskName(e.target.value)}
							/>
						</div>
						<CliBox
							command={`php vendor/bin/wire make:task ${newTaskName || 'PruneExpiredSessionsTask'}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								icon="plus"
								disabled={!isSuperuser || busyAction !== null || newTaskName.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'makeTaskClass',
										guardKey: 'scheduling_make',
										confirmWord: 'CREATE',
										variant: 'primary',
										title: t('scheduling.scaffoldTitle', 'Scaffold Scheduled Task'),
										description: t(
											'scheduling.scaffoldConfirmDesc',
											{ name: newTaskName.trim() },
											'Create task class "{name}" in site/schedule/.'
										),
										payload: { make_task_name: newTaskName.trim() },
									})
								}
							>
								{t('scheduling.createTask', 'Create Task')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			<Card>
				<CardHeader className="pb-3">
					<CardTitle>{t('scheduling.registeredTasksCount', { count: tasks.length }, 'Registered Cron Tasks ({count})')}</CardTitle>
					<CardDescription>{t('scheduling.registeredTasksDesc', 'Task schedules discovered in site/schedule/')}</CardDescription>
				</CardHeader>
				<CardContent>
					{tasks.length === 0 ? (
						<EmptyState
							icon="clock"
							title={t('scheduling.emptyTitle', 'No Scheduled Tasks Found')}
							description={t('scheduling.emptyDescription', 'Scaffold a Task class above to automate recurring jobs.')}
						/>
					) : (
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('scheduling.taskNameHead', 'Task Name')}</TableHead>
									<TableHead>{t('scheduling.cronExpressionHead', 'Cron Expression')}</TableHead>
									<TableHead>{t('workspace.statusHead', 'Status')}</TableHead>
									<TableHead>{t('scheduling.nextRunHead', 'Next Run')}</TableHead>
									<TableHead>{t('scheduling.lastTriggeredHead', 'Last Triggered')}</TableHead>
									<TableHead className="text-right">{t('workspace.inspectHead', 'Inspect')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{tasks.map((task) => (
									<TableRow key={task.name}>
										<TableCell className="font-mono text-xs font-medium">{task.name}</TableCell>
										<TableCell><Badge variant="outline" className="font-mono">{task.expression}</Badge></TableCell>
										<TableCell>
											<Badge variant={task.isDue ? 'warning' : 'secondary'}>
												{task.isDue ? t('scheduling.dueNowBadge', 'Due Now') : t('scheduling.scheduledBadge', 'Scheduled')}
											</Badge>
										</TableCell>
										<TableCell className="font-mono text-xs text-muted-foreground">{task.nextRun}</TableCell>
										<TableCell className="text-xs text-muted-foreground">{task.lastTriggered}</TableCell>
										<TableCell className="text-right">
											<Button
												variant="ghost"
												size="sm"
												icon="eye"
												onClick={() => inspectFile('task', task.name)}
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
	);
}
