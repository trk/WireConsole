import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(__dirname, '../..');
const tsRoot = path.join(moduleRoot, 'resources/ts');
const panelsRoot = path.join(tsRoot, 'panels');
const reactWorkspaceCatalogPath = path.resolve(
	moduleRoot,
	'../React/dist/vector-icons.json',
);

const DISALLOWED_ICON_ALIASES = new Set([
	'layers',
	'hard-drive',
	'check-circle-2',
	'rotate-ccw',
	'refresh-cw',
	'trash-2',
	'sprout',
	'flask-conical',
	'settings-2',
	'book-open',
	'scroll-text',
	'code-2',
	'clock-o',
	'hdd-o',
	'list-alt',
	'check-circle',
	'dashboard',
]);

function collectFiles(dir: string, ext: string): string[] {
	const results: string[] = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const fullPath = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			results.push(...collectFiles(fullPath, ext));
		} else if (entry.isFile() && fullPath.endsWith(ext)) {
			results.push(fullPath);
		}
	}
	return results.sort();
}

function loadVectorOutlineCatalog(): Record<string, unknown> | null {
	if (!fs.existsSync(reactWorkspaceCatalogPath)) {
		return null;
	}
	const parsed = JSON.parse(fs.readFileSync(reactWorkspaceCatalogPath, 'utf8')) as {
		outline?: Record<string, unknown>;
	};
	assert.ok(parsed.outline && typeof parsed.outline === 'object', 'Expected outline icon catalog');
	return parsed.outline;
}

test('all TSX icon props and DynamicIcon names exist in canonical vector outline catalog', () => {
	const outline = loadVectorOutlineCatalog();
	const tsxFiles = collectFiles(tsRoot, '.tsx');
	assert.ok(tsxFiles.length >= 11, 'Expected Workspace + 9 panels + entry TSX files');

	const discoveredIcons = new Set<string>();

	for (const file of tsxFiles) {
		const rel = path.relative(moduleRoot, file);
		const content = fs.readFileSync(file, 'utf8');

		// Direct string attributes: icon="foo" or name="foo" inside DynamicIcon
		for (const match of content.matchAll(/\bicon="([a-z0-9-]+)"/g)) {
			const icon = match[1];
			discoveredIcons.add(icon);
			assert.ok(!DISALLOWED_ICON_ALIASES.has(icon), `Non-canonical icon "${icon}" found in ${rel}`);
			if (outline) {
				assert.ok(icon in outline, `Unknown vector outline icon "${icon}" in ${rel}`);
			}
		}

		for (const match of content.matchAll(/<DynamicIcon[^>]*\bname="([a-z0-9-]+)"/g)) {
			const icon = match[1];
			discoveredIcons.add(icon);
			assert.ok(!DISALLOWED_ICON_ALIASES.has(icon), `Non-canonical DynamicIcon "${icon}" found in ${rel}`);
			if (outline) {
				assert.ok(icon in outline, `Unknown vector DynamicIcon "${icon}" in ${rel}`);
			}
		}

		// Ternary string expressions inside icon={... ? 'a' : 'b'} or DynamicIcon name={... ? 'a' : 'b'}
		for (const match of content.matchAll(/(?:\bicon=|<DynamicIcon[^>]*\bname=)\{[^}]*\?\s*'([a-z0-9-]+)'\s*:\s*'([a-z0-9-]+)'\s*\}/g)) {
			for (const icon of [match[1], match[2]]) {
				discoveredIcons.add(icon);
				assert.ok(!DISALLOWED_ICON_ALIASES.has(icon), `Non-canonical ternary icon "${icon}" found in ${rel}`);
				if (outline) {
					assert.ok(icon in outline, `Unknown vector ternary icon "${icon}" in ${rel}`);
				}
			}
		}
	}

	assert.ok(discoveredIcons.size >= 25, `Expected at least 25 distinct canonical vector icons, got ${discoveredIcons.size}`);
});

test('PHP tab definitions and admin icon registrations use canonical vector outline icons', () => {
	const outline = loadVectorOutlineCatalog();
	const runtimePhp = fs.readFileSync(path.join(moduleRoot, 'src/ConsoleRuntime.php'), 'utf8');
	const modulePhp = fs.readFileSync(path.join(moduleRoot, 'ProcessWireConsole.module.php'), 'utf8');

	const tabDefSection = runtimePhp.slice(
		runtimePhp.indexOf('public function getTabDefinitions(): array'),
		runtimePhp.indexOf('public function getAccessibleTabs(): array'),
	);
	const tabIcons = [...tabDefSection.matchAll(/'icon'\s*=>\s*'([a-z0-9-]+)'/g)].map((m) => m[1]);
	assert.equal(tabIcons.length, 9, 'Expected 9 tab icons in ConsoleRuntime::getTabDefinitions()');

	for (const icon of tabIcons) {
		assert.ok(!DISALLOWED_ICON_ALIASES.has(icon), `Tab icon "${icon}" must not use disallowed icon alias`);
		if (outline) {
			assert.ok(icon in outline, `Tab icon "${icon}" must exist in vector outline catalog`);
		}
	}

	const registerAdminBlock = modulePhp.match(/IconManager::registerAdminIcons\(\[([\s\S]*?)\]\)/);
	assert.ok(registerAdminBlock, 'Expected IconManager::registerAdminIcons([...]) in ProcessWireConsole::init()');
	const registeredAdminIcons = [...registerAdminBlock[1].matchAll(/'([a-z0-9-]+)'/g)].map((m) => m[1]);

	for (const tabIcon of tabIcons) {
		assert.ok(
			registeredAdminIcons.includes(tabIcon),
			`Tab icon "${tabIcon}" must be pre-registered via IconManager::registerAdminIcons()`,
		);
	}
});

test('every t("section.key") call in TSX resolves to buildTranslationsArray() in ProcessWireConsole.module.php', () => {
	const modulePhp = fs.readFileSync(path.join(moduleRoot, 'ProcessWireConsole.module.php'), 'utf8');
	const startIdx = modulePhp.indexOf('private function buildTranslationsArray(): array');
	assert.ok(startIdx > 0, 'Expected buildTranslationsArray() in ProcessWireConsole.module.php');
	const translationsSource = modulePhp.slice(startIdx);

	const phpSections = new Map<string, Set<string>>();
	const sectionRegex = /\t\t\t'([a-zA-Z0-9_]+)'\s*=>\s*\[([\s\S]*?)\n\t\t\t\],/g;
	for (const secMatch of translationsSource.matchAll(sectionRegex)) {
		const sectionName = secMatch[1];
		const keys = new Set<string>();
		for (const keyMatch of secMatch[2].matchAll(/\t\t\t\t'([a-zA-Z0-9_]+)'\s*=>/g)) {
			keys.add(keyMatch[1]);
		}
		phpSections.set(sectionName, keys);
	}

	assert.ok(phpSections.size >= 12, `Expected at least 12 translation sections, found ${phpSections.size}`);

	const tsxFiles = collectFiles(tsRoot, '.tsx');
	const usedKeys = new Set<string>();
	let checkedCount = 0;

	for (const file of tsxFiles) {
		const rel = path.relative(moduleRoot, file);
		const content = fs.readFileSync(file, 'utf8');
		for (const match of content.matchAll(/\bt\(\s*'([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)'/g)) {
			const [, section, key] = match;
			checkedCount++;
			usedKeys.add(`${section}.${key}`);
			const sectionKeys = phpSections.get(section);
			assert.ok(sectionKeys, `Missing PHP translation section "${section}" referenced in ${rel}`);
			assert.ok(
				sectionKeys.has(key),
				`Missing PHP translation key "${section}.${key}" referenced in ${rel}`,
			);
		}

		for (const match of content.matchAll(/<TableHead[^>]*>([\s\S]*?)<\/TableHead>/g)) {
			assert.ok(match[1].includes('t('), `Untranslated <TableHead> in ${rel}: ${match[1].trim()}`);
		}
		for (const match of content.matchAll(/<CardDescription[^>]*>([\s\S]*?)<\/CardDescription>/g)) {
			assert.ok(
				match[1].includes('t(') || match[1].includes('{tabMeta'),
				`Untranslated <CardDescription> in ${rel}: ${match[1].trim()}`,
			);
		}
		for (const match of content.matchAll(/<Label[^>]*>([\s\S]*?)<\/Label>/g)) {
			assert.ok(match[1].includes('t('), `Untranslated <Label> in ${rel}: ${match[1].trim()}`);
		}
	}

	for (const [section, keys] of phpSections.entries()) {
		for (const key of keys) {
			assert.ok(
				usedKeys.has(`${section}.${key}`),
				`Unused translation key "${section}.${key}" in ProcessWireConsole.module.php`,
			);
		}
	}

	const allTsFiles = [...collectFiles(tsRoot, '.ts'), ...tsxFiles];
	for (const file of allTsFiles) {
		const rel = path.relative(moduleRoot, file);
		const content = fs.readFileSync(file, 'utf8');
		assert.ok(
			!/:\s*any\b|<any>|as\s+Record<string,\s*any>/.test(content),
			`Disallowed explicit TypeScript "any" type found in ${rel}`,
		);
	}

	assert.ok(checkedCount >= 420, `Expected at least 420 t() calls across TSX files, checked ${checkedCount}`);
});

test('every openConfirm() and runAction() in TSX matches HandlesWorkspaceActions.php 1-to-1', () => {
	const actionsPhp = fs.readFileSync(
		path.join(moduleRoot, 'src/Concerns/HandlesWorkspaceActions.php'),
		'utf8',
	);

	const serverGuards = new Map<string, { guardKey: string; expectedConfirm: string }>();
	const guardRegex =
		/'([a-zA-Z0-9_]+)'\s*=>\s*\$this->guardAndRun\(\s*tab:\s*'[^']+',\s*permission:\s*[^,]+,\s*guardKey:\s*'([^']+)',\s*expectedConfirm:\s*'([^']+)'/g;

	for (const match of actionsPhp.matchAll(guardRegex)) {
		serverGuards.set(match[1], {
			guardKey: match[2],
			expectedConfirm: match[3],
		});
	}

	assert.equal(serverGuards.size, 34, 'Expected all 34 guarded actions in HandlesWorkspaceActions.php');
	assert.ok(actionsPhp.includes("'inspectFile' => $this->runInspectFileAction"), 'Expected inspectFile action');

	const tsxFiles = collectFiles(tsRoot, '.tsx');
	const wiredActions = new Set<string>();
	let confirmCalls = 0;

	for (const file of tsxFiles) {
		const rel = path.relative(moduleRoot, file);
		const content = fs.readFileSync(file, 'utf8');

		const openConfirmRegex =
			/openConfirm\(\{\s*action:\s*'([^']+)',\s*guardKey:\s*'([^']+)',\s*confirmWord:\s*'([^']+)'/g;
		for (const match of content.matchAll(openConfirmRegex)) {
			const [, action, guardKey, confirmWord] = match;
			confirmCalls++;
			wiredActions.add(action);
			const server = serverGuards.get(action);
			assert.ok(server, `Unknown server action "${action}" in ${rel}`);
			assert.equal(
				guardKey,
				server.guardKey,
				`Guard key mismatch for "${action}" in ${rel}: expected "${server.guardKey}", got "${guardKey}"`,
			);
			assert.equal(
				confirmWord,
				server.expectedConfirm,
				`Confirm word mismatch for "${action}" in ${rel}: expected "${server.expectedConfirm}", got "${confirmWord}"`,
			);
		}
	}

	for (const serverAction of serverGuards.keys()) {
		assert.ok(
			wiredActions.has(serverAction),
			`Server guarded action "${serverAction}" is not wired in any TSX panel`,
		);
	}
	assert.ok(confirmCalls >= 34, `Expected at least 34 openConfirm() calls across TSX panels, found ${confirmCalls}`);
});

test('accessibility & routing contracts hold across TSX panels and Workspace shell', () => {
	const panelFiles = collectFiles(panelsRoot, '.tsx');
	let switchCount = 0;

	for (const file of panelFiles) {
		const rel = path.relative(moduleRoot, file);
		const content = fs.readFileSync(file, 'utf8');

		for (const match of content.matchAll(/<Switch\b([\s\S]*?)\/>/g)) {
			switchCount++;
			const attrs = match[1];
			const idMatch = attrs.match(/\bid="([^"]+)"/);
			assert.ok(idMatch, `Every <Switch /> must declare an explicit id attribute for WCAG 4.1.2 (${rel})`);
			const switchId = idMatch[1];
			const hasInlineLabel = /\blabel=/.test(attrs);
			const hasExternalLabel = content.includes(`htmlFor="${switchId}"`);
			assert.ok(
				hasInlineLabel || hasExternalLabel,
				`Expected label prop or matching <Label htmlFor="${switchId}"> for <Switch id="${switchId}"> in ${rel}`,
			);
		}

		for (const match of content.matchAll(/<div\b([^>]*overflow-y-auto[^>]*)>/g)) {
			const attrs = match[1];
			assert.ok(
				attrs.includes('tabIndex={0}'),
				`Scrollable region with overflow-y-auto in ${rel} must include tabIndex={0} for keyboard accessibility`,
			);
		}
	}

	assert.equal(switchCount, 5, 'Expected all 5 Switch controls across panels to be verified');

	const workspaceTsx = fs.readFileSync(path.join(tsRoot, 'pages/Workspace.tsx'), 'utf8');
	assert.ok(
		!workspaceTsx.includes('router.get(window.location.pathname') &&
			!workspaceTsx.includes('router.get(\n\t\t\twindow.location.pathname'),
		'Workspace.tsx must not use window.location.pathname for GET navigation after POST actions',
	);
	assert.ok(
		workspaceTsx.includes('`${baseUrl}logs/`'),
		'Workspace.tsx handleFilterLogs must route explicitly to `${baseUrl}logs/`',
	);
	assert.ok(
		!workspaceTsx.includes('confirm: confirmWord'),
		'Workspace.tsx must send only canonical [`confirm_${guardKey}`] without generic confirm fallback',
	);

	const actionsPhp = fs.readFileSync(path.join(moduleRoot, 'src/Concerns/HandlesWorkspaceActions.php'), 'utf8');
	assert.ok(
		!/\$payload\[[^\]]+\]\s*\?\?\s*\$payload\[/.test(actionsPhp),
		'HandlesWorkspaceActions.php must enforce a single canonical payload key per parameter without fallback aliases',
	);
});
