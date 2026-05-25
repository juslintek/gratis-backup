<?php declare(strict_types=1);
namespace Gratis\Backup\Source;

use Gratis\Backup\Contracts\BackupSourceInterface;

final class DatabaseSource implements BackupSourceInterface
{
    private string $tempFile;

    public function name(): string { return 'database'; }

    public function files(): \Generator
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'gratis-db-');
        $cmd = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s 2>/dev/null',
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_NAME),
            escapeshellarg($this->tempFile)
        );
        exec($cmd, $output, $code);
        if ($code === 0 && filesize($this->tempFile) > 0) {
            yield 'database.sql';
        }
    }

    public function readChunked(string $path, int $chunkSize = 65536): \Generator
    {
        if (!file_exists($this->tempFile)) return;
        $fh = fopen($this->tempFile, 'rb');
        if (!$fh) return;
        while (!feof($fh)) {
            $chunk = fread($fh, $chunkSize);
            if ($chunk !== '' && $chunk !== false) yield $chunk;
        }
        fclose($fh);
        @unlink($this->tempFile);
    }
}
