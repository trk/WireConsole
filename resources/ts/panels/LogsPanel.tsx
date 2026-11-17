import {
	React,
	useState,
	useEffect,
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
	EmptyState,
	DynamicIcon,
	useTranslations,
	type ActionHelperProps,
} from '../runtime';
import type { LogChannelInfo, LogEntryItem, LogsPayload } from '../types';

interface LogsPanelProps extends ActionHelperProps {
	logs: LogsPayload | null;
	onFilterLogs: (channel: string, search: string, limit: number) => void;
}

export function LogsPanel({
	logs,
	isSuperuser,
	busyAction,
	openConfirm,
	onFilterLogs,
}: LogsPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const channels: LogChannelInfo[] = Array.isArray(logs?.channels) ? logs.channels : [];
	const entries: LogEntryItem[] = Array.isArray(logs?.entries) ? logs.entries : [];

	const [selectedChannel, setSelectedChannel] = useState(logs?.selectedChannel ?? 'wire-console-audit');
	const [search, setSearch] = useState(logs?.search ?? '');
	const [limit, setLimit] = useState(String(logs?.limit ?? 50));

	useEffect(() => {
		if (logs?.selectedChannel) {
			setSelectedChannel(logs.selectedChannel);
		}
		if (typeof logs?.search === 'string') {
			setSearch(logs.search);
		}
		if (logs?.limit !== undefined) {
			setLimit(String(logs.limit));
		}
	}, [logs?.selectedChannel, logs?.search, logs?.limit]);

	if (!logs) {
		return (
			<EmptyState
				icon="list-details"
				title={t('logs.unavailableTitle', 'Logs & Audit Section Unavailable')}
				description={t('logs.unavailableDescription', 'You do not have permission to view Console audit trails or ProcessWire logs.')}
			/>
		);
	}

	const handleApplyFilter = (e: React.FormEvent<HTMLFormElement>) => {
		e.preventDefault();
		onFilterLogs(selectedChannel, search, Number(limit) || 50);
	};

	const channelOptions =
		channels.length > 0
			? channels.map((c) => ({
					value: c.name,
					label: `${c.name} (${c.sizeFormatted})`,
				}))
			: [{ value: 'wire-console-audit', label: 'wire-console-audit' }];

	const limitOptions = [25, 50, 100, 250].map((count) => ({
		value: String(count),
		label: t('logs.rowsOption', { count }, '{count} rows'),
	}));

	return (
		<div className="space-y-6">
			<Card>
				<CardHeader className="pb-3">
					<div className="flex flex-wrap items-center justify-between gap-2">
						<div>
							<div className="flex items-center gap-2">
								<DynamicIcon name="list-details" className="h-4 w-4 text-primary" />
								<CardTitle>{t('logs.title', 'Log Channels & Audit Trail')}</CardTitle>
							</div>
							<CardDescription>
								{t('logs.description', 'Inspect structured wire-console-audit entries and ProcessWire runtime logs.')}
							</CardDescription>
						</div>
						<div className="flex items-center gap-2">
							<Button
								variant="outline"
								size="sm"
								icon="trash"
								disabled={!isSuperuser || busyAction !== null || !selectedChannel}
								onClick={() =>
									openConfirm({
										action: 'clearLogChannel',
										guardKey: 'logs_clear',
										confirmWord: 'CLEAR',
										variant: 'danger',
										title: t('logs.clearChannelConfirmTitle', { channel: selectedChannel }, 'Clear Log Channel "{channel}"'),
										description: t('logs.clearChannelConfirmDesc', { channel: selectedChannel }, 'Truncate all entries in site/assets/logs/{channel}.txt.'),
										payload: { clear_log_channel: selectedChannel },
									})
								}
							>
								{t('logs.clearChannel', 'Clear Channel')}
							</Button>
							<Button
								variant="destructive"
								size="sm"
								icon="trash"
								disabled={!isSuperuser || busyAction !== null || channels.length === 0}
								onClick={() =>
									openConfirm({
										action: 'clearAllLogChannels',
										guardKey: 'logs_clear_all',
										confirmWord: 'CLEARALL',
										variant: 'danger',
										title: t('logs.clearAllConfirmTitle', 'Clear All Log Channels'),
										description: t('logs.clearAllConfirmDesc', 'Truncate all .txt log files in site/assets/logs/.'),
									})
								}
							>
								{t('logs.clearAllLogs', 'Clear All Logs')}
							</Button>
						</div>
					</div>
				</CardHeader>
				<CardContent>
					<form onSubmit={handleApplyFilter} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
						<div className="space-y-1.5">
							<Label htmlFor="log-channel-select">{t('logs.channelLabel', 'Channel')}</Label>
							<Select
								id="log-channel-select"
								value={selectedChannel}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => {
									const next = e.target.value;
									setSelectedChannel(next);
									onFilterLogs(next, search, Number(limit) || 50);
								}}
								options={channelOptions}
							/>
						</div>
						<div className="space-y-1.5 sm:col-span-2">
							<Label htmlFor="log-search-input">{t('logs.searchLabel', 'Substring Filter')}</Label>
							<Input
								id="log-search-input"
								placeholder={t('logs.searchPlaceholder', 'Search by user, IP, action, or message...')}
								value={search}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSearch(e.target.value)}
							/>
						</div>
						<div className="flex items-end gap-2">
							<div className="flex-1 space-y-1.5">
								<Label htmlFor="log-limit-select">{t('logs.limitLabel', 'Limit')}</Label>
								<Select
									id="log-limit-select"
									value={limit}
									onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setLimit(e.target.value)}
									options={limitOptions}
								/>
							</div>
							<Button type="submit" variant="secondary" icon="search">
								{t('logs.filter', 'Filter')}
							</Button>
						</div>
					</form>
				</CardContent>
			</Card>

			<Card>
				<CardHeader className="pb-3">
					<div className="flex items-center justify-between">
						<CardTitle>{t('logs.channelEntriesTitle', { channel: selectedChannel }, 'Channel Entries: {channel}')}</CardTitle>
						<Badge variant="outline">{t('logs.entriesCount', { count: entries.length }, '{count} entry(s)')}</Badge>
					</div>
				</CardHeader>
				<CardContent>
					{entries.length === 0 ? (
						<EmptyState
							icon="list-details"
							title={t('logs.emptyTitle', 'No Log Entries Found')}
							description={t('logs.emptyDescription', { channel: selectedChannel }, 'No matching log entries in channel "{channel}".')}
						/>
					) : (
						<Table style={{ tableLayout: 'fixed' }}>
							<TableHeader>
								<TableRow>
									<TableHead className="w-40">{t('logs.timestampHead', 'Timestamp')}</TableHead>
									<TableHead className="w-44">{t('logs.userHead', 'User')}</TableHead>
									<TableHead>{t('logs.messageHead', 'Event / Message')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{entries.map((entry, idx) => (
									<TableRow key={`${entry.date}-${idx}`}>
										<TableCell className="truncate font-mono text-xs text-muted-foreground">
											{entry.date}
										</TableCell>
										<TableCell className="truncate font-mono text-xs">
											{entry.user || '—'}
										</TableCell>
										<TableCell className="min-w-0 break-words text-xs">
											{entry.audit ? (
												<div className="space-y-1">
													<div className="flex flex-wrap items-center gap-2">
														<Badge variant="outline" className="font-mono">
															{entry.audit.action}
														</Badge>
														<Badge
															variant={
																/^(DENIED|FAILED|EXCEPTION|denied|invalid|error)/i.test(String(entry.audit.status))
																	? 'danger'
																	: 'success'
															}
														>
															{entry.audit.status}
														</Badge>
														{entry.audit.ip && (
															<span className="font-mono text-[11px] text-muted-foreground">
																{t('logs.ipPrefix', { ip: entry.audit.ip }, 'IP: {ip}')}
															</span>
														)}
													</div>
													{entry.audit.contextFormatted && (
														<pre
															style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}
															className="mt-1 break-all rounded bg-muted/50 p-2 font-mono text-[11px]"
														>
															{entry.audit.contextFormatted}
														</pre>
													)}
												</div>
											) : (
												<div className="font-mono break-all">{entry.text}</div>
											)}
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
