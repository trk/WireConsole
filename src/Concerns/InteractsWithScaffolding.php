<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Commands\MakeMigrationCommand;
use Totoglu\Console\Commands\MakeModuleCommand;
use Totoglu\Console\Commands\MakeQueueCommand;
use Totoglu\Console\Commands\MakeSeederCommand;
use Totoglu\Console\Commands\MakeTaskCommand;
use Totoglu\Console\Commands\MakeTestCommand;

/**
 * Scaffolding (`make:*`) and Read-Only File Inspection (`inspectProjectFile`) operations for ConsoleRuntime.
 */
trait InteractsWithScaffolding
{
	/**
	 * Validate a PHP class or migration identifier (`/^[A-Za-z][A-Za-z0-9_]*$/`).
	 */
	public function isValidIdentifier(string $name): bool
	{
		return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', trim($name)) === 1;
	}

	/**
	 * Scaffold a Migration, Queue, Seeder, Task, Pest/Playwright Test, or ProcessWire Module file
	 * by delegating to upstream `trk/processwire-console` `Make*Command` classes in-process.
	 *
	 * @param string $kind One of: 'migration', 'queue', 'seeder', 'task', 'test', 'module'
	 * @param string $rawName Target class, migration, or E2E spec identifier
	 * @param array<string, mixed> $options Optional command parameters ('type', 'module_type', 'test_kind', 'module')
	 * @return array{ok: bool, file: ?string, path: ?string, message: string}
	 */
	public function scaffoldComponentFile(string $kind, string $rawName, array $options = []): array
	{
		$name = trim($rawName);

		if ($kind === 'migration') {
			if (!$this->isValidIdentifier($name)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => $this->_('Invalid migration name. Use letters, numbers, and underscores starting with a letter (e.g. create_blog_fields).'),
				];
			}

			$allowedTypes = [
				'blank',
				'create-field',
				'create-template',
				'attach-field',
				'create-page',
				'create-role',
				'install-module',
			];
			$rawType = isset($options['type']) && is_string($options['type']) ? trim($options['type']) : 'blank';
			$type = $rawType !== '' ? $rawType : 'blank';
			if (!in_array($type, $allowedTypes, true)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => sprintf($this->_('Unsupported migration stub type "%s".'), $type),
				];
			}

			$result = $this->runConsoleCommandInProcess(MakeMigrationCommand::class, [
				'name' => $name,
				'--type' => $type,
			]);
			$this->migratorInstance = null;

			if (!$result['ok']) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => (string) ($result['error']['message'] ?? $this->_('Failed to write migration file to site/migrations/.')),
				];
			}

			$filePath = (string) ($result['data']['path'] ?? '');
			$fileName = (string) ($result['data']['file'] ?? basename($filePath));

			return [
				'ok' => true,
				'file' => $fileName,
				'path' => $filePath,
				'message' => sprintf($this->_('Created migration: site/migrations/%s'), $fileName),
			];
		}

		if ($kind === 'module') {
			if (!$this->isValidIdentifier($name)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => $this->_('Invalid class name. Use PascalCase letters and numbers starting with a letter (e.g. SendWelcomeEmail).'),
				];
			}

			$allowedModuleTypes = ['module', 'fieldtype', 'inputfield', 'process'];
			$rawModType = isset($options['module_type']) && is_string($options['module_type']) ? strtolower(trim($options['module_type'])) : 'module';
			$moduleType = $rawModType !== '' ? $rawModType : 'module';
			if (!in_array($moduleType, $allowedModuleTypes, true)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => sprintf($this->_('Unsupported module stub type "%s".'), $moduleType),
				];
			}

			$result = $this->runConsoleCommandInProcess(MakeModuleCommand::class, [
				'name' => $name,
				'--type' => $moduleType,
			]);

			if (!$result['ok']) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => (string) ($result['error']['message'] ?? sprintf($this->_('Failed to write module file to site/modules/%s/.'), $name)),
				];
			}

			$filePath = (string) ($result['data']['path'] ?? '');
			$fileName = basename($filePath);

			try {
				$this->wire()->modules->refresh();
			} catch (\Throwable) {
				// Ignore registry refresh errors during scaffolding
			}

			return [
				'ok' => true,
				'file' => $fileName,
				'path' => $filePath,
				'message' => sprintf($this->_('Created site/modules/%s/%s and refreshed module registry.'), $name, $fileName),
			];
		}

		if ($kind === 'test') {
			$rawTestKind = isset($options['test_kind']) && is_string($options['test_kind']) ? strtolower(trim($options['test_kind'])) : 'feature';
			$testKind = $rawTestKind !== '' ? $rawTestKind : 'feature';
			if (!in_array($testKind, ['feature', 'unit', 'e2e'], true)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => sprintf($this->_('Unsupported test scaffold kind "%s".'), $testKind),
				];
			}

			if ($testKind === 'e2e') {
				if (MakeTestCommand::normalizeE2eSpecSlug($name) === null) {
					return [
						'ok' => false,
						'file' => null,
						'path' => null,
						'message' => $this->_('Invalid E2E spec name. Start with a letter and use letters, numbers, underscores, or hyphens (e.g. checkout-flow).'),
					];
				}
			} elseif (!$this->isValidIdentifier($name)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => $this->_('Invalid class name. Use PascalCase letters and numbers starting with a letter (e.g. SendWelcomeEmail).'),
				];
			}

			$params = ['name' => $name];
			if ($testKind === 'unit') {
				$params['--unit'] = true;
			} elseif ($testKind === 'e2e') {
				$params['--e2e'] = true;
			}

			$targetModule = isset($options['module']) && is_string($options['module']) ? trim($options['module']) : '';
			if ($targetModule !== '') {
				if (!$this->isValidIdentifier($targetModule)) {
					return [
						'ok' => false,
						'file' => null,
						'path' => null,
						'message' => $this->_('Invalid target module name.'),
					];
				}
				$params['--module'] = $targetModule;
			}

			$result = $this->runConsoleCommandInProcess(MakeTestCommand::class, $params);
			$this->discoveredTestFilesCache = null;

			if (!$result['ok']) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => (string) ($result['error']['message'] ?? $this->_('Failed to create test file.')),
				];
			}

			$filePath = (string) ($result['data']['path'] ?? '');
			$fileName = basename($filePath);
			$relPath = $this->toProjectRelativePath($filePath);

			return [
				'ok' => true,
				'file' => $fileName,
				'path' => $filePath,
				'message' => sprintf($this->_('Created %s'), $relPath),
			];
		}

		$commandClass = match ($kind) {
			'queue' => MakeQueueCommand::class,
			'seeder' => MakeSeederCommand::class,
			'task' => MakeTaskCommand::class,
			default => null,
		};

		if ($commandClass === null) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Unsupported scaffold type.'),
			];
		}

		if (!$this->isValidIdentifier($name)) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Invalid class name. Use PascalCase letters and numbers starting with a letter (e.g. SendWelcomeEmail).'),
			];
		}

		$params = ['name' => $name];
		$targetModule = isset($options['module']) && is_string($options['module']) ? trim($options['module']) : '';
		if ($targetModule !== '') {
			if (!$this->isValidIdentifier($targetModule)) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => $this->_('Invalid target module name.'),
				];
			}
			$params['--module'] = $targetModule;
		}

		$result = $this->runConsoleCommandInProcess($commandClass, $params);
		if ($kind === 'queue') {
			$this->discoveredQueuesCache = null;
			$this->queuesSummaryCache = null;
		}

		if (!$result['ok']) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => (string) ($result['error']['message'] ?? $this->_('Failed to create component file.')),
			];
		}

		$filePath = (string) ($result['data']['path'] ?? '');
		$fileName = basename($filePath);
		$relPath = $this->toProjectRelativePath($filePath);

		return [
			'ok' => true,
			'file' => $fileName,
			'path' => $filePath,
			'message' => sprintf($this->_('Created %s'), $relPath),
		];
	}

	/**
	 * Convert an absolute file path inside the project root into a normalized POSIX relative path.
	 */
	protected function toProjectRelativePath(string $filePath): string
	{
		$normalizedPath = str_replace('\\', '/', $filePath);
		$normalizedRoot = rtrim(str_replace('\\', '/', $this->getProjectRootPath()), '/') . '/';
		if (str_starts_with($normalizedPath, $normalizedRoot)) {
			return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
		}

		$realPath = realpath($filePath);
		$realRoot = realpath($this->getProjectRootPath());
		if ($realPath !== false && $realRoot !== false) {
			$normRealPath = str_replace('\\', '/', $realPath);
			$normRealRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
			if (str_starts_with($normRealPath, $normRealRoot)) {
				return ltrim(substr($normRealPath, strlen($normRealRoot)), '/');
			}
		}

		return basename($normalizedPath);
	}

	/**
	 * Safely read a discovered Migration, Queue, Seeder, Task, Test, Backup, Boost Skill, or Boost Document file for read-only inspection.
	 * Rejects path traversal and any file outside the allowlisted discovery map.
	 *
	 * @param string $category One of: 'migration', 'queue', 'seeder', 'task', 'test', 'backup', 'skill', 'boost-doc'
	 * @return ?array{category: string, identifier: string, relativePath: string, lineCount: int, sizeFormatted: string, content: string}
	 */
	public function inspectProjectFile(string $category, string $identifier): ?array
	{
		$cleanCategory = strtolower(trim($category));
		$cleanId = trim($identifier);
		if (!$this->userCanInspectCategory($cleanCategory)) {
			$this->audit('file.inspect', 'denied_rbac', [
				'category' => $cleanCategory,
				'identifier' => $cleanId,
			]);

			return null;
		}
		if ($cleanId === '' || str_contains($cleanId, "\0") || str_contains($cleanId, '..') || str_contains($cleanId, '/') || str_contains($cleanId, '\\')) {
			return null;
		}

		$root = rtrim(str_replace('\\', '/', $this->getProjectRootPath()), '/') . '/';
		$rootReal = realpath($root);
		if ($rootReal === false) {
			return null;
		}
		$normalizedRoot = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';

		$targetPath = null;
		$discoverer = $this->createDiscoverer();

		if ($cleanCategory === 'migration' && $discoverer !== null) {
			$files = $discoverer->discoverFiles('migrations', '*.php');
			$key = (string) preg_replace('/\.php$/i', '', $cleanId);
			$targetPath = $files[$key] ?? $files[$cleanId] ?? null;
		} elseif ($cleanCategory === 'queue' && $discoverer !== null) {
			$files = $discoverer->discoverFiles('queue', '*Queue.php');
			$targetPath = $files[$cleanId] ?? null;
		} elseif ($cleanCategory === 'seeder' && $discoverer !== null) {
			$files = $discoverer->discoverFiles('seeders', '*Seeder.php');
			$targetPath = $files[$cleanId] ?? null;
		} elseif ($cleanCategory === 'task' && $discoverer !== null) {
			$files = $discoverer->discoverFiles('schedule', '*Task.php');
			$targetPath = $files[$cleanId] ?? null;
		} elseif ($cleanCategory === 'test') {
			foreach ($this->getDiscoveredTestFiles() as $testFile) {
				$baseName = basename($testFile['path']);
				if ($testFile['id'] === $cleanId || $testFile['name'] === $cleanId || $baseName === $cleanId) {
					$targetPath = $testFile['path'];
					break;
				}
			}
		} elseif ($cleanCategory === 'backup') {
			$targetPath = $this->resolveBackupFilePath($cleanId);
		} elseif ($cleanCategory === 'skill') {
			if ($cleanId !== '.' && $cleanId !== '..' && preg_match('/^[A-Za-z0-9._-]+$/', $cleanId)) {
				$skillDir = $root . '.agents/skills/' . $cleanId;
				$candidate = $skillDir . '/SKILL.md';
				if (
					!is_link($root . '.agents')
					&& !is_link($root . '.agents/skills')
					&& !is_link($skillDir)
					&& !is_link($candidate)
					&& is_file($candidate)
				) {
					$targetPath = $candidate;
				}
			}
		} elseif ($cleanCategory === 'boost-doc') {
			$allowlistedDocs = [
				'agents-md' => $root . 'AGENTS.md',
				'claude-md' => $root . 'CLAUDE.md',
				'boost-json' => $root . '.agents/boost.json',
				'map-json' => $root . '.agents/map.json',
			];
			if (
				isset($allowlistedDocs[$cleanId])
				&& !is_link(dirname($allowlistedDocs[$cleanId]))
				&& !is_link($allowlistedDocs[$cleanId])
				&& is_file($allowlistedDocs[$cleanId])
			) {
				$targetPath = $allowlistedDocs[$cleanId];
			}
		}

		if (!is_string($targetPath) || $targetPath === '' || is_link(dirname($targetPath)) || is_link($targetPath)) {
			return null;
		}

		$realTarget = realpath($targetPath);
		if ($realTarget === false || !is_file($realTarget)) {
			return null;
		}

		$normalizedTarget = str_replace('\\', '/', $realTarget);
		if (!str_starts_with($normalizedTarget, $normalizedRoot)) {
			return null;
		}

		$rawContent = @file_get_contents($realTarget, false, null, 0, 65536);
		if (!is_string($rawContent)) {
			return null;
		}

		$sizeBytes = (int) (@filesize($realTarget) ?: strlen($rawContent));
		$lines = preg_split("/\r\n|\n|\r/", $rawContent) ?: [];
		$totalLines = count($lines);
		if ($totalLines > 250 || $sizeBytes > 65536) {
			$lines = array_slice($lines, 0, 250);
			$lines[] = sprintf('// ... (preview capped at %d lines / 64 KB; total file size: %s)', count($lines), $this->formatBytes($sizeBytes));
		}

		$relativePath = ltrim(substr($normalizedTarget, strlen($normalizedRoot)), '/');

		return [
			'category' => $cleanCategory,
			'identifier' => $cleanId,
			'relativePath' => $relativePath,
			'lineCount' => $totalLines,
			'sizeFormatted' => $this->formatBytes($sizeBytes),
			'content' => implode("\n", $lines),
		];
	}
}
