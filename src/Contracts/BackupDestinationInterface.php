<?php declare(strict_types=1);
namespace Gratis\Backup\Contracts;

interface BackupDestinationInterface
{
    public function name(): string;
    public function write(string $path, \Generator $chunks): bool;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    public function isAvailable(): bool;
}
