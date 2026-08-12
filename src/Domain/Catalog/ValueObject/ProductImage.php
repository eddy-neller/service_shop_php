<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

final readonly class ProductImage
{
    private function __construct(
        private ?string $fileName = null,
    ) {
    }

    public static function create(?string $fileName = null): self
    {
        return new self(
            fileName: $fileName,
        );
    }

    public function fileName(): ?string
    {
        return $this->fileName;
    }

    public function withFile(?string $fileName): self
    {
        return new self(
            fileName: $fileName,
        );
    }
}
