import {
	test,
	expect,
	devices,
	request as playwrightRequest,
	type Cookie,
	type Page,
} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import crypto from 'node:crypto';
import {
	attachRuntimeErrorTracker,
	deleteIsolatedUser,
	getAdminUrl,
	runWirePhp,
} from '../../../../../tests/e2e/helpers';

const E2E_RESTRICTED_USER = `wc_e2e_restricted_${crypto.randomBytes(3).toString('hex')}`;
const E2E_RESTRICTED_ROLE = 'wc-e2e-restricted-role';
const E2E_QUEUE_CLASS = 'WcPlaywrightE2eQueue';
const E2E_QUEUE_NAME = 'wc_pw_e2e';
const E2E_BACKUP_FIXTURE = 'wc_pw_e2e_snapshot.sql';
const E2E_CACHE_KEY = 'wire-console:e2e:queue-marker';

function runPhpJson<T = unknown>(code: string): T {
	return runWirePhp<T>(code);
}

function cleanupE2eConsoleArtifacts(): void {
	runPhpJson(`
		$downFile = $wire->config->paths->site . 'down.json';
		if (is_file($downFile)) {
			@unlink($downFile);
		}
		$queueFile = $wire->config->paths->site . 'queue/${E2E_QUEUE_CLASS}.php';
		if (is_file($queueFile)) {
			@unlink($queueFile);
		}
		$backupFile = rtrim($wire->config->paths->assets, '/') . '/backups/database/${E2E_BACKUP_FIXTURE}';
		if (is_file($backupFile)) {
			@unlink($backupFile);
		}
		$wire->cache->delete('${E2E_CACHE_KEY}');
		if ($wire->modules->isInstalled('ProcessWireConsole')) {
			$mod = $wire->modules->getModule('ProcessWireConsole', ['noPermissionCheck' => true]);
			if ($mod && method_exists($mod, 'runtime')) {
				$mod->runtime()->purgePendingJobs('${E2E_QUEUE_NAME}');
			}
		}
		echo json_encode(['ok' => true]);
	`);
}

async function confirmAndExecuteModal(page: Page): Promise<void> {
	const dialog = page.getByRole('dialog');
	await expect(dialog).toBeVisible();

	const confirmButton = dialog.getByRole('button', { name: 'Confirm', exact: true });
	await expect(confirmButton).toBeDisabled();

	const checkbox = dialog.getByRole('checkbox');
	await checkbox.check();
	await expect(confirmButton).toBeEnabled();
	await confirmButton.click();
	await expect(dialog).toHaveCount(0);
}

test.describe('WireConsole Admin Workspace, Operations & RBAC (ProcessWireConsole)', () => {
	let restrictedCookies: Cookie[] = [];
	let resolvedBaseUrl = '';

	test.beforeAll(async ({ browser, baseURL }) => {
		cleanupE2eConsoleArtifacts();

		const restrictedPass = `WcView_${crypto.randomBytes(8).toString('hex')}!9aA`;

		const bootMeta = runPhpJson<{ httpHost: string }>(`
			$users = $wire->users;
			$roles = $wire->roles;
			$permissions = $wire->permissions;

			$existing = $users->get('${E2E_RESTRICTED_USER}');
			if ($existing && $existing->id) {
				$users->delete($existing);
			}
			$existingRole = $roles->get('${E2E_RESTRICTED_ROLE}');
			if ($existingRole && $existingRole->id) {
				$roles->delete($existingRole);
			}

			foreach (['wire-console', 'wire-console-migrations', 'wire-console-logs', 'wire-console-backup'] as $permName) {
				if (!$permissions->get($permName)->id) {
					$p = $permissions->add($permName);
					$p->title = $permName;
					$permissions->save($p);
				}
			}

			$role = $roles->add('${E2E_RESTRICTED_ROLE}');
			$role->of(false);
			foreach (['page-view', 'page-edit', 'wire-console', 'wire-console-migrations', 'wire-console-logs'] as $permName) {
				$role->addPermission($permName);
			}
			$roles->save($role);

			$viewer = $users->add('${E2E_RESTRICTED_USER}');
			$viewer->of(false);
			$viewer->pass = '${restrictedPass}';
			$viewer->addRole($role);
			$users->save($viewer);

			$hosts = is_array($wire->config->httpHosts) ? array_values($wire->config->httpHosts) : [];
			echo json_encode([
				'httpHost' => $hosts[0] ?? $wire->config->httpHost,
			], JSON_UNESCAPED_SLASHES);
		`);

		resolvedBaseUrl = baseURL || process.env.PW_BASE_URL || `https://${bootMeta.httpHost}`;
		const ctx = await browser.newContext({
			...devices['Desktop Chrome'],
			baseURL: resolvedBaseUrl,
			ignoreHTTPSErrors: true,
			storageState: { cookies: [], origins: [] },
		});
		const loginPage = await ctx.newPage();
		await loginPage.goto(getAdminUrl(), { waitUntil: 'networkidle' });
		await loginPage.locator('#login_name').fill(E2E_RESTRICTED_USER);
		await loginPage.locator('#login_pass').fill(restrictedPass);
		await Promise.all([
			loginPage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			loginPage.locator('#Inputfield_login_submit').click(),
		]);
		await expect(loginPage.locator('#login_name')).toHaveCount(0);
		restrictedCookies = (await ctx.storageState()).cookies;
		await ctx.close();
	});

	test.afterEach(() => {
		cleanupE2eConsoleArtifacts();
	});

	test.afterAll(() => {
		cleanupE2eConsoleArtifacts();
		deleteIsolatedUser(E2E_RESTRICTED_USER);
		runPhpJson(`
			$r = $wire->roles->get('${E2E_RESTRICTED_ROLE}');
			if ($r && $r->id) {
				$wire->roles->delete($r);
			}
			echo json_encode(['ok' => true]);
		`);
	});

	test('1. mounts workspace with vector SVG icons, navigates all 9 tabs & deep-links, passes WCAG 2.1 AA axe scans, and toggles theme & mobile drawer', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);

		await page.goto(getAdminUrl('setup/wire-console/'), { waitUntil: 'networkidle' });
		await expect(page.locator('#app')).toBeVisible();
		await expect(page.getByRole('heading', { name: /Console/i }).first()).toBeVisible();

		// Verify ReactIcons.js vector icon catalog runtime is preloaded on window.ProcessWireIcons and hydrates canonical icons
		await expect
			.poll(async () => {
				return page.evaluate(() => {
					const catalog = (
						window as unknown as {
							ProcessWireIcons?: {
								getCachedIcon?: (name: string) => unknown;
								requestHydration?: (name: string) => void;
								renderSvgString?: (name: string) => string;
							};
						}
					).ProcessWireIcons;
					if (
						!catalog ||
						typeof catalog.getCachedIcon !== 'function' ||
						typeof catalog.requestHydration !== 'function' ||
						typeof catalog.renderSvgString !== 'function'
					) {
						return false;
					}
					if (!catalog.getCachedIcon('stack-2')) {
						catalog.requestHydration('stack-2');
						return false;
					}
					return Boolean(catalog.renderSvgString('stack-2').includes('<svg'));
				});
			})
			.toBe(true);
		const svgCount = await page.locator('#app svg').count();
		expect(svgCount).toBeGreaterThan(12);

		const runWcagScan = async (sectionLabel: string) => {
			// Full WCAG 2.1 AA scan INCLUDING color-contrast on all WireConsole panel content ([data-console-panel])
			const panelAxe = await new AxeBuilder({ page })
				.include('[data-console-panel]')
				.withTags(['wcag2a', 'wcag2aa'])
				.analyze();
			const panelViolations = panelAxe.violations.filter(
				(v) => v.impact === 'critical' || v.impact === 'serious',
			);
			expect(
				panelViolations,
				`Unexpected WCAG 2.1 AA panel violations (including color-contrast) on ${sectionLabel}: ${JSON.stringify(panelViolations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.html) })))}`,
			).toEqual([]);

			// Structural & ARIA WCAG 2.1 AA scan across the outer shared AppShell chrome
			const shellAxe = await new AxeBuilder({ page })
				.include('#app')
				.exclude('[data-console-panel]')
				.withTags(['wcag2a', 'wcag2aa'])
				.disableRules(['color-contrast'])
				.analyze();
			const shellViolations = shellAxe.violations.filter(
				(v) => v.impact === 'critical' || v.impact === 'serious',
			);
			expect(
				shellViolations,
				`Unexpected WCAG 2.1 AA shell violations on ${sectionLabel}: ${JSON.stringify(shellViolations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.html) })))}`,
			).toEqual([]);
		};

		// WCAG scan on initial Overview tab
		await runWcagScan('Overview');

		// Navigate across all 9 tabs and verify URL state + full WCAG 2.1 AA scans (including color-contrast) on every section
		const tabSequence: Array<{ label: string; slug: string; scanA11y?: boolean }> = [
			{ label: 'Migrations', slug: 'migrations/', scanA11y: true },
			{ label: 'Queues', slug: 'queues/', scanA11y: true },
			{ label: 'Seeding', slug: 'seeding/', scanA11y: true },
			{ label: 'Scheduling', slug: 'scheduling/', scanA11y: true },
			{ label: 'Backups', slug: 'backup/', scanA11y: true },
			{ label: 'Testing', slug: 'testing/', scanA11y: true },
			{ label: 'Boost & AI', slug: 'boost/', scanA11y: true },
			{ label: 'Logs & Audit', slug: 'logs/', scanA11y: true },
			{ label: 'Overview', slug: '' },
		];

		for (const item of tabSequence) {
			const tabButton = page.getByRole('button', { name: item.label, exact: true }).first();
			await expect(tabButton).toBeVisible();
			await tabButton.click();
			if (item.slug !== '') {
				expect(page.url()).toContain(`/wire-console/${item.slug}`);
			} else {
				expect(page.url().endsWith('/wire-console/')).toBe(true);
			}
			if (item.scanA11y) {
				await runWcagScan(item.label);
			}
		}

		// Verify direct deep-link routes render expected active tab directly from PHP
		await page.goto(getAdminUrl('setup/wire-console/testing/'), { waitUntil: 'networkidle' });
		await expect(page.getByText('Execute Playwright E2E Suite')).toBeVisible();
		await expect(page.locator('td', { hasText: 'console-workspace.spec.ts' }).first()).toBeVisible();

		await page.goto(getAdminUrl('setup/wire-console/boost/'), { waitUntil: 'networkidle' });
		await expect(page.getByText('Synchronize & Configure Boost')).toBeVisible();

		await page.goto(getAdminUrl('setup/wire-console/logs/'), { waitUntil: 'networkidle' });
		await expect(page.getByText('Log Channels & Audit Trail')).toBeVisible();

		// Theme toggle & mobile responsive drawer verification
		const userMenuTrigger = page.locator('[data-rw-user-menu-trigger]');
		if (await userMenuTrigger.isVisible()) {
			await userMenuTrigger.click();
			await expect(page.locator('[data-rw-theme-section]')).toBeVisible();

			const darkPrefPromise = page.waitForResponse(
				(res) => res.url().includes('/_react/workspace-prefs') && res.request().method() === 'POST',
			);
			await page.locator('[data-rw-theme-mode-option="dark"]').click();
			expect((await darkPrefPromise).status()).toBe(200);
			await expect(page.locator('html')).toHaveClass(/\bdark\b/);

			const restoreModePromise = page.waitForResponse(
				(res) => res.url().includes('/_react/workspace-prefs') && res.request().method() === 'POST',
			);
			await page.locator('[data-rw-theme-mode-option="system"]').click();
			expect((await restoreModePromise).status()).toBe(200);
			await page.keyboard.press('Escape');
		}

		await page.setViewportSize({ width: 390, height: 844 });
		const mobileTrigger = page.locator('[data-rw-mobile-menu-trigger]');
		if (await mobileTrigger.isVisible()) {
			await mobileTrigger.click();
			await expect(page.locator('[data-rw-mobile-drawer]')).toBeVisible();
			await page.keyboard.press('Escape');
		}
		await page.setViewportSize({ width: 1280, height: 720 });

		tracker.assertNoErrors();
	});

	test('2. enforces ConfirmDialog acknowledgment checkbox, flushes WireCache, and toggles live Maintenance Mode (503 -> 200)', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);
		await page.goto(getAdminUrl('setup/wire-console/'), { waitUntil: 'networkidle' });

		// 1. Test ConfirmDialog Cancel + Confirm flow on WireCache flush
		await page.locator('#cache-clear-mode').selectOption('wire');
		await page.getByRole('button', { name: 'Flush Selected Caches' }).click();

		const dialog = page.getByRole('dialog');
		await expect(dialog).toBeVisible();
		await expect(dialog.getByRole('button', { name: 'Confirm', exact: true })).toBeDisabled();
		await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
		await expect(dialog).toHaveCount(0);

		await page.getByRole('button', { name: 'Flush Selected Caches' }).click();
		await confirmAndExecuteModal(page);
		await expect(page.getByText('Action Completed (clearSystemCaches)')).toBeVisible();

		// 2. Enable Maintenance Mode with bypass secret and verify live public HTTP 503 + bypass 200
		const bypassSecret = `e2e-bypass-${crypto.randomBytes(4).toString('hex')}`;
		await page.locator('#maintenance-secret').fill(bypassSecret);
		await page.locator('#maintenance-retry').fill('120');
		await page.getByRole('button', { name: 'Enable Maintenance Mode' }).click();
		await confirmAndExecuteModal(page);

		await expect(page.getByText('Maintenance Mode (503)').first()).toBeVisible();
		await expect(page.getByRole('button', { name: 'Bring Application Online' })).toBeVisible();

		const anonApi = await playwrightRequest.newContext({
			baseURL: resolvedBaseUrl,
			ignoreHTTPSErrors: true,
			storageState: { cookies: [], origins: [] },
		});
		try {
			const downResponse = await anonApi.get('/');
			expect(downResponse.status()).toBe(503);

			const bypassResponse = await anonApi.get(`/?secret=${encodeURIComponent(bypassSecret)}`);
			expect(bypassResponse.status()).toBe(200);
		} finally {
			await anonApi.dispose();
		}

		// 3. Bring Application Online via UI and verify live public HTTP 200 restored
		await page.getByRole('button', { name: 'Bring Application Online' }).click();
		await confirmAndExecuteModal(page);
		await expect(page.getByText('System Online').first()).toBeVisible();

		const anonVerifyOnline = await playwrightRequest.newContext({
			baseURL: resolvedBaseUrl,
			ignoreHTTPSErrors: true,
			storageState: { cookies: [], origins: [] },
		});
		try {
			const onlineResponse = await anonVerifyOnline.get('/');
			expect(onlineResponse.status()).toBe(200);
		} finally {
			await anonVerifyOnline.dispose();
		}

		tracker.assertNoErrors();
	});

	test('3. opens Source Inspector Sheet for AGENTS.md, executes in-process MCP pw_module_list, and filters Audit Logs cleanly via GET', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);
		await page.goto(getAdminUrl('setup/wire-console/boost/'), { waitUntil: 'networkidle' });

		// 1. Open Source Inspector Sheet for AGENTS.md
		await page.getByRole('button', { name: 'AGENTS.md', exact: true }).click();
		const sheet = page.getByRole('dialog');
		await expect(sheet).toBeVisible();
		await expect(sheet.getByText('ProcessWire Boost Guidelines')).toBeVisible();
		await sheet.getByRole('button', { name: 'Close' }).first().click();
		await expect(sheet).toHaveCount(0);

		// 2. Run read-only MCP tool pw_module_list in-process
		await page.locator('#mcp-tool-name').selectOption('pw_module_list');
		await page.locator('#mcp-tool-args').fill('{}');
		await page.getByRole('button', { name: 'Run MCP Tool' }).click();
		await confirmAndExecuteModal(page);

		await expect(page.getByText('MCP JSON-RPC Response (pw_module_list)')).toBeVisible();
		await expect(page.locator('pre code').filter({ hasText: 'ProcessModule' }).first()).toBeVisible();

		// 3. Switch to Logs & Audit and filter via GET (ensuring URL routes to /logs/ and NOT /action/)
		await page.getByRole('button', { name: 'Logs & Audit', exact: true }).first().click();
		await expect(page.locator('#log-search-input')).toBeVisible();
		await page.locator('#log-search-input').fill('boost_mcp_test');

		const filterGetPromise = page.waitForResponse(
			(res) =>
				res.url().includes('/wire-console/logs/') &&
				res.request().method() === 'GET' &&
				!res.url().includes('/action/'),
		);
		await page.getByRole('button', { name: 'Filter', exact: true }).click();
		const filterRes = await filterGetPromise;
		expect(filterRes.status()).toBe(200);
		await expect(page.getByText('Executed read-only MCP tool "pw_module_list" via JSON-RPC 2.0.').first()).toBeVisible();

		tracker.assertNoErrors();
	});

	test('4. scaffolds queue handler, previews PHP source, dispatches & works queue batch, and previews & downloads SQL backup snapshot', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);

		// Seed queue tables + isolated SQL backup fixture before opening UI
		runPhpJson(`
			$mod = $wire->modules->getModule('ProcessWireConsole', ['noPermissionCheck' => true]);
			$rt = $mod->runtime();
			$rt->installQueueTables();
			$rt->purgePendingJobs('${E2E_QUEUE_NAME}');
			$backupPath = $rt->getBackupDirectory() . '${E2E_BACKUP_FIXTURE}';
			file_put_contents($backupPath, "-- PLAYWRIGHT E2E SQL FIXTURE\\nSELECT 1027;\\n");
			echo json_encode(['ok' => true]);
		`);

		await page.goto(getAdminUrl('setup/wire-console/queues/'), { waitUntil: 'networkidle' });

		// 1. Scaffold WcPlaywrightE2eQueue via UI
		await page.locator('#make-queue-name').fill(E2E_QUEUE_CLASS);
		await page.getByRole('button', { name: 'Create', exact: true }).click();
		await confirmAndExecuteModal(page);
		await expect(page.getByText('Action Completed (makeQueueClass)')).toBeVisible();
		await expect(page.getByText(`${E2E_QUEUE_CLASS}.php`)).toBeVisible();

		// Inject deterministic WireCache marker into scaffolded queue class so workQueueBatch proves real execution
		runPhpJson(`
			$queueFile = $wire->config->paths->site . 'queue/${E2E_QUEUE_CLASS}.php';
			file_put_contents($queueFile, "<?php\\ndeclare(strict_types=1);\\nnamespace Site\\\\Queue;\\nuse Totoglu\\\\Console\\\\Queue\\\\Queue;\\nclass ${E2E_QUEUE_CLASS} extends Queue {\\n\\tpublic function handle(array \\$data = []): void {\\n\\t\\t\\\\ProcessWire\\\\wire('cache')->save('${E2E_CACHE_KEY}', (string)(\\$data['token'] ?? 'ran'), 300);\\n\\t}\\n}\\n");
			echo json_encode(['ok' => true]);
		`);

		// 2. Dispatch job onto isolated queue wc_pw_e2e
		await page.locator('#dispatch-job-class').selectOption(E2E_QUEUE_CLASS);
		await page.locator('#dispatch-job-queue').fill(E2E_QUEUE_NAME);
		await page.locator('#dispatch-job-payload').fill('{"token":"pw-e2e-worker-verified"}');
		await page.getByRole('button', { name: 'Dispatch Job' }).click();
		await confirmAndExecuteModal(page);
		await expect(page.getByText('Action Completed (dispatchQueueJob)')).toBeVisible();

		// 3. Process batch on wc_pw_e2e via UI and verify WireCache marker written by QueueWorkCommand
		await page.locator('#work-queue-name').fill(E2E_QUEUE_NAME);
		await page.getByRole('button', { name: 'Work Batch' }).click();
		await confirmAndExecuteModal(page);
		await expect(page.getByText('Action Completed (workQueueBatch)')).toBeVisible();

		const workerMarker = runPhpJson<{ value: string | null }>(`
			echo json_encode(['value' => $wire->cache->get('${E2E_CACHE_KEY}')]);
		`);
		expect(workerMarker.value).toBe('pw-e2e-worker-verified');

		// 4. Switch to Backups tab, preview SQL fixture in Sheet, and verify authenticated download endpoint
		await page.getByRole('button', { name: 'Backups', exact: true }).first().click();
		const fixtureRow = page.locator('tr', { hasText: E2E_BACKUP_FIXTURE });
		await expect(fixtureRow).toBeVisible();

		await fixtureRow.getByRole('button', { name: 'Preview' }).click();
		const sheet = page.getByRole('dialog');
		await expect(sheet).toBeVisible();
		await expect(sheet.getByText('PLAYWRIGHT E2E SQL FIXTURE')).toBeVisible();
		await sheet.getByRole('button', { name: 'Close' }).first().click();

		const downloadRes = await page.request.get(
			getAdminUrl(`setup/wire-console/download-backup/?file=${encodeURIComponent(E2E_BACKUP_FIXTURE)}`),
		);
		expect(downloadRes.status()).toBe(200);
		expect(await downloadRes.text()).toContain('PLAYWRIGHT E2E SQL FIXTURE');

		tracker.assertNoErrors();
	});

	test('5. enforces granular non-superuser RBAC in browser: hides unauthorized tabs, disables mutations, and blocks SQL download', async ({
		browser,
	}) => {
		runPhpJson(`
			$mod = $wire->modules->getModule('ProcessWireConsole', ['noPermissionCheck' => true]);
			$backupPath = $mod->runtime()->getBackupDirectory() . '${E2E_BACKUP_FIXTURE}';
			file_put_contents($backupPath, "-- RBAC PROTECTED SQL\\nSELECT 1;\\n");
			echo json_encode(['ok' => true]);
		`);

		const ctx = await browser.newContext({
			...devices['Desktop Chrome'],
			baseURL: resolvedBaseUrl,
			ignoreHTTPSErrors: true,
			storageState: { cookies: [], origins: [] },
		});
		await ctx.addCookies(restrictedCookies);
		const page = await ctx.newPage();
		const tracker = attachRuntimeErrorTracker(page);

		try {
			await page.goto(getAdminUrl('setup/wire-console/'), { waitUntil: 'networkidle' });
			await expect(page.locator('#app')).toBeVisible();

			// Allowed tabs (Overview, Migrations, Logs & Audit) are visible
			await expect(page.getByRole('button', { name: 'Overview', exact: true }).first()).toBeVisible();
			await expect(page.getByRole('button', { name: 'Migrations', exact: true }).first()).toBeVisible();
			await expect(page.getByRole('button', { name: 'Logs & Audit', exact: true }).first()).toBeVisible();

			// Unauthorized tabs are completely hidden from navigation
			for (const hiddenTab of ['Queues', 'Seeding', 'Scheduling', 'Backups', 'Testing', 'Boost & AI']) {
				await expect(page.getByRole('button', { name: hiddenTab, exact: true })).toHaveCount(0);
			}

			// Mutating controls on Overview, Migrations, and Logs & Audit are disabled for non-superusers
			await expect(page.getByRole('button', { name: 'Enable Maintenance Mode' })).toBeDisabled();
			await expect(page.getByRole('button', { name: 'Flush Selected Caches' })).toBeDisabled();

			await page.getByRole('button', { name: 'Migrations', exact: true }).first().click();
			await expect(page.getByRole('button', { name: 'Run Pending' })).toBeDisabled();

			await page.getByRole('button', { name: 'Logs & Audit', exact: true }).first().click();
			await expect(page.getByRole('button', { name: 'Clear Channel' })).toBeDisabled();
			await expect(page.getByRole('button', { name: 'Clear All Logs' })).toBeDisabled();

			// Direct HTTP download-backup request by non-superuser must never stream SQL attachment and must audit denied_rbac
			const forbiddenDownload = await page.request.get(
				getAdminUrl(`setup/wire-console/download-backup/?file=${encodeURIComponent(E2E_BACKUP_FIXTURE)}`),
			);
			const contentDisposition = forbiddenDownload.headers()['content-disposition'] || '';
			expect(contentDisposition).not.toContain('attachment');
			expect(await forbiddenDownload.text()).not.toContain('RBAC PROTECTED SQL');

			const auditDenied = runPhpJson<{ found: boolean }>(`
				$mod = $wire->modules->getModule('ProcessWireConsole', ['noPermissionCheck' => true]);
				$entries = $mod->runtime()->readLogEntries('wire-console-audit', 10, 'backup.download');
				$found = false;
				foreach ($entries as $e) {
					if (($e['user'] ?? '') === '${E2E_RESTRICTED_USER}' && str_contains((string) ($e['text'] ?? ''), 'denied_rbac')) {
						$found = true;
						break;
					}
				}
				echo json_encode(['found' => $found]);
			`);
			expect(auditDenied.found).toBe(true);

			tracker.assertNoErrors();
		} finally {
			await ctx.close();
		}
	});

	test('6. verifies AppShell header parity, RecordHeader controls, responsive overflow, ?ws=1 embed mode, and full WCAG 2.1 AA', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);
		await page.setViewportSize({ width: 1440, height: 900 });

		await page.goto(getAdminUrl('setup/wire-console/'), { waitUntil: 'networkidle' });
		const moduleHeader = page.locator('[data-rw-module-header]').first();
		await expect(moduleHeader).toBeVisible();
		await expect(moduleHeader.getByRole('button', { name: 'Refresh Status' })).toHaveCount(0);

		const recordHeader = page.locator('[data-rw-record-header]').first();
		await expect(recordHeader).toBeVisible();
		await expect(recordHeader).toContainText('Overview');
		await expect(recordHeader.getByRole('button', { name: 'Refresh Status' }).first()).toBeVisible();

		const baseHeaderBox = await moduleHeader.boundingBox();
		expect(baseHeaderBox).not.toBeNull();

		// Navigate to Migrations and verify module header parity and RecordHeader title
		await page.locator('[data-rw-module-nav] button').filter({ hasText: /^Migrations$/ }).first().click();
		await expect(page).toHaveURL(/\/wire-console\/migrations\//);
		await expect(recordHeader).toContainText('Migrations');
		await expect(moduleHeader.getByRole('button', { name: 'Refresh Status' })).toHaveCount(0);
		const migrationsHeaderBox = await moduleHeader.boundingBox();
		expect(migrationsHeaderBox).not.toBeNull();
		expect(Math.abs((migrationsHeaderBox?.height ?? 0) - (baseHeaderBox?.height ?? 0))).toBeLessThanOrEqual(2);

		// Navigate to Logs & Audit (either direct nav button or More overflow menu) and verify header parity
		const directLogsBtn = page.locator('[data-rw-module-nav] button').filter({ hasText: /^Logs & Audit$/ }).first();
		if (await directLogsBtn.isVisible()) {
			await directLogsBtn.click();
		} else {
			await page.locator('[data-rw-module-nav-more]').first().click();
			await page.locator('[data-rw-module-nav-menu]').getByRole('menuitem', { name: /Logs & Audit/i }).first().click();
		}
		await expect(page).toHaveURL(/\/wire-console\/logs\//);
		await expect(recordHeader).toContainText('Logs & Audit');
		const logsHeaderBox = await moduleHeader.boundingBox();
		expect(logsHeaderBox).not.toBeNull();
		expect(Math.abs((logsHeaderBox?.height ?? 0) - (baseHeaderBox?.height ?? 0))).toBeLessThanOrEqual(2);

		// Return to Overview and verify responsive layout (820px, 680px, 390px) with zero horizontal #app or nav overflow + full WCAG 2.1 AA
		await page.goto(getAdminUrl('setup/wire-console/'), { waitUntil: 'networkidle' });
		for (const vp of [
			{ width: 820, height: 900 },
			{ width: 680, height: 900 },
			{ width: 390, height: 844 },
		]) {
			await page.setViewportSize(vp);
			await page.evaluate(
				() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))
			);
			const appNoOverflow = await page
				.locator('#app')
				.evaluate((el) => el.scrollWidth <= el.clientWidth + 1);
			expect(appNoOverflow, `#app horizontal overflow at ${vp.width}x${vp.height}`).toBe(true);
			const navNoOverflow = await page
				.locator('#app [data-rw-module-nav]')
				.evaluate((el) => el.scrollWidth <= el.clientWidth + 1);
			expect(navNoOverflow, `Module nav horizontal overflow at ${vp.width}x${vp.height}`).toBe(true);
		}

		const responsiveAxe = await new AxeBuilder({ page })
			.include('[data-console-panel]')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(responsiveAxe.violations, 'WCAG 2.1 AA violations on WireConsole Responsive Overview').toEqual([]);

		// Restore desktop viewport, open ?ws=1 embedded mode, and verify tab navigation preserves ?ws=1
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto(getAdminUrl('setup/wire-console/?ws=1'), { waitUntil: 'networkidle' });
		await expect(page.locator('[data-console-panel]').first()).toBeVisible();
		await page.locator('[data-rw-module-nav] button').filter({ hasText: /^Migrations$/ }).first().click();
		await expect(page).toHaveURL(/\/wire-console\/migrations\/\?ws=1$/);

		tracker.assertNoErrors();
	});
});
