<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

/**
 * Pest PHP testing operations for ConsoleRuntime.
 *
 * Encapsulates test file discovery across `tests/` and `site/modules/<module>/tests/`,
 * testing environment inspection, strict allowlisted CLI flag sanitization, and
 * isolated `proc_open` test execution with timeout enforcement.
 */
trait InteractsWithTesting
{
	/**
	 * Discover all `*Test.php` Pest/PHPUnit test files inside `tests/` and `site/modules/<module>/tests/`.
	 *
	 * @return list<array{id: string, name: string, relativePath: string, path: string, suite: string}>
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

		$searchDirs = [];
		if (is_dir($root . 'tests')) {
			$searchDirs[] = $root . 'tests';
		}

		$moduleTestDirs = glob($root . 'site/modules/*/tests', GLOB_ONLYDIR);
		if (is_array($moduleTestDirs)) {
			foreach ($moduleTestDirs as $modTestDir) {
				$searchDirs[] = $modTestDir;
			}
		}

		$discovered = [];
		foreach ($searchDirs as $dir) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
				);
				foreach ($iterator as $file) {
					if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
						continue;
					}

					$realFile = $file->getRealPath();
					if ($realFile === false) {
						continue;
					}

					$normalizedFile = str_replace('\\', '/', $realFile);
					if (!str_starts_with($normalizedFile, $normalizedRoot)) {
						continue;
					}

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
	 * Inspect the testing environment (Pest binary, phpunit.xml, tests/ directory).
	 *
	 * @return array{
	 *   ready: bool,
	 *   hasPestBinary: bool,
	 *   hasWireBinary: bool,
	 *   hasTestsDir: bool,
	 *   hasPhpunitXml: bool,
	 *   testFileCount: int,
	 *   procOpenAvailable: bool
	 * }
	 */
	public function getTestingEnvironment(): array
	{
		$root = $this->getProjectRootPath();
		$hasPest = is_file($root . 'vendor/bin/pest') || is_file($root . 'vendor/bin/pest.bat');
		$hasWire = is_file($root . 'vendor/bin/wire') || is_file($root . 'vendor/bin/wire.bat');
		$hasTestsDir = is_dir($root . 'tests');
		$hasPhpunitXml = is_file($root . 'phpunit.xml') || is_file($root . 'phpunit.xml.dist');
		$procOpen = function_exists('proc_open');
		$testFileCount = count($this->getDiscoveredTestFiles());

		return [
			'ready' => $hasWire && $hasPest && $procOpen,
			'hasPestBinary' => $hasPest,
			'hasWireBinary' => $hasWire,
			'hasTestsDir' => $hasTestsDir,
			'hasPhpunitXml' => $hasPhpunitXml,
			'testFileCount' => $testFileCount,
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

			// Allow relative test directory/file paths inside tests/ or site/modules/
			if (preg_match('/^(tests|site\/modules\/[A-Za-z0-9_-]+\/tests)(\/[A-Za-z0-9_.-]+)*$/', $part)) {
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
		if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
			$candidates[] = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . $exeName;
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
				if (is_string($key) && is_scalar($val)) {
					$env[$key] = (string) $val;
				}
			}
		}

		$pathDirs = [];
		if ($phpCliBinary !== '' && $phpCliBinary !== 'php') {
			$binDir = dirname($phpCliBinary);
			if ($binDir !== '' && is_dir($binDir)) {
				$pathDirs[] = $binDir;
			}
		}
		if (defined('PHP_BINDIR') && PHP_BINDIR !== '' && is_dir(PHP_BINDIR)) {
			$pathDirs[] = rtrim(PHP_BINDIR, '/\\');
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
		$phpBin = $this->resolvePhpCliBinary();
		$wireBin = $root . 'vendor/bin/wire';

		$cmdArray = array_merge([$phpBin, $wireBin, 'test', '--colors=never'], $validated['tokens']);
		$displayCmd = 'vendor/bin/wire test' . ($validated['tokens'] !== [] ? ' ' . implode(' ', $validated['tokens']) : '');

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$envVars = $this->buildCliSubprocessEnvironment($phpBin);
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
		$start = microtime(true);
		$timeout = max(5, min(180, $timeoutSeconds));
		$timedOut = false;

		while (true) {
			$status = proc_get_status($process);
			$stdout .= (string) stream_get_contents($pipes[1]);
			$stderr .= (string) stream_get_contents($pipes[2]);

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

		$stdout .= (string) stream_get_contents($pipes[1]);
		$stderr .= (string) stream_get_contents($pipes[2]);
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
