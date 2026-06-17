<?php declare(strict_types=1);
namespace Gratis\Backup\CLI;

use Gratis\Backup\BackupEngine;
use Gratis\Backup\Destination\LocalFilesystem;
use Gratis\Backup\RestoreEngine;
use Gratis\Backup\Source\DatabaseSource;
use Gratis\Backup\Source\DirectorySource;

/** WP-CLI commands: wp gratis-backup <subcommand> */
final class BackupCommand {

    /**
     * Run a backup.
     *
     * ## OPTIONS
     * [--type=<type>]
     * : Backup type: full, database, uploads, themes, plugins
     * ---
     * default: full
     * ---
     *
     * ## EXAMPLES
     *     wp gratis-backup run
     *     wp gratis-backup run --type=database
     *
     * @when after_wp_load
     */
    public function run($args, $assoc_args): void {
        $type = $assoc_args['type'] ?? 'full';
        $dest = new LocalFilesystem();

        if (!$dest->isAvailable()) {
            \WP_CLI::error('Backup destination not writable.');
        }

        $engine = new BackupEngine($dest);
        $sources = $this->sourcesForType($type);

        foreach ($sources as $source) {
            \WP_CLI::log("Backing up: {$source->name()}...");
            $result = $engine->run($source, function ($count) {
                if ($count % 100 === 0) {
                    \WP_CLI::log("  {$count} files processed...");
                }
            });
            $mb = round($result['bytes'] / 1048576, 2);
            \WP_CLI::success("{$source->name()}: {$result['files']} files, {$mb} MB in {$result['duration']}s");
        }
    }

    /**
     * List existing backups.
     *
     * ## EXAMPLES
     *     wp gratis-backup list
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args): void {
        unset($args, $assoc_args);
        $engine = new BackupEngine(new LocalFilesystem());
        $backups = $engine->listBackups();

        if (empty($backups)) {
            \WP_CLI::log('No backups found.');
            return;
        }

        $rows = array_map(fn($b) => [
            'ID'      => $b['id'] ?? '?',
            'Source'  => $b['source'] ?? '?',
            'Files'   => $b['files'] ?? 0,
            'Size'    => round(($b['bytes'] ?? 0) / 1048576, 1) . ' MB',
            'Created' => $b['created'] ?? '?',
        ], $backups);

        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Source', 'Files', 'Size', 'Created']);
    }

    /**
     * Show backup status and destination info.
     *
     * ## EXAMPLES
     *     wp gratis-backup status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args): void {
        unset($args, $assoc_args);
        $dest = new LocalFilesystem();
        \WP_CLI::log('=== Gratis Backup Status ===');
        \WP_CLI::log('Destination: local filesystem');
        \WP_CLI::log('Writable: ' . ($dest->isAvailable() ? 'yes' : 'NO'));
        \WP_CLI::log('Path: ' . WP_CONTENT_DIR . '/gratis-backups');

        $engine = new BackupEngine($dest);
        $backups = $engine->listBackups();
        \WP_CLI::log('Backups: ' . count($backups));

        if (!empty($backups)) {
            \WP_CLI::log('Latest: ' . ($backups[0]['id'] ?? 'unknown'));
        }
    }

    /**
     * Restore a backup this plugin produced.
     *
     * Validates the archive (per-file + overall hash), takes a pre-restore
     * safety snapshot, then restores wp-content files or imports the database.
     * SQLite and MySQL backups are both supported; cross-engine restores are
     * refused.
     *
     * ## OPTIONS
     *
     * <file>
     * : Backup id (e.g. 20260617-094447-database) or path to its manifest .json.
     *
     * [--dry-run]
     * : Validate the archive and report what would change without writing.
     *
     * [--yes]
     * : Skip the destructive-restore confirmation prompt.
     *
     * ## EXAMPLES
     *     wp gratis-backup restore 20260617-094447-database --dry-run
     *     wp gratis-backup restore 20260617-094447-uploads
     *
     * @when after_wp_load
     */
    public function restore($args, $assoc_args): void {
        $target = (string) ($args[0] ?? '');
        if ('' === $target) {
            \WP_CLI::error('Specify a backup id or manifest path to restore.');
        }

        $engine = new RestoreEngine();
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

        $plan = $engine->plan($target);
        $this->printPlan($plan);

        if (!empty($plan['errors'])) {
            \WP_CLI::error('Backup is not restorable:' . "\n - " . implode("\n - ", $plan['errors']));
        }

        if ($dryRun) {
            \WP_CLI::success('Dry run complete — no changes were made.');
            return;
        }

        $what = 'database' === $plan['kind']
            ? sprintf('the %s database', (string) $plan['db_engine'])
            : sprintf("the '%s' file tree (%d file(s))", (string) $plan['source'], (int) $plan['summary']['files']);
        \WP_CLI::confirm(
            sprintf('This will overwrite %s. A pre-restore snapshot is taken first. Continue?', $what),
            $assoc_args
        );

        try {
            $result = $engine->restore($target);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        if (empty($result['restored'])) {
            \WP_CLI::error('Restore did not run:' . "\n - " . implode("\n - ", $result['errors'] ?? ['unknown error']));
        }

        \WP_CLI::log('Pre-restore snapshot: ' . ((string) ($result['snapshot_dir'] ?? 'n/a')));
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            \WP_CLI::warning((string) $warning);
        }

        if ('database' === $plan['kind']) {
            \WP_CLI::success(sprintf('Database restored (%s).', (string) $plan['db_engine']));
            return;
        }

        $applied = (array) ($result['applied'] ?? []);
        \WP_CLI::success(sprintf(
            'Files restored: %d created, %d overwritten, %d unchanged, %d failed.',
            (int) ($applied['create'] ?? 0),
            (int) ($applied['overwrite'] ?? 0),
            (int) ($applied['unchanged'] ?? 0),
            (int) ($applied['failed'] ?? 0)
        ));
        if (!empty($applied['failed'])) {
            \WP_CLI::warning($applied['failed'] . ' file(s) failed to restore; the pre-restore snapshot is preserved.');
        }
    }

    /** Print a human-readable summary of a restore plan. */
    private function printPlan(array $plan): void {
        \WP_CLI::log('=== Restore plan ===');
        \WP_CLI::log('Backup:  ' . (($plan['id'] ?? '') ?: '?') . '  (source: ' . (($plan['source'] ?? '') ?: '?') . ', created: ' . (($plan['created'] ?? '') ?: '?') . ')');
        \WP_CLI::log('Kind:    ' . ((string) ($plan['kind'] ?? '?')));

        if ('database' === ($plan['kind'] ?? '')) {
            \WP_CLI::log('Engine:  ' . ((string) ($plan['db_engine'] ?? '?')) . '  (file: ' . ((string) ($plan['db_file'] ?? '?')) . ')');
            \WP_CLI::log('Target:  ' . ((string) ($plan['db']['target'] ?? '?')));
        } elseif ('files' === ($plan['kind'] ?? '')) {
            $summary = $plan['summary'];
            \WP_CLI::log('Target:  ' . ((string) ($plan['target_root'] ?? '?')));
            \WP_CLI::log(sprintf(
                'Changes: %d create, %d overwrite, %d unchanged (%d total)',
                (int) ($summary['create'] ?? 0),
                (int) ($summary['overwrite'] ?? 0),
                (int) ($summary['unchanged'] ?? 0),
                (int) ($summary['files'] ?? 0)
            ));
        }
        foreach ((array) ($plan['warnings'] ?? []) as $warning) {
            \WP_CLI::warning((string) $warning);
        }
    }

    private function sourcesForType(string $type): array {
        return match ($type) {
            'database' => [new DatabaseSource()],
            'uploads'  => [new DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*', 'cache/*'])],
            'themes'   => [new DirectorySource(get_theme_root(), 'themes')],
            'plugins'  => [new DirectorySource(WP_PLUGIN_DIR, 'plugins', ['gratis-backup/*'])],
            default    => [
                new DatabaseSource(),
                new DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*', 'cache/*']),
                new DirectorySource(get_theme_root(), 'themes'),
                new DirectorySource(WP_PLUGIN_DIR, 'plugins', ['gratis-backup/*']),
            ],
        };
    }
}
