<?php // __(file-not-translatable)

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$projectRoot = dirname($moduleRoot, 3);

$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
	require_once $autoloadPath;
}

$coreDir = $projectRoot . '/wire/core/';
foreach (['Interfaces.php', 'ProcessWire.php', 'Wire.php', 'WireData.php', 'Module.php', 'Functions.php', 'FunctionsAPI.php'] as $coreBootFile) {
	if (is_file($coreDir . $coreBootFile)) {
		require_once $coreDir . $coreBootFile;
	}
}

spl_autoload_register(static function (string $class) use ($projectRoot, $moduleRoot): void {
	$psr4Prefixes = [
		'Totoglu\\WireConsole\\' => $moduleRoot . '/src/',
		'Totoglu\\Inertia\\' => $projectRoot . '/site/modules/Inertia/src/',
		'Totoglu\\React\\' => $projectRoot . '/site/modules/React/src/',
	];

	foreach ($psr4Prefixes as $prefix => $baseDir) {
		if (str_starts_with($class, $prefix)) {
			$rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			$candidate = $baseDir . $rel;
			if (is_file($candidate)) {
				require_once $candidate;
			}

			return;
		}
	}

	if (!str_starts_with($class, 'ProcessWire\\')) {
		return;
	}

	$short = substr($class, strlen('ProcessWire\\'));
	if ($short === '' || str_contains($short, '\\')) {
		return;
	}

	$directCandidates = [
		$projectRoot . '/wire/core/' . $short . '.php',
		$projectRoot . '/site/modules/Inertia/' . $short . '.module.php',
		$projectRoot . '/site/modules/React/' . $short . '.module.php',
		$moduleRoot . '/' . $short . '.module.php',
	];

	foreach ($directCandidates as $candidate) {
		if (is_file($candidate)) {
			require_once $candidate;

			return;
		}
	}

	$coreMatches = glob($projectRoot . '/wire/core/{' . $short . ',*/' . $short . '}.php', GLOB_BRACE);
	if (is_array($coreMatches) && isset($coreMatches[0]) && is_file($coreMatches[0])) {
		require_once $coreMatches[0];

		return;
	}

	$wireMatches = glob($projectRoot . '/wire/modules/{*,*/*}/' . $short . '{.module,.module.php,.php}', GLOB_BRACE);
	if (is_array($wireMatches) && isset($wireMatches[0]) && is_file($wireMatches[0])) {
		require_once $wireMatches[0];
	}
});

foreach ([
	$coreDir . 'WireDatabasePDOStatement.php',
	$coreDir . 'WireDatabaseBackup.php',
	$projectRoot . '/site/modules/Inertia/Inertia.module.php',
	$projectRoot . '/site/modules/React/React.module.php',
] as $preloadFile) {
	if (is_file($preloadFile)) {
		require_once $preloadFile;
	}
}

