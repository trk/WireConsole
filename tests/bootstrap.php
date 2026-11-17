<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\ProcessWire;
use ProcessWire\ProcessWireConsole;

$moduleRoot = dirname(__DIR__);
$projectRoot = dirname($moduleRoot, 3);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (is_file($autoloadPath)) {
	require_once $autoloadPath;
}

if (!function_exists('ProcessWire\\wire') || ProcessWire::getCurrentInstance() === null) {
	$_SERVER['HTTP_HOST'] ??= 'localhost';
	require_once $projectRoot . '/wire/core/ProcessWire.php';
	try {
		new ProcessWire(ProcessWire::buildConfig($projectRoot));
	} catch (\Throwable $bootException) {
		if (ProcessWire::getCurrentInstance() === null) {
			throw $bootException;
		}
	}
}

$pw = ProcessWire::getCurrentInstance();
if ($pw?->hooks && $pw?->pages) {
	$allowedDirs = [
		str_replace('\\', '/', $moduleRoot) . '/',
		str_replace('\\', '/', $projectRoot . '/site/modules/Inertia') . '/',
		str_replace('\\', '/', $projectRoot . '/site/modules/ProcessReactWorkspace') . '/',
	];
	$siteModulesDir = str_replace('\\', '/', $projectRoot . '/site/modules') . '/';

	foreach ($pw->hooks->getHooks($pw->pages) as $hookId => $hook) {
		$toMethod = $hook['toMethod'] ?? null;
		$hookFile = '';
		if ($toMethod instanceof \Closure) {
			$hookFile = str_replace('\\', '/', (string) (new \ReflectionFunction($toMethod))->getFileName());
		} elseif (isset($hook['toObject']) && is_object($hook['toObject'])) {
			$hookFile = str_replace('\\', '/', (string) (new \ReflectionObject($hook['toObject']))->getFileName());
		}
		if ($hookFile === '' || !str_starts_with($hookFile, $siteModulesDir)) {
			continue;
		}
		$allowed = false;
		foreach ($allowedDirs as $dir) {
			if (str_starts_with($hookFile, $dir)) {
				$allowed = true;
				break;
			}
		}
		if (!$allowed) {
			$pw->hooks->removeHook($pw->pages, (string) ($hook['id'] ?? $hookId));
		}
	}
}
$classLoader = $pw?->classLoader ?? \ProcessWire\wire('classLoader');

$inertiaRoot = $projectRoot . '/site/modules/Inertia';
if (is_file($inertiaRoot . '/Inertia.module.php')) {
	require_once $inertiaRoot . '/Inertia.module.php';
}

$reactWorkspaceRoot = $projectRoot . '/site/modules/ProcessReactWorkspace';
if (is_file($reactWorkspaceRoot . '/ProcessReactWorkspace.module.php')) {
	require_once $reactWorkspaceRoot . '/ProcessReactWorkspace.module.php';
}

if ($classLoader) {
	if (is_dir($inertiaRoot)) {
		$classLoader->addNamespace('Totoglu\\Inertia', $inertiaRoot . '/src/');
	}
	if (is_dir($reactWorkspaceRoot)) {
		$classLoader->addNamespace('Totoglu\\ProcessReactWorkspace', $reactWorkspaceRoot . '/src/');
	}
}

require_once $moduleRoot . '/ProcessWireConsole.module.php';
(new ProcessWireConsole());

if (!function_exists('bootProcessReactWorkspaceForTesting')) {
	function bootProcessReactWorkspaceForTesting(): \ProcessWire\ProcessReactWorkspace
	{
		$pw = ProcessWire::getCurrentInstance();
		$modules = $pw?->modules;
		$workspace = $pw?->workspace;
		if (!$workspace instanceof \ProcessWire\ProcessReactWorkspace) {
			if ($modules && $modules->isInstalled('ProcessReactWorkspace')) {
				try {
					$installed = $modules->getModule('ProcessReactWorkspace', ['noPermissionCheck' => true]);
					if ($installed instanceof \ProcessWire\ProcessReactWorkspace) {
						$workspace = $installed;
					}
				} catch (\Throwable) {
				}
			}
			if (!$workspace instanceof \ProcessWire\ProcessReactWorkspace) {
				$workspace = new \ProcessWire\ProcessReactWorkspace();
				if ($pw) {
					$pw->wire($workspace);
				}
				$workspace->wired();
				$workspace->init();
			}
		}
		if ($modules) {
			$modules->set('ProcessReactWorkspace', $workspace);
		}
		$pw?->fuel?->set('workspace', $workspace);

		return $workspace;
	}
}

bootProcessReactWorkspaceForTesting();

if (!function_exists('wcTestModule')) {
	/**
	 * Instantiate and wire a fresh ProcessWireConsole instance for Pest tests.
	 */
	function wcTestModule(): ProcessWireConsole
	{
		$pw = ProcessWire::getCurrentInstance();
		bootProcessReactWorkspaceForTesting();

		$adminPage = $pw?->pages?->get(2);
		if ($adminPage && $adminPage->id) {
			$pw?->fuel?->set('page', $adminPage);
		}

		$module = new ProcessWireConsole();
		if ($pw) {
			$module->setWire($pw);
		}
		$module->init();

		return $module;
	}

	/**
	 * Switch the current ProcessWire test user to the superuser (ID 41).
	 */
	function wcSetSuperuser(): void
	{
		$pw = ProcessWire::getCurrentInstance();
		$users = $pw?->users;
		if (!$users) {
			return;
		}
		$admin = $users->get(41);
		if (!$admin || !$admin->id) {
			$admin = $users->get('roles=superuser');
		}
		if ($admin && $admin->id) {
			$users->setCurrentUser($admin);
		}
	}
}
