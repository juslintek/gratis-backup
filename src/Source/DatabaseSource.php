<?php declare(strict_types=1);
namespace Gratis\Backup\Source;

use Gratis\Backup\Contracts\BackupSourceInterface;

final class DatabaseSource implements BackupSourceInterface {

    private string $tempFile;

    public function name(): string {
        return 'database';
    }

    public function files(): \Generator {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'gratis-db-');
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
        if (!file_exists($this->tempFile)) {
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
