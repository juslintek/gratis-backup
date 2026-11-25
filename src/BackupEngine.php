<?php declare(strict_types=1);
namespace Gratis\Backup;

defined('ABSPATH') || exit;
use Gratis\Backup\Contracts\BackupDestinationInterface;
use Gratis\Backup\Contracts\BackupSourceInterface;

final class BackupEngine {

    private BackupDestinationInterface $destination;
    private string $manifestDir;

    public function __construct(BackupDestinationInterface $destination, ?string $manifestDir = null) {
        $this->destination = $destination;
        $this->manifestDir = $manifestDir ?? WP_CONTENT_DIR . '/gratis-backup-manifests';
    }

    /**
     * Run a backup from source to destination.
     * @return array{id: string, files: int, bytes: int, hash: string, duration: float}
     */
    public function run(BackupSourceInterface $source, ?callable $onProgress = null): array {
        $id = gmdate('Ymd-His') . '-' . $source->name();
        $prefix = "backups/{$id}";
        $manifest = [];
        $totalBytes = 0;
        $fileCount = 0;
        $start = microtime(true);

        foreach ($source->files() as $relativePath) {
            $destPath = "{$prefix}/{$relativePath}";
            $hash = hash_init('xxh3');
            $fileBytes = 0;

            $chunks = (function () use ($source, $relativePath, &$hash, &$fileBytes) {
                foreach ($source->readChunked($relativePath) as $chunk) {
                    hash_update($hash, $chunk);
                    $fileBytes += strlen($chunk);
                    yield $chunk;
                }
            })();

            $ok = $this->destination->write($destPath, $chunks);
            if (!$ok) continue;

            $manifest[] = [
                'path' => $relativePath,
                'size' => $fileBytes,
                'hash' => hash_final($hash),
            ];
            $totalBytes += $fileBytes;
            ++$fileCount;

            if ($onProgress) $onProgress($fileCount, $relativePath);
        }

        $duration = microtime(true) - $start;
        $overallHash = hash('xxh3', (string) wp_json_encode($manifest));

        $result = [
            'id'       => $id,
            'source'   => $source->name(),
            'files'    => $fileCount,
            'bytes'    => $totalBytes,
            'hash'     => $overallHash,
            'duration' => round($duration, 2),
            'created'  => gmdate('c'),
        ];

        $this->saveManifest($id, $result, $manifest);
        return $result;
    }

    public function listBackups(): array {
        if (!is_dir($this->manifestDir)) {
            return [];
        }
        $manifests = glob($this->manifestDir . '/*.json');
        $list = [];
        foreach ($manifests as $f) {
            // Reading a local manifest JSON file written by this plugin, not a remote URL.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $raw = file_get_contents($f);
            $data = $raw ? json_decode($raw, true) : null;
            if ($data) {
                $list[] = $data['meta'] ?? $data;
            }
        }
        usort($list, fn($a, $b) => ($b['created'] ?? '') <=> ($a['created'] ?? ''));
        return $list;
    }

    private function saveManifest(string $id, array $meta, array $files): void {
        if (!is_dir($this->manifestDir)) {
            // Local backup-manifest directory; WP_Filesystem is not initialised this early.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            @mkdir($this->manifestDir, 0755, true);
        }
        $data = wp_json_encode(['meta' => $meta, 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Writing a local manifest file; WP_Filesystem cannot stream large backups.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($this->manifestDir . "/{$id}.json", $data, LOCK_EX);
    }
}
