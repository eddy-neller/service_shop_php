<?php

declare(strict_types=1);

namespace App\Presentation\Shared\Adapter;

use App\Application\Shared\Port\FileInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class SymfonyFileAdapter implements FileInterface
{
    public function __construct(
        private File $file,
    ) {
    }

    public function getPathname(): string
    {
        return $this->file->getPathname();
    }

    public function getMimeType(): string
    {
        $mimeType = $this->file->getMimeType();

        return $mimeType ?? '';
    }

    public function getSize(): int
    {
        $size = $this->file->getSize();

        return is_int($size) ? $size : 0;
    }

    public function getClientOriginalName(): string
    {
        if ($this->file instanceof UploadedFile) {
            return $this->file->getClientOriginalName();
        }

        return $this->file->getFilename();
    }

    public function getExtension(): string
    {
        if ($this->file instanceof UploadedFile) {
            return $this->file->getClientOriginalExtension();
        }

        return $this->file->getExtension();
    }

    public function isValid(): bool
    {
        if ($this->file instanceof UploadedFile) {
            return $this->file->isValid();
        }

        return $this->file->isFile();
    }
}
