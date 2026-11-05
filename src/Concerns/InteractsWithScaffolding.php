<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

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
	 * Load a stub file from vendor/trk/processwire-console/resources/stubs/ and replace placeholders.
	 *
	 * @param array<string, string> $replacements
	 */
	protected function renderConsoleStub(string $stubFile, array $replacements = []): ?string
	{
		$stubPath = $this->getProjectRootPath() . 'vendor/trk/processwire-console/resources/stubs/' . ltrim($stubFile, '/');
		if (!is_file($stubPath)) {
			return null;
		}

		$content = @file_get_contents($stubPath);
		if (!is_string($content)) {
			return null;
		}

		if ($replacements !== []) {
			$content = str_replace(array_keys($replacements), array_values($replacements), $content);
		}

		return $content;
	}

	/**
	 * Scaffold a new timestamped migration file in site/migrations/ (`make:migration`).
	 *
	 * @return array{ok: bool, file: ?string, path: ?string, message: string}
	 */
	protected function scaffoldMigrationFile(string $rawName): array
	{
		$name = trim($rawName);
		if (!$this->isValidIdentifier($name)) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Invalid migration name. Use letters, numbers, and underscores starting with a letter (e.g. create_blog_fields).'),
			];
		}

		$migrator = $this->createMigrator();
		if ($migrator === null) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Migration engine is unavailable.'),
			];
		}

		$migrator->ensureMigrationsDirectory();
		$timestamp = date('YmdHis');
		$safeName = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
		$fileName = "{$timestamp}_{$safeName}.php";
		$filePath = rtrim($migrator->getMigrationsPath(), '/') . '/' . $fileName;

		if (file_exists($filePath)) {
			return [
				'ok' => false,
				'file' => $fileName,
				'path' => $filePath,
				'message' => sprintf($this->_('Migration file already exists: %s'), $fileName),
			];
		}

		$content = $this->renderConsoleStub('migration.stub');
		if ($content === null) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Migration stub file not found in trk/processwire-console.'),
			];
		}

		if (@file_put_contents($filePath, $content) === false) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Failed to write migration file to site/migrations/.'),
			];
		}

		return [
			'ok' => true,
			'file' => $fileName,
			'path' => $filePath,
			'message' => sprintf($this->_('Created migration: site/migrations/%s'), $fileName),
		];
	}

	/**
	 * Scaffold a Migration, Queue, Seeder, Task, Pest Test, or ProcessWire Module file (`make:migration`, `make:queue`, `make:seeder`, `make:task`, `make:test`, `make:module`).
	 *
	 * @param string $kind One of: 'migration', 'queue', 'seeder', 'task', 'test', 'module'
	 * @return array{ok: bool, file: ?string, path: ?string, message: string}
	 */
	public function scaffoldComponentFile(string $kind, string $rawName): array
	{
		if ($kind === 'migration') {
			return $this->scaffoldMigrationFile($rawName);
		}

		$name = trim($rawName);
		if (!$this->isValidIdentifier($name)) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Invalid class name. Use PascalCase letters and numbers starting with a letter (e.g. SendWelcomeEmail).'),
			];
		}

		$sitePath = rtrim((string) ($this->wire()->config?->paths?->site ?? ($this->getProjectRootPath() . 'site/')), '/') . '/';

		if ($kind === 'module') {
			$modulesDir = rtrim((string) ($this->wire()->config?->paths?->siteModules ?? ($sitePath . 'modules/')), '/') . '/';
			$moduleDir = $modulesDir . $name . '/';
			$fileName = $name . '.module.php';
			$filePath = $moduleDir . $fileName;

			if (is_dir($moduleDir) || file_exists($filePath)) {
				return [
					'ok' => false,
					'file' => $fileName,
					'path' => $filePath,
					'message' => sprintf($this->_('Module directory already exists: site/modules/%s/'), $name),
				];
			}

			$baseName = (string) preg_replace('/^Fieldtype/', '', $name);
			if ($baseName === '') {
				$baseName = $name;
			}

			$content = $this->renderConsoleStub('module.stub', [
				'{{className}}' => $name,
				'{{baseName}}' => $baseName,
				'{{title}}' => addslashes($name),
				'{{summary}}' => addslashes("A ProcessWire module scaffolded via ProcessWireConsole."),
				'{{author}}' => 'ProcessWire Console',
				'{{name}}' => addslashes(strtolower($name)),
			]);

			if ($content === null) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => $this->_('Stub file module.stub was not found.'),
				];
			}

			if (!is_dir($moduleDir)) {
				@mkdir($moduleDir, 0755, true);
			}

			if (@file_put_contents($filePath, $content) === false) {
				return [
					'ok' => false,
					'file' => null,
					'path' => null,
					'message' => sprintf($this->_('Failed to write module file to site/modules/%s/.'), $name),
				];
			}

			try {
				$this->wire()->modules?->refresh();
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

		$spec = match ($kind) {
			'queue' => [
				'suffix' => 'Queue',
				'dir' => $sitePath . 'queue/',
				'relDir' => 'site/queue/',
				'namespace' => 'Site\\Queue',
				'stub' => 'queue.stub',
			],
			'seeder' => [
				'suffix' => 'Seeder',
				'dir' => $sitePath . 'seeders/',
				'relDir' => 'site/seeders/',
				'namespace' => 'Site\\Seeders',
				'stub' => 'seeder.stub',
			],
			'task' => [
				'suffix' => 'Task',
				'dir' => $sitePath . 'schedule/',
				'relDir' => 'site/schedule/',
				'namespace' => 'Site\\Schedule',
				'stub' => 'task.stub',
			],
			'test' => [
				'suffix' => 'Test',
				'dir' => $this->getProjectRootPath() . 'tests/Feature/',
				'relDir' => 'tests/Feature/',
				'namespace' => '',
				'stub' => 'pest.stub',
			],
			default => null,
		};

		if ($spec === null) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => $this->_('Unsupported scaffold type.'),
			];
		}

		if (!str_ends_with($name, $spec['suffix'])) {
			$name .= $spec['suffix'];
		}

		if (!is_dir($spec['dir'])) {
			@mkdir($spec['dir'], 0755, true);
		}

		$fileName = $name . '.php';
		$filePath = $spec['dir'] . $fileName;
		if (file_exists($filePath)) {
			return [
				'ok' => false,
				'file' => $fileName,
				'path' => $filePath,
				'message' => sprintf($this->_('File already exists: %s%s'), $spec['relDir'], $fileName),
			];
		}

		$content = $this->renderConsoleStub($spec['stub'], [
			'{{namespace}}' => $spec['namespace'],
			'{{class}}' => $name,
		]);
		if ($content === null) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => sprintf($this->_('Stub file %s was not found.'), $spec['stub']),
			];
		}

		if (@file_put_contents($filePath, $content) === false) {
			return [
				'ok' => false,
				'file' => null,
				'path' => null,
				'message' => sprintf($this->_('Failed to write file to %s.'), $spec['relDir']),
			];
		}

		if ($kind === 'test') {
			$this->discoveredTestFilesCache = null;
		} elseif ($kind === 'queue') {
			$this->discoveredQueuesCache = null;
		}

		return [
			'ok' => true,
			'file' => $fileName,
			'path' => $filePath,
			'message' => sprintf($this->_('Created %s%s'), $spec['relDir'], $fileName),
		];
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
				if ($testFile['id'] === $cleanId || $testFile['name'] === $cleanId || ($testFile['name'] . '.php') === $cleanId) {
					$targetPath = $testFile['path'];
					break;
				}
			}
		} elseif ($cleanCategory === 'backup') {
			$targetPath = $this->resolveBackupFilePath($cleanId);
		} elseif ($cleanCategory === 'skill') {
			if (preg_match('/^[A-Za-z0-9._-]+$/', $cleanId)) {
				$candidate = $root . '.agents/skills/' . $cleanId . '/SKILL.md';
				if (is_file($candidate)) {
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
			if (isset($allowlistedDocs[$cleanId]) && is_file($allowlistedDocs[$cleanId])) {
				$targetPath = $allowlistedDocs[$cleanId];
			}
		}

		if (!is_string($targetPath) || $targetPath === '') {
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
