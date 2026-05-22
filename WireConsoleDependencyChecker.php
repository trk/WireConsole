<?php

declare(strict_types=1);

namespace ProcessWire;

use Composer\InstalledVersions;

/**
 * Dependency checker for WireConsole module family.
 *
 * This file must not depend on any external packages.
 */
final class WireConsoleDependencyChecker
{
	/**
	 * Best-effort attempt to resolve the project root that holds composer.json/vendor.
	 *
	 * Why this exists:
	 * - Some installations place /site under a /public or /web root.
	 * - composer.json/vendor may live one or more levels above the ProcessWire web root.
	 */
	public static function getProjectRootPath(Config $config): string
	{
		$sitePath = rtrim((string) $config->paths->site, '/');
		$start = dirname($sitePath);

		$dir = $start;
		for ($i = 0; $i < 8; $i++) {
			if (self::isComposerRootCandidate($dir)) {
				return $dir;
			}
			$parent = dirname($dir);
			if ($parent === $dir) {
				break;
			}
			$dir = $parent;
		}

		// Extra safety: if site path resolution fails, fall back to current working directory.
		return $start !== '' ? $start : (getcwd() ?: '');
	}

	private static function isComposerRootCandidate(string $dir): bool
	{
		$composerJson = rtrim($dir, '/') . '/composer.json';
		if (!is_file($composerJson)) {
			return false;
		}

		// Require at least one of the common vendor markers.
		$autoload = rtrim($dir, '/') . '/vendor/autoload.php';
		$installedJson = rtrim($dir, '/') . '/vendor/composer/installed.json';
		$installedPhp = rtrim($dir, '/') . '/vendor/composer/installed.php';

		return is_file($autoload) || is_file($installedJson) || is_file($installedPhp) || is_dir(rtrim($dir, '/') . '/vendor');
	}

	public static function getComposerJsonPath(Config $config): ?string
	{
		$rootPath = self::getProjectRootPath($config);
		$composerJson = $rootPath . '/composer.json';
		return is_file($composerJson) ? $composerJson : null;
	}

	public static function getVendorPath(Config $config): ?string
	{
		$rootPath = self::getProjectRootPath($config);
		$vendor = $rootPath . '/vendor';
		return is_dir($vendor) ? $vendor : null;
	}

	/**
	 * Checks if the package is present in root composer.json "require" or "require-dev".
	 */
	public static function isPackageInComposerJson(Config $config, string $packageName): bool
	{
		$composerJsonPath = self::getComposerJsonPath($config);
		if (!$composerJsonPath) {
			return false;
		}

		$json = @file_get_contents($composerJsonPath);
		if ($json === false || $json === '') {
			return false;
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return false;
		}

		$require = $data['require'] ?? [];
		$requireDev = $data['require-dev'] ?? [];

		return (is_array($require) && array_key_exists($packageName, $require))
			|| (is_array($requireDev) && array_key_exists($packageName, $requireDev));
	}

	/**
	 * Checks if the package is installed in /vendor (best effort).
	 *
	 * Note: returns false when not installed OR when it cannot be determined.
	 */
	public static function isPackageInstalled(Config $config, string $packageName): bool
	{
		// Fast-path when InstalledVersions is available (means Composer autoloader is active).
		if (class_exists(InstalledVersions::class)) {
			try {
				return InstalledVersions::isInstalled($packageName);
			} catch (\Throwable) {
				// ignore and fallback
			}
		}

		$vendor = self::getVendorPath($config);
		if (!$vendor) {
			return false;
		}

		// Composer 2 usually provides installed.json
		$installedJson = $vendor . '/composer/installed.json';
		if (is_file($installedJson)) {
			$json = @file_get_contents($installedJson);
			if ($json !== false && $json !== '') {
				$data = json_decode($json, true);
				if (is_array($data)) {
					$packages = $data['packages'] ?? $data; // depending on Composer version
					if (is_array($packages)) {
						foreach ($packages as $pkg) {
							if (is_array($pkg) && ($pkg['name'] ?? null) === $packageName) {
								return true;
							}
						}
					}
				}
			}
		}

		// Composer 2 also provides installed.php
		$installedPhp = $vendor . '/composer/installed.php';
		if (is_file($installedPhp)) {
			/** @var mixed $data */
			$data = @include $installedPhp;
			if (is_array($data)) {
				$versions = $data['versions'] ?? null;
				if (is_array($versions) && array_key_exists($packageName, $versions)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns a compact status map for configured packages.
	 *
	 * @return array<string, array{inComposer: bool, installed: bool}>
	 */
	public static function getPackagesStatus(Config $config, array $packages): array
	{
		$status = [];
		foreach ($packages as $packageName) {
			$status[$packageName] = [
				'inComposer' => self::isPackageInComposerJson($config, (string) $packageName),
				'installed' => self::isPackageInstalled($config, (string) $packageName),
			];
		}
		return $status;
	}
}
