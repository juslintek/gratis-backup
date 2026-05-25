<?php declare(strict_types=1);
namespace Gratis\Backup\CLI;

use Gratis\Backup\BackupEngine;
use Gratis\Backup\Destination\LocalFilesystem;
use Gratis\Backup\Source\DatabaseSource;
use Gratis\Backup\Source\DirectorySource;

/** WP-CLI commands: wp gratis-backup <subcommand> */
final class BackupCommand
{
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
    public function run($args, $assoc_args): void
    {
        $type = $assoc_args['type'] ?? 'full';
        $dest = new LocalFilesystem();

        if (!$dest->isAvailable()) {
            \WP_CLI::error("Backup destination not writable.");
        }

        $engine = new BackupEngine($dest);
        $sources = $this->sourcesForType($type);

        foreach ($sources as $source) {
            \WP_CLI::log("Backing up: {$source->name()}...");
            $result = $engine->run($source, function ($count, $file) {
                if ($count % 100 === 0) \WP_CLI::log("  {$count} files processed...");
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
    public function list($args, $assoc_args): void
    {
        $engine = new BackupEngine(new LocalFilesystem());
        $backups = $engine->listBackups();

        if (empty($backups)) {
            \WP_CLI::log("No backups found.");
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
    public function status($args, $assoc_args): void
    {
        $dest = new LocalFilesystem();
        \WP_CLI::log("=== Gratis Backup Status ===");
        \WP_CLI::log("Destination: local filesystem");
        \WP_CLI::log("Writable: " . ($dest->isAvailable() ? 'yes' : 'NO'));
        \WP_CLI::log("Path: " . WP_CONTENT_DIR . '/gratis-backups');

        $engine = new BackupEngine($dest);
        $backups = $engine->listBackups();
        \WP_CLI::log("Backups: " . count($backups));

        if (!empty($backups)) {
            \WP_CLI::log("Latest: " . ($backups[0]['id'] ?? 'unknown'));
        }
    }

    private function sourcesForType(string $type): array
    {
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
