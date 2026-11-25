<?php declare(strict_types=1);
namespace Gratis\Backup\Source;

defined('ABSPATH') || exit;
use Gratis\Backup\Contracts\BackupSourceInterface;
use Gratis\Backup\DatabaseBackend;

// 'sqlite'/'database.sqlite' literals and SQLite helper names trip the
// PHPCompatibility removed-extension sniff (PHP 5.4 sqlite2) — a false positive
// for the modern pdo_sqlite-backed code paths used here.
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved

final class DatabaseSource implements BackupSourceInterface {

    private string $tempFile = '';

    public function name(): string {
        return 'database';
    }

    public function files(): \Generator {
        // SQLite: back up by making a consistent copy of the single database
        // file (mysqldump does not exist on a SQLite host).
        if (DatabaseBackend::isSqlite()) {
            $source = DatabaseBackend::sqliteFile();
            if (!is_file($source)) {
                return;
            }
            $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'gratis-db-');
            // VACUUM INTO requires a non-existent target.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($this->tempFile);
            if (DatabaseBackend::copySqliteConsistently($source, $this->tempFile)
                && is_file($this->tempFile)
                && filesize($this->tempFile) > 0) {
                yield 'database.sqlite';
            }
            return;
        }

        // MySQL/MariaDB: stream a logical dump via mysqldump.
        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'gratis-db-');
        $cmd = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s 2>/dev/null',
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_NAME),
            escapeshellarg($this->tempFile)
        );
        // mysqldump is the fast path for a streamed SQL backup; all args are shell-escaped.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec($cmd, $output, $code);
        if ($code === 0 && filesize($this->tempFile) > 0) {
            yield 'database.sql';
        }
    }

    public function readChunked(string $path, int $chunkSize = 65536): \Generator {
        if ('' === $this->tempFile || !file_exists($this->tempFile)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen($this->tempFile, 'rb');
        if (!$fh) {
            return;
        }
        while (!feof($fh)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($fh, $chunkSize);
            if ($chunk !== '' && $chunk !== false) {
                yield $chunk;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink($this->tempFile);
    }
}
