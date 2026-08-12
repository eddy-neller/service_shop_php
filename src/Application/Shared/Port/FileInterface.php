<?php

declare(strict_types=1);

namespace App\Application\Shared\Port;

/**
 * Représente un fichier uploadé.
 */
interface FileInterface
{
    public function getPathname(): string;

    public function getMimeType(): string;

    public function getSize(): int;

    public function getClientOriginalName(): string;

    public function getExtension(): string;

    public function isValid(): bool;
}
