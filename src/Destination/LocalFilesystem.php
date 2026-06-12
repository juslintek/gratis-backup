<?php declare(strict_types=1);
namespace Gratis\Backup\Destination;

use Gratis\Backup\Contracts\BackupDestinationInterface;

final class LocalFilesystem implements BackupDestinationInterface {

    private string $basePath;

    public function __construct(?string $basePath = null) {
        $this->basePath = rtrim($basePath ?? WP_CONTENT_DIR . '/gratis-backups', '/');
    }

    public function name(): string {
        return 'local';
    }

    public function write(string $path, \Generator $chunks): bool {
        $full = $this->basePath . '/' . $path;
        $dir = dirname($full);
        // Stream-first local backup writer; WP_Filesystem cannot stream chunked output.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen($full, 'wb');
        if (!$fh) {
            return false;
        }

        foreach ($chunks as $chunk) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($fh, $chunk);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);
        return true;
    }

    public function exists(string $path): bool {
        return file_exists($this->basePath . '/' . $path);
    }

    public function delete(string $path): bool {
        $full = $this->basePath . '/' . $path;
        if (!file_exists($full)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return unlink($full);
    }

    public function isAvailable(): bool {
        if (!is_dir($this->basePath)) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            @mkdir($this->basePath, 0755, true);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        return is_writable($this->basePath);
    }
}
