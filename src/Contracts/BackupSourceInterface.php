<?php declare(strict_types=1);
namespace Gratis\Backup\Contracts;

interface BackupSourceInterface
{
    public function name(): string;

    /** @return \Generator<string> Yields file paths relative to source root */
    public function files(): \Generator;

    /** @return \Generator<string> Yields chunks of file content */
    public function readChunked(string $path, int $chunkSize = 65536): \Generator;
}
