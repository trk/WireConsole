<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Commands\TestE2eCommand;

/**
 * Pest PHP and Playwright E2E testing operations for ConsoleRuntime.
 *
 * Encapsulates test file discovery across project, site, template, and module directories,
 * testing environment inspection, strict allowlisted CLI flag sanitization, Playwright E2E
 * initialization, and isolated `proc_open` test execution with timeout enforcement.
 */
trait InteractsWithTesting
{
	/**
	 * Discover all `*Test.php` Pest files and `*.spec.ts` Playwright E2E files across the installation.
	 *
	 * @return list<array{id: string, name: string, relativePath: string, path: string, suite: string, runner: string}>
	 */
	public function getDiscoveredTestFiles(): array
	{
		if ($this->discoveredTestFilesCache !== null) {
			return $this->discoveredTestFilesCache;
		}

		$root = rtrim(str_replace('\\', '/', $this->getProjectRootPath()), '/') . '/';
		$rootReal = realpath($root);
		$normalizedRoot = $rootReal !== false
			? rtrim(str_replace('\\', '/', $rootReal), '/') . '/'
			: $root;

		$discoverer = $this->createDiscoverer();

		$pestDirs = [];
		if (is_dir($root . 'tests')) {
			$pestDirs[] = $root . 'tests';
		}
		if (is_dir($root . 'site/tests')) {
			$pestDirs[] = $root . 'site/tests';
		}
		if ($discoverer !== null) {
			foreach ($discoverer->discoverPaths('tests') as $dir) {
				$pestDirs[] = $dir;
			}
		} else {
			$moduleTestDirs = glob($root . 'site/modules/*/tests', GLOB_ONLYDIR);
			if (is_array($moduleTestDirs)) {
				foreach ($moduleTestDirs as $modTestDir) {
					$pestDirs[] = $modTestDir;
				}
			}
		}

		$templatesPath = rtrim(str_replace('\\', '/', (string) ($this->wire()->config?->paths?->templates ?? ($root . 'site/templates'))), '/');
		$e2eDirs = [];
		foreach ([
			$root . 'tests/e2e',
			$root . 'site/tests/e2e',
			$templatesPath . '/tests/e2e',
		] as $candidateE2eDir) {
			if (is_dir($candidateE2eDir)) {
				$e2eDirs[] = $candidateE2eDir;
			}
		}
		if ($discoverer !== null) {
			foreach ($discoverer->discoverPaths('tests/e2e') as $dir) {
				$e2eDirs[] = $dir;
			}
		} else {
			$moduleE2eDirs = glob($root . 'site/modules/*/tests/e2e', GLOB_ONLYDIR);
			if (is_array($moduleE2eDirs)) {
				foreach ($moduleE2eDirs as $modE2eDir) {
					$e2eDirs[] = $modE2eDir;
				}
			}
		}

		$discovered = [];
		$seenPaths = [];

		foreach ($pestDirs as $dir) {
			if (!is_dir($dir) || is_link(rtrim($dir, '/'))) {
				continue;
			}
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
				);
				foreach ($iterator as $file) {
					if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
						continue;
					}

					$realFile = $file->getRealPath();
					if ($realFile === false) {
						continue;
					}

					$normalizedFile = str_replace('\\', '/', $realFile);
					if (!str_starts_with($normalizedFile, $normalizedRoot) || isset($seenPaths[$normalizedFile])) {
						continue;
					}
					$seenPaths[$normalizedFile] = true;

					$relPath = ltrim(substr($normalizedFile, strlen($normalizedRoot)), '/');
					$name = (string) preg_replace('/\.php$/i', '', $file->getFilename());
					$suite = 'Project';
					if (str_starts_with($relPath, 'site/modules/')) {
						$parts = explode('/', $relPath);
						$suite = isset($parts[2]) ? 'Module: ' . $parts[2] : 'Module';
					} elseif (str_contains($relPath, '/Unit/')) {
						$suite = 'Unit';
					} elseif (str_contains($relPath, '/Feature/')) {
						$suite = 'Feature';
					}

					$discovered[] = [
						'id' => substr(sha1($relPath), 0, 16),
						'name' => $name,
						'relativePath' => $relPath,
						'path' => $normalizedFile,
						'suite' => $suite,
						'runner' => 'pest',
					];
				}
			} catch (\Throwable) {
				// Ignore unreadable directory
			}
		}

		foreach ($e2eDirs as $dir) {
			if (!is_dir($dir) || is_link(rtrim($dir, '/'))) {
				continue;
			}
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
				);
				foreach ($iterator as $file) {
					if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile() || !str_ends_with($file->getFilename(), '.spec.ts')) {
						continue;
					}

					$realFile = $file->getRealPath();
					if ($realFile === false) {
						continue;
					}

					$normalizedFile = str_replace('\\', '/', $realFile);
					if (!str_starts_with($normalizedFile, $normalizedRoot) || isset($seenPaths[$normalizedFile])) {
						continue;
					}
					$seenPaths[$normalizedFile] = true;

					$relPath = ltrim(substr($normalizedFile, strlen($normalizedRoot)), '/');
					$name = $file->getFilename();
					$suite = 'E2E';
					if (str_starts_with($relPath, 'site/modules/')) {
						$parts = explode('/', $relPath);
						$suite = isset($parts[2]) ? 'Module: ' . $parts[2] : 'Module E2E';
					} elseif (str_starts_with($relPath, 'site/templates/')) {
						$suite = 'Templates E2E';
					}

					$discovered[] = [
						'id' => substr(sha1($relPath), 0, 16),
						'name' => $name,
						'relativePath' => $relPath,
						'path' => $normalizedFile,
						'suite' => $suite,
						'runner' => 'playwright',
					];
				}
			} catch (\Throwable) {
				// Ignore unreadable directory
			}
		}

		usort($discovered, static fn(array $a, array $b): int => strcmp($a['relativePath'], $b['relativePath']));

		return $this->discoveredTestFilesCache = $discovered;
	}

	/**
	 * Inspect the Pest PHP and Playwright E2E testing environment.
	 *
	 * @return array{
	 *   ready: bool,
	 *   hasPestBinary: bool,
	 *   hasWireBinary: bool,
	 *   hasTestsDir: bool,
	 *   hasPhpunitXml: bool,
	 *   testFileCount: int,
	 *   e2eReady: bool,
	 *   hasPlaywrightBinary: bool,
	 *   hasPlaywrightConfig: bool,
	 *   e2eFileCount: int,
	 *   procOpenAvailable: bool
	 * }
	 */
	public function getTestingEnvironment(): array
	{
		$root = $this->getProjectRootPath();
		$hasPest = is_file($root . 'vendor/bin/pest') || is_file($root . 'vendor/bin/pest.bat');
		$hasWire = is_file($root . 'vendor/bin/wire') || is_file($root . 'vendor/bin/wire.bat');
		$hasTestsDir = is_dir($root . 'tests') || is_dir($root . 'site/tests');
		$hasPhpunitXml = is_file($root . 'phpunit.xml') || is_file($root . 'phpunit.xml.dist');
		$playwrightBinary = $this->isConsolePackageAvailable()
			? TestE2eCommand::resolvePlaywrightBinaryPath(rtrim($root, '/'))
			: ($root . 'node_modules/.bin/playwright');
		$hasPlaywright = is_file($playwrightBinary);
		$hasPlaywrightConfig = is_file($root . 'playwright.config.ts') || is_file($root . 'playwright.config.js');
		$procOpen = function_exists('proc_open');

		$pestCount = 0;
		$e2eCount = 0;
		foreach ($this->getDiscoveredTestFiles() as $file) {
			if ($file['runner'] === 'playwright') {
				$e2eCount++;
			} else {
				$pestCount++;
			}
		}

		return [
			'ready' => $hasWire && $hasPest && $procOpen,
			'hasPestBinary' => $hasPest,
			'hasWireBinary' => $hasWire,
			'hasTestsDir' => $hasTestsDir,
			'hasPhpunitXml' => $hasPhpunitXml,
			'testFileCount' => $pestCount,
			'e2eReady' => $hasWire && $hasPlaywright && $hasPlaywrightConfig && $procOpen,
			'hasPlaywrightBinary' => $hasPlaywright,
			'hasPlaywrightConfig' => $hasPlaywrightConfig,
			'e2eFileCount' => $e2eCount,
			'procOpenAvailable' => $procOpen,
		];
	}

	/**
	 * Validate and tokenize CLI arguments for `vendor/bin/wire test`.
	 * Strictly blocks shell metacharacters, path traversal, and non-allowlisted flags.
	 *
	 * @return array{ok: bool, tokens: list<string>, error: ?string}
	 */
	public function sanitizeTestArgs(string $rawArgs): array
	{
		$trimmed = trim($rawArgs);
		if ($trimmed === '') {
			return ['ok' => true, 'tokens' => [], 'error' => null];
		}

		if (strlen($trimmed) > 200) {
			return [
				'ok' => false,
				'tokens' => [],
				'error' => $this->_('Test arguments cannot exceed 200 characters.'),
			];
		}

		// Reject shell control characters, quotes, backticks, redirects, or traversal
		if (preg_match('/[;&|`$><\\\\"\'\r\n\0]/', $trimmed) || str_contains($trimmed, '..')) {
			return [
				'ok' => false,
				'tokens' => [],
				'error' => $this->_('Test arguments contain disallowed shell or path traversal characters.'),
			];
		}

		$parts = preg_split('/\s+/', $trimmed) ?: [];
		$allowedExactFlags = [
			'--stop-on-failure',
			'--bail',
			'--compact',
			'--verbose',
			'-v',
			'--colors=never',
			'--parallel',
		];
		$tokens = [];

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			if (in_array($part, $allowedExactFlags, true)) {
				$tokens[] = $part;
				continue;
			}

			if (preg_match('/^--(filter|group|testsuite)=([A-Za-z0-9_.:\/-]+)$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			// Allow relative test directory/file paths inside tests/, site/tests/, or site/modules/
			if (preg_match('/^(tests|site\/tests|site\/modules\/[A-Za-z0-9_-]+\/tests)(\/[A-Za-z0-9_.-]+)*$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			return [
				'ok' => false,
				'tokens' => [],
				'error' => sprintf(
					$this->_('Unsupported test argument "%s". Allowed: --filter=Name, --group=name, --testsuite=name, --stop-on-failure, --bail, --compact, or a tests/ path.'),
					$part
				),
			];
		}

		return ['ok' => true, 'tokens' => $tokens, 'error' => null];
	}

	/**
	 * Validate and tokenize CLI arguments for `vendor/bin/wire test:e2e`.
	 * Strictly blocks interactive browser flags (`--ui`, `--headed`, `--debug`), shell metacharacters, and path traversal.
	 *
	 * @return array{ok: bool, tokens: list<string>, error: ?string}
	 */
	public function sanitizeE2eArgs(string $rawArgs): array
	{
		$trimmed = trim($rawArgs);
		if ($trimmed === '') {
			return ['ok' => true, 'tokens' => [], 'error' => null];
		}

		if (strlen($trimmed) > 200) {
			return [
				'ok' => false,
				'tokens' => [],
				'error' => $this->_('E2E test arguments cannot exceed 200 characters.'),
			];
		}

		if (preg_match('/[;&|`$><\\\\"\'\r\n\0]/', $trimmed) || str_contains($trimmed, '..')) {
			return [
				'ok' => false,
				'tokens' => [],
				'error' => $this->_('E2E test arguments contain disallowed shell or path traversal characters.'),
			];
		}

		$parts = preg_split('/\s+/', $trimmed) ?: [];
		$tokens = [];

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			if (preg_match('/^--module=([A-Za-z][A-Za-z0-9_]*)$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			if (preg_match('/^--(grep|grep-invert|project)=([A-Za-z0-9_.:\/-]+)$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			if (preg_match('/^--(workers|retries|max-failures|timeout|repeat-each)=([0-9]+)$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			if (preg_match('/^--reporter=(list|line|dot)$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			if (preg_match('/^(tests\/e2e|site\/tests\/e2e|site\/templates\/tests\/e2e|site\/modules\/[A-Za-z0-9_-]+\/tests\/e2e)(\/[A-Za-z0-9_.-]+)*$/', $part)) {
				$tokens[] = $part;
				continue;
			}

			return [
				'ok' => false,
				'tokens' => [],
				'error' => sprintf(
					$this->_('Unsupported E2E argument "%s". Allowed: --module=Name, --grep=pattern, --project=name, --workers=N, --retries=N, --reporter=line, or a tests/e2e/ path.'),
					$part
				),
			];
		}

		return ['ok' => true, 'tokens' => $tokens, 'error' => null];
	}

	/**
	 * Resolve the CLI `php` binary path, ensuring web SAPIs (`php-fpm`, `php-cgi`)
	 * are never invoked as the CLI interpreter when spawning subprocesses.
	 */
	public function resolvePhpCliBinary(?string $currentBinary = null): string
	{
		$binary = $currentBinary ?? PHP_BINARY;
		$exeName = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';

		if ($binary !== '') {
			$base = strtolower(basename($binary));
			$isFpmOrCgi = str_contains($base, 'fpm')
				|| str_contains($base, 'cgi')
				|| str_contains($base, 'httpd')
				|| str_contains($base, 'apache')
				|| str_contains($base, 'nginx');

			if (!$isFpmOrCgi && is_file($binary) && is_executable($binary)) {
				return $binary;
			}
		}

		$candidates = [];
		$phpBinDir = defined('PHP_BINDIR') ? rtrim(trim(PHP_BINDIR), '/\\') : '';
		if ($phpBinDir !== '') {
			$candidates[] = $phpBinDir . DIRECTORY_SEPARATOR . $exeName;
		}
		if ($binary !== '') {
			$candidates[] = dirname(dirname($binary)) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $exeName;
			$candidates[] = dirname($binary) . DIRECTORY_SEPARATOR . $exeName;
		}
		$candidates[] = '/opt/homebrew/bin/php';
		$candidates[] = '/usr/local/bin/php';
		$candidates[] = '/usr/bin/php';

		foreach ($candidates as $candidate) {
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		return 'php';
	}

	/**
	 * Build an environment map for CLI subprocess execution with the resolved PHP CLI directory prepended to PATH.
	 *
	 * @return array<string, string>
	 */
	protected function buildCliSubprocessEnvironment(string $phpCliBinary): array
	{
		$env = [];
		$rawEnv = getenv();
		if (is_array($rawEnv)) {
			foreach ($rawEnv as $key => $val) {
				$env[(string) $key] = (string) $val;
			}
		}

		$pathDirs = [];
		if ($phpCliBinary !== '' && $phpCliBinary !== 'php') {
			$binDir = dirname($phpCliBinary);
			if ($binDir !== '' && is_dir($binDir)) {
				$pathDirs[] = $binDir;
			}
		}
		$phpBinDir = defined('PHP_BINDIR') ? rtrim(trim(PHP_BINDIR), '/\\') : '';
		if ($phpBinDir !== '' && is_dir($phpBinDir)) {
			$pathDirs[] = $phpBinDir;
		}
		foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'] as $extraDir) {
			if (is_dir($extraDir)) {
				$pathDirs[] = $extraDir;
			}
		}

		$existingPath = $env['PATH'] ?? $env['Path'] ?? (string) getenv('PATH');
		if ($existingPath !== '') {
			foreach (explode(PATH_SEPARATOR, $existingPath) as $part) {
				$trimmed = trim($part);
				if ($trimmed !== '') {
					$pathDirs[] = $trimmed;
				}
			}
		}

		$env['PATH'] = implode(PATH_SEPARATOR, array_values(array_unique($pathDirs)));

		return $env;
	}

	/**
	 * Execute `vendor/bin/wire test` with allowlisted arguments and timeout enforcement.
	 *
	 * @return array{ok: bool, exitCode: int, output: string, command: string, message: string}
	 */
	public function runTests(string $rawArgs = '', int $timeoutSeconds = 60): array
	{
		$validated = $this->sanitizeTestArgs($rawArgs);
		if (!$validated['ok']) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'output' => '',
				'command' => '',
				'message' => (string) $validated['error'],
			];
		}

		$env = $this->getTestingEnvironment();
		if (!$env['ready']) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'output' => '',
				'command' => 'vendor/bin/wire test',
				'message' => $this->_('Pest test runner is not available in this environment.'),
			];
		}

		$root = $this->getProjectRootPath();
		$execFlags = ['--colors=never'];
		$moduleNames = [];
		$hasNonModulePath = false;

		foreach ($validated['tokens'] as $tok) {
			if (str_starts_with($tok, '-')) {
				continue;
			}
			if (preg_match('#^site/modules/([A-Za-z0-9_-]+)/tests(?:/|$)#', $tok, $m)) {
				$moduleNames[$m[1]] = true;
			} else {
				$hasNonModulePath = true;
			}
		}

		if (!$hasNonModulePath && count($moduleNames) === 1) {
			$targetMod = (string) array_key_first($moduleNames);
			$modTestsRel = 'site/modules/' . $targetMod . '/tests';
			if (is_dir($root . $modTestsRel)) {
				$execFlags[] = '--test-directory=' . $modTestsRel;
			}
			foreach (['phpunit.xml', 'phpunit.xml.dist'] as $cfgName) {
				$cfgRel = 'site/modules/' . $targetMod . '/' . $cfgName;
				if (is_file($root . $cfgRel)) {
					$execFlags[] = '-c';
					$execFlags[] = $cfgRel;
					break;
				}
			}
		}

		$subcommandArgs = array_merge(['test'], $execFlags, $validated['tokens']);
		$displayCmd = 'vendor/bin/wire test' . ($validated['tokens'] !== [] ? ' ' . implode(' ', $validated['tokens']) : '');

		return $this->executeWireCliSubprocess($subcommandArgs, $displayCmd, $timeoutSeconds);
	}

	/**
	 * Execute `vendor/bin/wire test:e2e` with allowlisted Playwright arguments and timeout enforcement.
	 *
	 * @return array{ok: bool, exitCode: int, output: string, command: string, message: string}
	 */
	public function runE2eTests(string $rawArgs = '', int $timeoutSeconds = 120): array
	{
		$validated = $this->sanitizeE2eArgs($rawArgs);
		if (!$validated['ok']) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'output' => '',
				'command' => '',
				'message' => (string) $validated['error'],
			];
		}

		$env = $this->getTestingEnvironment();
		if (!$env['e2eReady']) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'output' => '',
				'command' => 'vendor/bin/wire test:e2e',
				'message' => !$env['hasPlaywrightBinary']
					? $this->_('Playwright binary is not installed in node_modules/.bin/playwright.')
					: $this->_('Playwright E2E configuration (playwright.config.ts) is missing. Initialize Playwright first.'),
			];
		}

		$tokens = $validated['tokens'];
		$hasReporter = false;
		foreach ($tokens as $tok) {
			if (str_starts_with($tok, '--reporter=')) {
				$hasReporter = true;
				break;
			}
		}
		$execTokens = $hasReporter ? $tokens : array_merge($tokens, ['--reporter=line']);
		$subcommandArgs = array_merge(['test:e2e'], $execTokens);
		$displayCmd = 'vendor/bin/wire test:e2e' . ($tokens !== [] ? ' ' . implode(' ', $tokens) : '');

		$extraEnv = [
			'CI' => '1',
			'NO_COLOR' => '1',
		];
		$config = $this->wire()->config;
		if ($config !== null) {
			$existingBaseUrl = getenv('PW_BASE_URL');
			if ((!is_string($existingBaseUrl) || trim($existingBaseUrl) === '') && $this->isConsolePackageAvailable()) {
				$extraEnv['PW_BASE_URL'] = TestE2eCommand::resolveBaseUrl($config);
			}
			$existingAdminUrl = getenv('PW_ADMIN_URL');
			if (!is_string($existingAdminUrl) || trim($existingAdminUrl) === '') {
				$rawAdminUrl = trim((string) ($config->urls?->admin ?? '/processwire/'), '/');
				$extraEnv['PW_ADMIN_URL'] = $rawAdminUrl !== '' ? '/' . $rawAdminUrl . '/' : '/processwire/';
			}
		}

		return $this->executeWireCliSubprocess($subcommandArgs, $displayCmd, $timeoutSeconds, $extraEnv);
	}

	/**
	 * Non-destructively initialize Playwright configuration and ProcessWire E2E helpers (`wire test:e2e --init`).
	 *
	 * @return array{ok: bool, created: list<string>, skipped: list<string>, details: string, message: string}
	 */
	public function initPlaywrightE2e(): array
	{
		$result = $this->runConsoleCommandInProcess(TestE2eCommand::class, [
			'--init' => true,
		]);
		$this->discoveredTestFilesCache = null;

		if (!$result['ok']) {
			return [
				'ok' => false,
				'created' => [],
				'skipped' => [],
				'details' => '',
				'message' => (string) ($result['error']['message'] ?? $this->_('Failed to initialize Playwright E2E configuration.')),
			];
		}

		$createdRaw = is_array($result['data']['created'] ?? null) ? $result['data']['created'] : [];
		$skippedRaw = is_array($result['data']['skipped'] ?? null) ? $result['data']['skipped'] : [];
		$created = array_values(array_filter(array_map('strval', $createdRaw), static fn(string $v): bool => $v !== ''));
		$skipped = array_values(array_filter(array_map('strval', $skippedRaw), static fn(string $v): bool => $v !== ''));

		$lines = [];
		foreach ($created as $item) {
			$lines[] = '+ Created: ' . $item;
		}
		foreach ($skipped as $item) {
			$lines[] = '= Preserved existing: ' . $item;
		}

		return [
			'ok' => true,
			'created' => $created,
			'skipped' => $skipped,
			'details' => implode("\n", $lines),
			'message' => sprintf(
				$this->_('Initialized Playwright E2E configuration (%d created, %d preserved).'),
				count($created),
				count($skipped)
			),
		];
	}

	/**
	 * Execute a `vendor/bin/wire` subcommand in an isolated `proc_open` subprocess with timeout enforcement.
	 *
	 * @param list<string> $subcommandAndArgs
	 * @param array<string, string> $extraEnv
	 * @return array{ok: bool, exitCode: int, output: string, command: string, message: string}
	 */
	protected function executeWireCliSubprocess(
		array $subcommandAndArgs,
		string $displayCmd,
		int $timeoutSeconds,
		array $extraEnv = []
	): array {
		$root = $this->getProjectRootPath();
		$phpBin = $this->resolvePhpCliBinary();
		$wireBin = $root . 'vendor/bin/wire';
		$cmdArray = array_merge([$phpBin, $wireBin], $subcommandAndArgs);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$envVars = array_merge($this->buildCliSubprocessEnvironment($phpBin), $extraEnv);
		$process = @proc_open($cmdArray, $descriptors, $pipes, $root, $envVars);
		if (!is_resource($process)) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'output' => '',
				'command' => $displayCmd,
				'message' => $this->_('Failed to start the test runner process.'),
			];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$maxOutputBytes = 524288;
		$appendBounded = static function (string &$buffer, mixed $pipe) use ($maxOutputBytes): void {
			if (!is_resource($pipe)) {
				return;
			}
			while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
				$remaining = $maxOutputBytes - strlen($buffer);
				if ($remaining > 0) {
					$buffer .= substr($chunk, 0, $remaining);
				}
			}
		};

		$start = microtime(true);
		$timeout = max(5, min(180, $timeoutSeconds));
		$timedOut = false;

		while (true) {
			$status = proc_get_status($process);
			$appendBounded($stdout, $pipes[1]);
			$appendBounded($stderr, $pipes[2]);

			if (!$status['running']) {
				break;
			}

			if ((microtime(true) - $start) > $timeout) {
				$timedOut = true;
				@proc_terminate($process, 9);
				break;
			}

			usleep(50000);
		}

		$appendBounded($stdout, $pipes[1]);
		$appendBounded($stderr, $pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		$combined = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
		// Strip any residual ANSI escape sequences
		$cleanOutput = (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $combined);

		if ($timedOut) {
			return [
				'ok' => false,
				'exitCode' => 124,
				'output' => $cleanOutput,
				'command' => $displayCmd,
				'message' => sprintf($this->_('Test execution timed out after %d seconds.'), $timeout),
			];
		}

		$passed = $exitCode === 0;

		return [
			'ok' => $passed,
			'exitCode' => $exitCode,
			'output' => $cleanOutput,
			'command' => $displayCmd,
			'message' => $passed
				? $this->_('Test suite completed with zero failures.')
				: sprintf($this->_('Test suite exited with code %d.'), $exitCode),
		];
	}
}
