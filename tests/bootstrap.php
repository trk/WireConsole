<?php

declare(strict_types=1);

use ProcessWire\ProcessWire;
use ProcessWire\ProcessWireConsole;
use ProcessWire\WireKit;

$moduleRoot = dirname(__DIR__);
$projectRoot = dirname($moduleRoot, 3);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (is_file($autoloadPath)) {
	require_once $autoloadPath;
}

if (!function_exists('ProcessWire\\wire') || ProcessWire::getCurrentInstance() === null) {
	require_once $projectRoot . '/index.php';
}

$pw = ProcessWire::getCurrentInstance();
$classLoader = $pw?->classLoader ?? \ProcessWire\wire('classLoader');

$wireKitRoot = $projectRoot . '/site/modules/WireKit';
if (is_file($wireKitRoot . '/WireKit.module.php')) {
	require_once $wireKitRoot . '/WireKit.module.php';
}

if ($classLoader) {
	if (is_dir($wireKitRoot)) {
		$classLoader->addNamespace('Totoglu\\WireKit', $wireKitRoot . '/src/');
		$classLoader->addNamespace('Htmx\\Component', $wireKitRoot . '/components/');
		$classLoader->addNamespace('Htmx\\Ui', $wireKitRoot . '/ui/');
	}
	$classLoader->addNamespace('Totoglu\\WireConsole', $moduleRoot . '/src/');
	$classLoader->addNamespace('Htmx\\Component', $moduleRoot . '/components/');
	$classLoader->addNamespace('Htmx\\Ui', $moduleRoot . '/ui/');
}

require_once $moduleRoot . '/ProcessWireConsole.module.php';

if (!function_exists('wcTestModule')) {
	/**
	 * Instantiate and wire a fresh ProcessWireConsole instance for Pest tests.
	 */
	function wcTestModule(): ProcessWireConsole
	{
		$pw = ProcessWire::getCurrentInstance();

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
