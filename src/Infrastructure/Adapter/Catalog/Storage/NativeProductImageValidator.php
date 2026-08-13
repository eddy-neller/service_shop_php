<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Catalog\Storage;

use App\Application\Catalog\Port\ProductImageValidatorInterface;
use App\Application\Shared\Port\FileInterface;
use App\Domain\Catalog\Exception\InvalidProductImageException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final readonly class NativeProductImageValidator implements ProductImageValidatorInterface
{
    /** @var array<string, string> */
    private const array MIME_TYPE_ALIASES = [
        'image/jpeg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/webp' => 'image/webp',
    ];

    public function __construct(
        private ParameterBagInterface $parameterBag,
    ) {
    }

    public function validate(FileInterface $file): void
    {
        $maxSize = (int) $this->parameterBag->get('app.product_image.max_size');
        $minDimension = (int) $this->parameterBag->get('app.product_image.min_dimension');
        $maxDimension = (int) $this->parameterBag->get('app.product_image.max_dimension');

        if (!$file->isValid() || $file->getSize() <= 0) {
            throw InvalidProductImageException::missing();
        }

        $declaredMimeType = $file->getMimeType();
        $declaredImageMime = self::MIME_TYPE_ALIASES[$declaredMimeType] ?? null;
        if (null === $declaredImageMime) {
            throw InvalidProductImageException::invalidMimeType($declaredMimeType);
        }

        if ($file->getSize() > $maxSize) {
            throw InvalidProductImageException::tooLarge($maxSize);
        }

        $dimensions = getimagesize($file->getPathname());
        if (false === $dimensions) {
            throw InvalidProductImageException::invalidDimensions($minDimension, $maxDimension);
        }

        $contentMimeType = $dimensions['mime'];
        $normalizedContentMimeType = self::MIME_TYPE_ALIASES[$contentMimeType] ?? null;
        if (null === $normalizedContentMimeType || $normalizedContentMimeType !== $declaredImageMime) {
            throw InvalidProductImageException::invalidMimeType($declaredMimeType);
        }

        $width = $dimensions[0];
        $height = $dimensions[1];
        if (
            $width < $minDimension
            || $height < $minDimension
            || $width > $maxDimension
            || $height > $maxDimension
        ) {
            throw InvalidProductImageException::invalidDimensions($minDimension, $maxDimension);
        }
    }
}
