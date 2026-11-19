import type { WorkspaceSharedContext } from './runtime';

export interface TabDefinition {
	label: string;
	icon: string;
	permission: string;
	description: string;
}

export interface ActionFeedback {
	ok: boolean;
	action: string;
	message: string;
	details: string;
	timestamp: string;
}

export interface InspectedFile {
	category: string;
	identifier: string;
	relativePath: string;
	lineCount: number;
	sizeFormatted: string;
	content: string;
}

export interface PackageInfo {
	installed: boolean;
	version: string | null;
	declared: string | null;
	package: string;
	repo: string;
	command: string;
}

export interface PackagesStatus {
	rootPath: string;
	hasComposerJson: boolean;
	hasVendorDir: boolean;
	hasAutoload: boolean;
	hasWireBinary: boolean;
	wireBinaryPath: string;
	console: PackageInfo;
	boost: PackageInfo;
	allReady: boolean;
}

export interface VersionMatrix {
	php: string;
	processwire: string;
	database: string;
	inertia: string;
	workspace: string;
	console: string;
	boost: string;
}

export interface MaintenanceStatus {
	isDown: boolean;
	file: string;
	status: number;
	retry: number | null;
	redirect: string | null;
	hasSecret: boolean;
	sinceFormatted: string | null;
}

export interface OverviewPayload {
	packages: PackagesStatus;
	versions: VersionMatrix;
	migrations: {
		tableExists: boolean;
		total: number;
		ran: number;
		pending: number;
		lastBatch: number;
	};
	queues: {
		tablesExist: boolean;
		queuedCount: number;
		failedCount: number;
		discoveredCount: number;
	};
	seeding: {
		discoveredCount: number;
		hasFaker: boolean;
	};
	scheduling: {
		discoveredCount: number;
		dueCount: number;
	};
	backup: {
		totalCount: number;
		latestFile: string | null;
		latestTime: string | null;
		writable: boolean;
	};
	testing: {
		ready: boolean;
		testFileCount: number;
		e2eReady: boolean;
		e2eFileCount: number;
	};
	boost: {
		available: boolean;
		hasConfig: boolean;
		skillCount: number;
		agentCount: number;
		moduleCount: number;
		enabledModuleCount: number;
	};
	maintenance: MaintenanceStatus;
}

export interface MigrationItem {
	name: string;
	status: 'Ran' | 'Pending' | 'Missing' | string;
	batch: number | null;
	migrated_at: string | null;
}

export interface MigrationsPayload {
	available: boolean;
	tableExists: boolean;
	total: number;
	ran: number;
	pending: number;
	lastBatch: number;
	rollbackPreview: string[];
	items: MigrationItem[];
}

export interface DiscoveredQueueHandler {
	name: string;
	path: string;
}

export interface PendingQueueJob {
	id: number;
	queue: string;
	jobClass: string;
	attempts: number;
	status: string;
	availableAt: string;
	payloadFormatted: string;
}

export interface FailedQueueJob {
	id: number;
	queue: string;
	jobClass: string;
	failedAt: string;
	exceptionPreview: string;
	payloadFormatted: string;
	exceptionFull: string;
}

export interface QueuesPayload {
	available: boolean;
	tablesExist: boolean;
	queuedCount: number;
	failedCount: number;
	discoveredQueues: DiscoveredQueueHandler[];
	pendingJobs: PendingQueueJob[];
	failedJobs: FailedQueueJob[];
}

export interface SeederItem {
	name: string;
	class: string;
	path: string;
}

export interface SeedingPayload {
	hasFaker: boolean;
	seeders: SeederItem[];
}

export interface ScheduledTaskItem {
	name: string;
	class: string;
	path: string;
	expression: string;
	description: string;
	isDue: boolean;
	nextRun: string;
	lastTriggered: string;
}

export interface SchedulingPayload {
	tasks: ScheduledTaskItem[];
}

export interface BackupItem {
	name: string;
	sizeBytes: number;
	sizeFormatted: string;
	modified: number;
	modifiedFormatted: string;
}

export interface BackupPayload {
	directory: string;
	writable: boolean;
	items: BackupItem[];
}

export interface TestingEnvironment {
	ready: boolean;
	hasPestBinary: boolean;
	hasWireBinary: boolean;
	hasTestsDir: boolean;
	hasPhpunitXml: boolean;
	testFileCount: number;
	e2eReady: boolean;
	hasPlaywrightBinary: boolean;
	hasPlaywrightConfig: boolean;
	e2eFileCount: number;
	procOpenAvailable: boolean;
}

export interface DiscoveredTestFile {
	id: string;
	name: string;
	relativePath: string;
	path: string;
	suite: string;
	runner: string;
}

export interface TestRunResult {
	ok: boolean;
	exitCode?: number;
	output?: string;
	command?: string;
	message: string;
}

export interface TestingPayload {
	environment: TestingEnvironment;
	files: DiscoveredTestFile[];
	lastResult: TestRunResult | null;
}

export interface BoostModuleInfo {
	name: string;
	version: string;
	hasGuidelines: boolean;
	hasSkills: boolean;
	enabled: boolean;
}

export interface BoostStatus {
	available: boolean;
	version: string;
	hasConfig: boolean;
	hasMapJson: boolean;
	config: {
		version: string;
		guidelines: boolean;
		skills: boolean;
		mcp: boolean;
		modules: string[];
		agents: string[];
		generated_at: string | null;
	};
	detectedProjectAgents: string[];
	detectedSystemAgents: string[];
	availableAgents: Array<{
		name: string;
		displayName: string;
		guidelinesPath: string;
		enabled: boolean;
		detected: boolean;
	}>;
	discoverableModules: BoostModuleInfo[];
	enabledModuleCount: number;
	installedSkills: string[];
}

export interface McpToolInfo {
	name: string;
	description: string;
	isReadOnly: boolean;
}

export interface McpDiagnostics {
	available: boolean;
	protocolVersion: string;
	serverName: string;
	serverVersion: string;
	allowExecute: boolean;
	allowRestore: boolean;
	toolCount: number;
	readOnlyCount: number;
	mutationCount: number;
	tools: McpToolInfo[];
}

export interface McpToolResult {
	ok: boolean;
	tool?: string;
	isError?: boolean;
	outputFormatted?: string;
	message: string;
}

export interface BoostPayload {
	status: BoostStatus;
	mcp: McpDiagnostics;
	readOnlyTools: string[];
	mcpResult: McpToolResult | null;
}

export interface LogChannelInfo {
	name: string;
	sizeBytes: number;
	sizeFormatted: string;
	modified: number;
	modifiedFormatted: string;
	isAudit: boolean;
}

export interface LogEntryItem {
	date: string;
	user: string;
	url: string;
	text: string;
	audit: {
		action: string;
		status: string;
		ip: string;
		contextFormatted: string;
	} | null;
}

export interface LogsPayload {
	channels: LogChannelInfo[];
	selectedChannel: string;
	search: string;
	limit: number;
	entries: LogEntryItem[];
}

export interface WorkspaceProps {
	workspace?: WorkspaceSharedContext;
	activeTab: string;
	tabs: Record<string, TabDefinition>;
	isSuperuser: boolean;
	isDemoMode?: boolean;
	actionUrl: string;
	downloadBackupUrl: string;
	csrf?: {
		name: string;
		value: string;
	};
	feedback: ActionFeedback | null;
	inspectedFile: InspectedFile | null;
	overview: OverviewPayload;
	migrations: MigrationsPayload | null;
	queues: QueuesPayload | null;
	seeding: SeedingPayload | null;
	scheduling: SchedulingPayload | null;
	backup: BackupPayload | null;
	testing: TestingPayload | null;
	boost: BoostPayload | null;
	logs: LogsPayload | null;
}
