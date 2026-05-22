# WireConsole (ProcessWire Admin UI)

This module provides a **ProcessWire Admin UI** (“bridge UI”) for the **ProcessWire Console** ecosystem.
It does **not** re-implement Console features itself; instead it integrates with:

- `trk/processwire-console` (required for most features)
- `trk/processwire-boost` (optional; status/info only)

## Compatibility

### ProcessWire / PHP

- ProcessWire `>= 3.0.210`
- PHP `>= 8.2`

### Console package PHP API expectations

This admin UI depends on the Console package exposing a small set of PHP API classes.
If your installed Console version does not provide these classes, the UI will display a warning with the missing FQCNs.

- Migrations:
  - `Totoglu\Console\Migration\Migrator`
  - `Totoglu\Console\Migration\MigrationRepository`
- Queues:
  - `Totoglu\Console\Queue\QueueManager`
- Seeding:
  - `Totoglu\Console\Database\SeederManager`
  - `Totoglu\Console\Database\Seeder`
- Scheduling:
  - `Totoglu\Console\Scheduling\ScheduleManager`
  - `Totoglu\Console\Scheduling\Task`
  - `Totoglu\Console\Scheduling\Event`

## Installation

### Via Composer (recommended)

From your project root:

```bash
composer require trk/processwire-wireconsole
```

Then in ProcessWire Admin:
- Admin → Modules → Refresh
- Install **WireConsole** (and **ProcessWireConsole** Process module)

### Dependencies

Install Console packages (from your project root):

```bash
composer require trk/processwire-console
composer require trk/processwire-boost # optional
```

If you install `trk/processwire-wireconsole` via Composer, you may still want to install `trk/processwire-console` for full functionality.

## Permissions

The admin page requires the base permission:

- `wire-console`

Feature tabs are permission-based (superusers bypass all checks):

- `wire-console-migrations`
- `wire-console-queues`
- `wire-console-seeding`
- `wire-console-scheduling`
- `wire-console-backup`
- `wire-console-testing`

If a user lacks a permission, the tab is hidden and direct access will display a “permission required” card.

## Scheduling (cron)

Scheduling depends on a server cron entry that runs every minute:

```bash
* * * * * cd /path/to/project && php vendor/bin/wire schedule:run >> /dev/null 2>&1
```

The Admin UI can also trigger “run due tasks” manually (superuser-only), but cron is recommended for production.

## Queues (workers)

Queue processing is operationally a CLI/worker concern. For production, run a worker process:

```bash
php vendor/bin/wire queue:work
```

The Admin UI focuses on table installation, inspecting failed jobs, and retry/clear operations.

## Database backups & restore

The module uses **ProcessWire core** backup tooling (`$database->backups()`).

Notes:
- In many production installs, `site/assets/backups/` is protected via `.htaccess` (downloads may be blocked).
- Ensure `site/assets/backups/database/` is **writable** by the PHP user.
- Restore is **destructive**. The UI requires typed confirmation (`RESTORE`) and can optionally create a pre-backup.

## Testing

The UI can run `php vendor/bin/wire test` (superuser-only) if:
- `proc_open` is enabled
- `vendor/bin/wire` exists

Security notes:
- Test arguments are allowlisted.
- In **non-debug** mode (`$config->debug = false`), test output is hidden in the UI.

## Audit log

All critical actions write structured audit entries to:

`site/assets/logs/wire-console.txt`

## Troubleshooting

### “Console PHP API not available”

Common causes:
- `vendor/autoload.php` not found (wrong project root detection)
- Console package not installed

The module resolves the Composer root by searching upwards from `/site` for `composer.json` and vendor markers.

### Backup download links don’t work

If `site/assets/backups/.htaccess` denies access, the UI will avoid generating public download links.
Use filesystem access to retrieve backup files.

### Actions are blocked / not visible

- Confirm the user has the required feature permission.
- Superusers bypass feature permissions.

## Development & tests

Module tests live under:

`site/modules/WireConsole/tests`

Run:

```bash
php vendor/bin/wire test --filter=WireConsole
```
