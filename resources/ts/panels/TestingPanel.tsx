import type * as React from 'react';
import { DynamicIcon, useState } from '@pw/react/core';
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
	Combobox,
	EmptyState,
	Input,
	Label,
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
import type { ActionHelperProps, DiscoveredTestFile, TestingPayload } from '../types';

interface TestingPanelProps extends ActionHelperProps {
	testing: TestingPayload | null;
}

export function TestingPanel({
	testing,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: TestingPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [testPath, setTestPath] = useState('');
	const [testFilter, setTestFilter] = useState('');
	const [testGroup, setTestGroup] = useState('');
	const [stopOnFailure, setStopOnFailure] = useState(false);

	const [e2ePath, setE2ePath] = useState('');
	const [e2eModule, setE2eModule] = useState('');
	const [e2eGrep, setE2eGrep] = useState('');

	const [testKind, setTestKind] = useState('feature');
	const [newTestName, setNewTestName] = useState('');
	const [newTestModule, setNewTestModule] = useState('');

	if (!testing) {
		return (
			<EmptyState
				icon="flask"
				title={t('testing.unavailableTitle', 'Testing Section Unavailable')}
				description={t('testing.unavailableDescription', 'You do not have permission to view the automated test runners.')}
			/>
		);
	}

	const env = testing.environment;
	const files: DiscoveredTestFile[] = Array.isArray(testing.files) ? testing.files : [];
	const pestFiles = files.filter((f) => f.runner !== 'playwright');
	const e2eFiles = files.filter((f) => f.runner === 'playwright');
	const lastResult = testing.lastResult ?? null;

	const testPathOptions = pestFiles.map((f) => ({
		value: f.relativePath,
		label: f.name,
		description: `${f.suite} • ${f.relativePath}`,
	}));

	const e2ePathOptions = e2eFiles.map((f) => ({
		value: f.relativePath,
		label: f.name,
		description: `${f.suite} • ${f.relativePath}`,
	}));

	const testKindOptions = [
		{
			value: 'feature',
			label: t('testing.kindFeatureShort', 'Feature'),
			description: t('testing.kindFeatureOption', 'Pest Feature Test (Feature/)'),
		},
		{
			value: 'unit',
			label: t('testing.kindUnitShort', 'Unit'),
			description: t('testing.kindUnitOption', 'Pest Unit Test (--unit)'),
		},
		{
			value: 'e2e',
			label: t('testing.kindE2eShort', 'Playwright E2E'),
			description: t('testing.kindE2eOption', 'Playwright E2E Spec (--e2e)'),
		},
	];
	const activeTestKindOption = testKindOptions.find((opt) => opt.value === testKind);

	const sampleScaffoldName = testKind === 'e2e' ? 'checkout-flow' : 'PageRoutingTest';
	const scaffoldCliCommand = `php vendor/bin/wire make:test ${newTestName || sampleScaffoldName}${testKind === 'unit' ? ' --unit' : testKind === 'e2e' ? ' --e2e' : ''}${newTestModule.trim() ? ` --module=${newTestModule.trim()}` : ''}`;

	const e2eTargetSummary = e2eModule.trim()
		? `--module=${e2eModule.trim()}`
		: e2ePath || t('testing.allE2eSpecs', 'All Discovered E2E Specs');
	const e2eCliCommand = `php vendor/bin/wire test:e2e${e2eModule.trim() ? ` --module=${e2eModule.trim()}` : e2ePath ? ` ${e2ePath}` : ''}${e2eGrep.trim() ? ` --grep=${e2eGrep.trim()}` : ''}`;

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					label={t('testing.pestRunner', 'Pest Test Runner')}
					value={env.ready ? t('queues.ready', 'Ready') : t('workspace.unavailable', 'Unavailable')}
					hint={t('testing.pestFilesHint', { count: env.testFileCount ?? pestFiles.length }, '{count} *Test.php file(s) • vendor/bin/pest')}
					icon="flask"
					tone={env.ready ? 'success' : 'danger'}
				/>
				<StatCard
					label={t('testing.e2eRunner', 'Playwright E2E Runner')}
					value={env.e2eReady ? t('queues.ready', 'Ready') : t('workspace.unavailable', 'Unavailable')}
					hint={t('testing.e2eFilesHint', { count: env.e2eFileCount ?? e2eFiles.length }, '{count} *.spec.ts file(s) • @playwright/test')}
					icon="cpu"
					tone={env.e2eReady ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('testing.runnerConfigs', 'Runner Configurations')}
					value={t(
						'testing.configsSummaryValue',
						{
							phpunit: env.hasPhpunitXml ? t('testing.detected', 'Detected') : t('testing.defaultLabel', 'Default'),
							playwright: env.hasPlaywrightConfig ? t('testing.detected', 'Detected') : t('workspace.missing', 'Missing'),
						},
						'Pest: {phpunit} • E2E: {playwright}'
					)}
					hint={t('testing.runnerConfigsHint', 'phpunit.xml • playwright.config.ts')}
					icon="adjustments-horizontal"
				/>
				<StatCard
					label={t('testing.lastRunStatus', 'Last Run Status')}
					value={
						lastResult
							? lastResult.ok
								? t('testing.passed', 'Passed')
								: t('testing.exitCodeStatus', { code: lastResult.exitCode ?? 1 }, 'Exit {code}')
							: t('testing.notRun', 'Not Run')
					}
					hint={lastResult?.command ?? 'vendor/bin/wire test'}
					icon="circle-check"
					tone={lastResult ? (lastResult.ok ? 'success' : 'danger') : 'default'}
				/>
			</div>

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="flask" className="h-4 w-4 text-primary" />
							<CardTitle>{t('testing.executeTitle', 'Execute Pest Test Suite')}</CardTitle>
						</div>
						<CardDescription>{t('testing.executeDesc', 'Run allowlisted Pest PHP suites or filter by test name via isolated proc_open.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="test-path">{t('testing.targetPathLabel', 'Target File / Suite Path')}</Label>
							<Combobox
								id="test-path"
								value={testPath || null}
								onValueChange={(next) => setTestPath(next)}
								options={testPathOptions}
								placeholder={t('testing.allTestSuites', 'All Pest Test Suites')}
								clearable={testPath !== ''}
								aria-label={t('testing.targetPathLabel', 'Target File / Suite Path')}
							/>
						</div>
						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
							<div className="space-y-1.5">
								<Label htmlFor="test-filter">{t('testing.filterPatternLabel', 'Filter Pattern (--filter)')}</Label>
								<Input
									id="test-filter"
									placeholder={t('testing.filterPlaceholder', 'e.g. InertiaTest')}
									value={testFilter}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setTestFilter(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="test-group">{t('testing.groupLabel', 'Group (--group)')}</Label>
								<Input
									id="test-group"
									placeholder={t('testing.groupPlaceholder', 'e.g. unit')}
									value={testGroup}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setTestGroup(e.target.value)}
								/>
							</div>
						</div>

						<Switch
							id="test-stop-on-failure-switch"
							checked={stopOnFailure}
							onCheckedChange={setStopOnFailure}
							label={t('testing.stopOnFailureSwitch', 'Stop immediately on first failure (--stop-on-failure)')}
						/>

						<CliBox
							wrap
							command={`php vendor/bin/wire test${testPath ? ` ${testPath}` : ''}${testFilter ? ` --filter=${testFilter}` : ''}${testGroup ? ` --group=${testGroup}` : ''}${stopOnFailure ? ' --stop-on-failure' : ''}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>

						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null || !env.ready}
								onClick={() =>
									openConfirm({
										action: 'runTests',
										guardKey: 'testing_run',
										confirmWord: 'TEST',
										variant: 'primary',
										title: t('testing.executeTitle', 'Execute Pest Test Suite'),
										description: t(
											'testing.runConfirmDesc',
											{ target: testPath || t('testing.allTestSuites', 'All Pest Test Suites') },
											'Run vendor/bin/wire test {target} with a 60s timeout.'
										),
										payload: {
											test_path: testPath,
											test_filter: testFilter.trim(),
											test_group: testGroup.trim(),
											test_stop_on_failure: stopOnFailure ? '1' : '0',
										},
									})
								}
							>
								{t('testing.runTestSuite', 'Run Pest Suite')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="cpu" className="h-4 w-4 text-primary" />
							<CardTitle>{t('testing.executeE2eTitle', 'Execute Playwright E2E Suite')}</CardTitle>
						</div>
						<CardDescription>{t('testing.executeE2eDesc', 'Run headless browser and WCAG accessibility specs via vendor/bin/wire test:e2e.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="e2e-path">{t('testing.e2eTargetPathLabel', 'Target E2E Spec Path')}</Label>
							<Combobox
								id="e2e-path"
								value={e2ePath || null}
								onValueChange={(next) => setE2ePath(next)}
								options={e2ePathOptions}
								placeholder={t('testing.allE2eSpecs', 'All Discovered E2E Specs')}
								clearable={e2ePath !== ''}
								aria-label={t('testing.e2eTargetPathLabel', 'Target E2E Spec Path')}
							/>
						</div>
						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
							<div className="space-y-1.5">
								<Label htmlFor="e2e-module">{t('testing.e2eModuleLabel', 'Module Filter (--module)')}</Label>
								<Input
									id="e2e-module"
									placeholder={t('testing.e2eModulePlaceholder', 'e.g. WireConsole')}
									value={e2eModule}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setE2eModule(e.target.value)}
								/>
							</div>
							<div className="space-y-1.5">
								<Label htmlFor="e2e-grep">{t('testing.e2eGrepLabel', 'Title Filter (--grep)')}</Label>
								<Input
									id="e2e-grep"
									placeholder={t('testing.e2eGrepPlaceholder', 'e.g. accessibility')}
									value={e2eGrep}
									onChange={(e: React.ChangeEvent<HTMLInputElement>) => setE2eGrep(e.target.value)}
								/>
							</div>
						</div>

						<CliBox
							wrap
							command={e2eCliCommand}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>

						<div className="flex flex-wrap items-center justify-end gap-2">
							<Button
								variant="outline"
								size="sm"
								icon="adjustments-horizontal"
								disabled={!isSuperuser || busyAction !== null || !env.hasPlaywrightBinary}
								onClick={() =>
									openConfirm({
										action: 'initPlaywrightE2e',
										guardKey: 'testing_e2e_init',
										confirmWord: 'INIT',
										variant: 'primary',
										title: t('testing.initE2eConfirmTitle', 'Initialize Playwright E2E Configuration'),
										description: t(
											'testing.initE2eConfirmDesc',
											'Non-destructively scaffold playwright.config.ts and tests/e2e/ helpers if missing.'
										),
									})
								}
							>
								{t('testing.initE2eButton', 'Init E2E (--init)')}
							</Button>
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null || !env.e2eReady}
								onClick={() =>
									openConfirm({
										action: 'runE2eTests',
										guardKey: 'testing_e2e_run',
										confirmWord: 'E2E',
										variant: 'primary',
										title: t('testing.executeE2eTitle', 'Execute Playwright E2E Suite'),
										description: t(
											'testing.runE2eConfirmDesc',
											{ target: e2eTargetSummary },
											'Run vendor/bin/wire test:e2e {target} with a 120s timeout.'
										),
										payload: {
											e2e_path: e2ePath,
											e2e_module: e2eModule.trim(),
											e2e_grep: e2eGrep.trim(),
										},
									})
								}
							>
								{t('testing.runE2eSuite', 'Run E2E Suite')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="plus" className="h-4 w-4 text-primary" />
							<CardTitle>{t('testing.scaffoldTitle', 'Scaffold Test or E2E Spec')}</CardTitle>
						</div>
						<CardDescription>{t('testing.scaffoldDesc', 'Create a Pest PHP test or Playwright E2E spec in site/tests/ or an installed module.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<SchemaField
							field={{
								id: 'make-test-kind',
								name: 'make_test_kind',
								type: 'segmented',
								label: t('testing.testKindLabel', 'Test Kind'),
								description: activeTestKindOption?.description,
								options: testKindOptions,
								fullWidth: true,
							}}
							inlinePortal
							value={testKind}
							onChange={(nextVal) => {
								if (typeof nextVal === 'string' && nextVal !== '') {
									setTestKind(nextVal);
								}
							}}
						/>
						<div className="space-y-1.5">
							<Label htmlFor="make-test-name">{t('testing.testClassNameLabel', 'Test / Spec Name')}</Label>
							<Input
								id="make-test-name"
								placeholder={
									testKind === 'e2e'
										? t('testing.e2eSpecPlaceholder', 'e.g. checkout-flow')
										: t('testing.testPlaceholder', 'e.g. PageRoutingTest')
								}
								value={newTestName}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewTestName(e.target.value)}
							/>
						</div>
						<div className="space-y-1.5">
							<Label htmlFor="make-test-module">{t('testing.testTargetModuleLabel', 'Target Module (optional --module)')}</Label>
							<Input
								id="make-test-module"
								placeholder={t('testing.e2eModulePlaceholder', 'e.g. WireConsole')}
								value={newTestModule}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewTestModule(e.target.value)}
							/>
						</div>
						<CliBox
							wrap
							command={scaffoldCliCommand}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								icon="plus"
								disabled={!isSuperuser || busyAction !== null || newTestName.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'makeTestFile',
										guardKey: 'testing_make',
										confirmWord: 'CREATE',
										variant: 'primary',
										title: t('testing.scaffoldConfirmTitle', 'Scaffold Test File'),
										description: t(
											'testing.scaffoldConfirmDesc',
											{ kind: testKind.toUpperCase(), name: newTestName.trim() },
											'Create {kind} test "{name}" via make:test.'
										),
										payload: {
											make_test_name: newTestName.trim(),
											make_test_kind: testKind,
											make_test_module: newTestModule.trim(),
										},
									})
								}
							>
								{t('testing.createTest', 'Create Test')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			{lastResult && (
				<CodePreview
					title={t(
						'testing.outputPreviewTitle',
						{ command: lastResult.command || 'vendor/bin/wire test' },
						'Test Runner Output ({command})'
					)}
					subtitle={lastResult.message}
					content={lastResult.output || t('testing.noOutput', '(no output)')}
					maxHeight="24rem"
				/>
			)}

			<Card>
				<CardHeader className="pb-3">
					<CardTitle>{t('testing.discoveredFilesCount', { count: files.length }, 'Discovered Test & E2E Files ({count})')}</CardTitle>
					<CardDescription>{t('testing.discoveredFilesDesc', 'All *Test.php and *.spec.ts files across project, site, and active modules')}</CardDescription>
				</CardHeader>
				<CardContent>
					{files.length === 0 ? (
						<EmptyState
							icon="flask"
							title={t('testing.emptyTitle', 'No Test Files Discovered')}
							description={t('testing.emptyDescription', 'Scaffold your first Pest test or Playwright E2E spec above.')}
						/>
					) : (
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('testing.testNameHead', 'Test Name')}</TableHead>
									<TableHead>{t('testing.runnerHead', 'Runner')}</TableHead>
									<TableHead>{t('testing.suiteHead', 'Suite')}</TableHead>
									<TableHead>{t('testing.relativePathHead', 'Relative Path')}</TableHead>
									<TableHead className="text-right">{t('workspace.actionsHead', 'Actions')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{files.map((file) => {
									const isPlaywright = file.runner === 'playwright';
									return (
										<TableRow key={file.id}>
											<TableCell className="font-mono text-xs font-medium">{file.name}</TableCell>
											<TableCell>
												<Badge variant={isPlaywright ? 'secondary' : 'success'}>
													{isPlaywright ? t('testing.playwrightBadge', 'Playwright') : t('testing.pestBadge', 'Pest')}
												</Badge>
											</TableCell>
											<TableCell><Badge variant="outline">{file.suite}</Badge></TableCell>
											<TableCell className="font-mono text-xs text-muted-foreground">{file.relativePath}</TableCell>
											<TableCell className="text-right">
												<div className="inline-flex items-center gap-1">
													<Button
														variant="ghost"
														size="sm"
														icon="eye"
														onClick={() => inspectFile('test', file.id)}
													>
														{t('workspace.source', 'Source')}
													</Button>
													{isPlaywright ? (
														<Button
															variant="outline"
															size="sm"
															icon="player-play"
															disabled={!isSuperuser || busyAction !== null || !env.e2eReady}
															onClick={() =>
																openConfirm({
																	action: 'runE2eTests',
																	guardKey: 'testing_e2e_run',
																	confirmWord: 'E2E',
																	variant: 'primary',
																	title: t('testing.runSingleConfirmTitle', { name: file.name }, 'Run {name}'),
																	description: t(
																		'testing.runSingleE2eConfirmDesc',
																		{ path: file.relativePath },
																		'Execute {path} via vendor/bin/wire test:e2e.'
																	),
																	payload: { e2e_path: file.relativePath },
																})
															}
														>
															{t('workspace.run', 'Run')}
														</Button>
													) : (
														<Button
															variant="outline"
															size="sm"
															icon="player-play"
															disabled={!isSuperuser || busyAction !== null || !env.ready}
															onClick={() =>
																openConfirm({
																	action: 'runTests',
																	guardKey: 'testing_run',
																	confirmWord: 'TEST',
																	variant: 'primary',
																	title: t('testing.runSingleConfirmTitle', { name: file.name }, 'Run {name}'),
																	description: t(
																		'testing.runSingleConfirmDesc',
																		{ path: file.relativePath },
																		'Execute {path} via vendor/bin/wire test.'
																	),
																	payload: { test_path: file.relativePath },
																})
															}
														>
															{t('workspace.run', 'Run')}
														</Button>
													)}
												</div>
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
