<?php declare(strict_types=1);
namespace Gratis\Backup\Source;

use Gratis\Backup\Contracts\BackupSourceInterface;

final class DirectorySource implements BackupSourceInterface {

    private string $dir;
    private string $label;
    private array $exclude;

    public function __construct(string $dir, string $label = 'directory', array $exclude = []) {
        $this->dir = rtrim($dir, '/');
        $this->label = $label;
        $this->exclude = $exclude;
    }

    public function name(): string {
        return $this->label;
    }

    public function files(): \Generator {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($this->dir) + 1);
            foreach ($this->exclude as $pattern) {
                if (fnmatch($pattern, $rel)) {
                    continue 2;
                }
            }
            yield $rel;
        }
    }

    public function readChunked(string $path, int $chunkSize = 65536): \Generator {
        $full = $this->dir . '/' . $path;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen($full, 'rb');
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
    }
}
