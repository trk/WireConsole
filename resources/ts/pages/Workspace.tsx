import { router, useEffect, useState } from '@pw/react/core';
import { AppShell, type AppShellRecordHeaderProps } from '@pw/react/layout';
import { Button, CodePreview, ConfirmDialog, Sheet } from '@pw/react/ui';
import { useKeyboardShortcuts, useTranslations } from '@pw/react/hooks';
import type { ConfirmDialogConfig, TabDefinition, WorkspaceProps } from '../types';
import { OverviewPanel } from '../panels/OverviewPanel';
import { MigrationsPanel } from '../panels/MigrationsPanel';
import { QueuesPanel } from '../panels/QueuesPanel';
import { SeedingPanel } from '../panels/SeedingPanel';
import { SchedulingPanel } from '../panels/SchedulingPanel';
import { BackupPanel } from '../panels/BackupPanel';
import { TestingPanel } from '../panels/TestingPanel';
import { BoostPanel } from '../panels/BoostPanel';
import { LogsPanel } from '../panels/LogsPanel';

interface ConfirmModalState extends ConfirmDialogConfig {
	open: boolean;
}

export function Workspace(props: WorkspaceProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [currentTab, setCurrentTab] = useState<string>(props.activeTab || 'overview');
	const [busyAction, setBusyAction] = useState<string | null>(null);
	const [dismissedFeedback, setDismissedFeedback] = useState(false);
	const [inspectorOpen, setInspectorOpen] = useState<boolean>(Boolean(props.inspectedFile));
	const [confirmState, setConfirmState] = useState<ConfirmModalState>({
		open: false,
		action: '',
		guardKey: '',
		confirmWord: 'RUN',
		title: '',
		description: '',
		variant: 'danger',
	});

	useEffect(() => {
		if (props.activeTab) {
			setCurrentTab(props.activeTab);
		}
	}, [props.activeTab]);

	useEffect(() => {
		setDismissedFeedback(false);
	}, [props.feedback]);

	useEffect(() => {
		if (props.inspectedFile) {
			setInspectorOpen(true);
		}
	}, [props.inspectedFile]);

	const tabsRecord: Record<string, TabDefinition> = props.tabs || {};

	const actionUrl: string = props.actionUrl || window.location.pathname;
	const downloadBackupUrl: string = props.downloadBackupUrl || '';
	const isDemoMode: boolean = Boolean(props.isDemoMode);
	const isSuperuser: boolean = Boolean(props.isSuperuser) && !isDemoMode;

	const runAction = (action: string, payload: Record<string, unknown> = {}) => {
		setBusyAction(action);
		const csrfToken = props.csrf?.value || '';
		router.post(
			actionUrl,
			{
				action,
				tab: currentTab,
				...(csrfToken ? { _token: csrfToken } : {}),
				...payload,
			} as NonNullable<Parameters<typeof router.post>[1]>,
			{
				preserveScroll: true,
				onFinish: () => {
					setBusyAction(null);
				},
			}
		);
	};

	const openConfirm = (config: ConfirmDialogConfig) => {
		setConfirmState({
			open: true,
			...config,
		});
	};

	const handleConfirmExecute = () => {
		const { action, guardKey, confirmWord, payload } = confirmState;
		setConfirmState((prev) => ({ ...prev, open: false }));
		runAction(action, {
			...(payload || {}),
			[`confirm_${guardKey}`]: confirmWord,
		});
	};

	const inspectFile = (category: string, file: string) => {
		if (isDemoMode) {
			const baseUrl = actionUrl.replace(/\/action\/?$/, '/');
			const targetUrl = currentTab === 'overview' ? baseUrl : `${baseUrl}${encodeURIComponent(currentTab)}/`;
			router.get(
				targetUrl,
				{
					inspect_category: category,
					inspect_file: file,
				},
				{
					preserveState: true,
					preserveScroll: true,
				}
			);
			return;
		}
		runAction('inspectFile', {
			inspect_category: category,
			inspect_file: file,
		});
	};

	const handleNavigateTab = (nextTab: string) => {
		if (!tabsRecord[nextTab]) {
			return;
		}
		setCurrentTab(nextTab);
		try {
			const baseUrl = actionUrl.replace(/\/action\/?$/, '/');
			const search = window.location.search || '';
			const targetUrl = (nextTab === 'overview' ? baseUrl : `${baseUrl}${encodeURIComponent(nextTab)}/`) + search;
			window.history.replaceState({}, '', targetUrl);
		} catch {
			// Ignore history state errors
		}
	};

	const handleFilterLogs = (channel: string, search: string, limit: number) => {
		const baseUrl = actionUrl.replace(/\/action\/?$/, '/');
		router.get(
			`${baseUrl}logs/`,
			{
				tab: 'logs',
				channel,
				search,
				limit,
			},
			{
				preserveState: true,
				preserveScroll: true,
			}
		);
	};

	const navItems = Object.entries(tabsRecord).map(([key, meta]) => {
		let badge: string | number | null = null;
		let badgeVariant: 'default' | 'primary' | 'secondary' | 'muted' | 'outline' | 'success' | 'warning' | 'danger' =
			'muted';

		if (key === 'migrations' && (props.overview?.migrations?.pending ?? 0) > 0) {
			badge = props.overview.migrations.pending;
			badgeVariant = 'warning';
		} else if (key === 'queues' && (props.overview?.queues?.failedCount ?? 0) > 0) {
			badge = props.overview.queues.failedCount;
			badgeVariant = 'danger';
		} else if (key === 'scheduling' && (props.overview?.scheduling?.dueCount ?? 0) > 0) {
			badge = props.overview.scheduling.dueCount;
			badgeVariant = 'warning';
		}

		return {
			key,
			label: meta.label,
			icon: meta.icon,
			badge,
			badgeVariant,
			active: currentTab === key,
			onClick: () => handleNavigateTab(key),
		};
	});

	const mappedFeedback =
		!dismissedFeedback && props.feedback
			? {
					type: (props.feedback.ok ? 'success' : 'danger') as 'success' | 'danger',
					title: props.feedback.ok
						? t('feedback.completed', { action: props.feedback.action }, 'Action Completed ({action})')
						: t('feedback.failed', { action: props.feedback.action }, 'Action Failed ({action})'),
					message: props.feedback.message,
					output: props.feedback.details || undefined,
					timestamp: props.feedback.timestamp,
				}
			: null;

	const helperProps = {
		actionUrl,
		activeTab: currentTab,
		isSuperuser,
		busyAction,
		runAction,
		openConfirm,
		inspectFile,
	};

	const activeTabMeta = tabsRecord[currentTab] || {
		label: t('workspace.title', 'Console'),
		description: t('workspace.subtitle', 'System health, database maintenance, background tasks, and diagnostics.'),
	};

	const isDown = Boolean(props.overview?.maintenance?.isDown);

	const handleRefreshTab = () => {
		if (busyAction !== null) {
			return;
		}
		const baseUrl = actionUrl.replace(/\/action\/?$/, '/');
		const search = window.location.search || '';
		const targetUrl =
			(currentTab === 'overview' ? baseUrl : `${baseUrl}${encodeURIComponent(currentTab)}/`) + search;
		router.get(targetUrl, {}, { preserveState: true, preserveScroll: true });
	};

	const shortcutsEnabled =
		props.workspace?.enableKeyboardShortcuts !== false &&
		busyAction === null &&
		!confirmState.open &&
		!(inspectorOpen && Boolean(props.inspectedFile));

	useKeyboardShortcuts([
		{
			id: 'wire-console.refresh',
			keys: 'r',
			label: t('workspace.refreshTelemetry', 'Refresh Status'),
			group: 'module',
			when: shortcutsEnabled,
			handler: handleRefreshTab,
		},
	]);

	const recordHeader: AppShellRecordHeaderProps = {
		title: activeTabMeta.label,
		subtitle: activeTabMeta.description,
		showHeadButtonsPortal: false,
		actions: (
			<Button
				variant="outline"
				size="sm"
				icon="refresh"
				disabled={busyAction !== null}
				onClick={handleRefreshTab}
			>
				{t('workspace.refreshTelemetry', 'Refresh Status')}
			</Button>
		),
	};

	return (
		<AppShell
			workspace={props.workspace}
			title={t('workspace.title', 'Console')}
			subtitle={t('workspace.subtitle', 'System health, database maintenance, background tasks, and diagnostics.')}
			icon="terminal"
			pills={[
				{
					label: isDown
						? t('workspace.maintenancePill', 'Maintenance Mode (503)')
						: t('workspace.onlinePill', 'System Online'),
					variant: isDown ? 'warning' : 'success',
				},
				...(isDemoMode
					? [
							{
								label: t('workspace.demoPill', 'Demo Mode'),
								variant: 'warning' as const,
							},
						]
					: []),
				{
					label: `PHP ${props.overview?.versions?.php ?? '8.4+'}`,
					variant: 'outline',
				},
				{
					label: `PW ${props.overview?.versions?.processwire ?? '3.0'}`,
					variant: 'outline',
				},
			]}
			navItems={navItems}
			activeNav={currentTab}
			feedback={mappedFeedback}
			onDismissFeedback={() => setDismissedFeedback(true)}
			recordHeader={recordHeader}
		>
			<div data-console-panel>
				{currentTab === 'overview' && (
					<OverviewPanel
						{...helperProps}
						overview={props.overview}
						accessibleTabs={Object.keys(tabsRecord)}
						onNavigateTab={handleNavigateTab}
					/>
				)}
				{currentTab === 'migrations' && (
					<MigrationsPanel {...helperProps} migrations={props.migrations} />
				)}
				{currentTab === 'queues' && (
					<QueuesPanel {...helperProps} queues={props.queues} />
				)}
				{currentTab === 'seeding' && (
					<SeedingPanel {...helperProps} seeding={props.seeding} />
				)}
				{currentTab === 'scheduling' && (
					<SchedulingPanel {...helperProps} scheduling={props.scheduling} />
				)}
				{currentTab === 'backup' && (
					<BackupPanel
						{...helperProps}
						backup={props.backup}
						downloadBackupUrl={downloadBackupUrl}
					/>
				)}
				{currentTab === 'testing' && (
					<TestingPanel {...helperProps} testing={props.testing} />
				)}
				{currentTab === 'boost' && (
					<BoostPanel {...helperProps} boost={props.boost} />
				)}
				{currentTab === 'logs' && (
					<LogsPanel
						{...helperProps}
						logs={props.logs}
						onFilterLogs={handleFilterLogs}
					/>
				)}
			</div>

			{/* Read-Only Source Code & File Inspector Sheet */}
			<Sheet
				open={inspectorOpen && Boolean(props.inspectedFile)}
				onOpenChange={setInspectorOpen}
				title={props.inspectedFile?.relativePath || t('inspector.title', 'Source Inspector')}
				subtitle={
					props.inspectedFile
						? t(
								'inspector.subtitle',
								{
									category: props.inspectedFile.category,
									lines: props.inspectedFile.lineCount,
									size: props.inspectedFile.sizeFormatted,
								},
								'{category} • {lines} lines • {size}'
							)
						: ''
				}
			>
				{props.inspectedFile && (
					<CodePreview
						title={props.inspectedFile.identifier}
						subtitle={props.inspectedFile.relativePath}
						badge={props.inspectedFile.sizeFormatted}
						content={props.inspectedFile.content}
						maxHeight="calc(100vh - 180px)"
					/>
				)}
			</Sheet>

			{/* Guarded Action Confirmation Dialog */}
			<ConfirmDialog
				open={confirmState.open}
				onOpenChange={(open: boolean) => setConfirmState((prev) => ({ ...prev, open }))}
				title={confirmState.title}
				description={confirmState.description}
				variant={confirmState.variant ?? 'danger'}
				confirmLabel={t('workspace.confirmLabel', 'Confirm')}
				loading={busyAction !== null}
				onConfirm={handleConfirmExecute}
			/>
		</AppShell>
	);
}
