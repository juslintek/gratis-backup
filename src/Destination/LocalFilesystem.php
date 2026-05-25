<?php declare(strict_types=1);
namespace Gratis\Backup\Destination;

use Gratis\Backup\Contracts\BackupDestinationInterface;

final class LocalFilesystem implements BackupDestinationInterface
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? WP_CONTENT_DIR . '/gratis-backups', '/');
    }

    public function name(): string { return 'local'; }

    public function write(string $path, \Generator $chunks): bool
    {
        $full = $this->basePath . '/' . $path;
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

        $fh = fopen($full, 'wb');
        if (!$fh) return false;

        foreach ($chunks as $chunk) {
            fwrite($fh, $chunk);
        }
        fclose($fh);
        return true;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->basePath . '/' . $path);
    }

    public function delete(string $path): bool
    {
        $full = $this->basePath . '/' . $path;
        return file_exists($full) && unlink($full);
    }

    public function isAvailable(): bool
    {
        if (!is_dir($this->basePath)) @mkdir($this->basePath, 0755, true);
        return is_writable($this->basePath);
    }
}
