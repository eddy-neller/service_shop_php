<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Adapter\Catalog\Storage;

use App\Application\Shared\Port\FileInterface;
use App\Domain\Catalog\Exception\InvalidProductImageException;
use App\Infrastructure\Adapter\Catalog\Storage\NativeProductImageValidator;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class NativeProductImageValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testValidateAcceptsPngAndWebpImages(): void
    {
        $png = $this->createFile($this->createTempFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wcAAwAB/8z9R5UAAAAASUVORK5CYII=')), 'image/png');
        $webp = $this->createFile($this->createTempFile(base64_decode('UklGRiIAAABXRUJQVlA4IBYAAADQAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=')), 'image/webp');
        $validator = $this->validator(minDimension: 1, maxDimension: 1);

        $validator->validate($png);
        $validator->validate($webp);

        $this->addToAssertionCount(1);
    }

    public static function provideAcceptedDimensions(): Generator
    {
        yield 'At the minimum' => [2, 2];
        yield 'At the maximum' => [3, 3];
        yield 'Minimum width, maximum height' => [2, 3];
    }

    #[DataProvider('provideAcceptedDimensions')]
    public function testValidateAcceptsDimensionsWithinBounds(int $width, int $height): void
    {
        $file = $this->createFile($this->createPng($width, $height), 'image/png');

        $this->validator(minDimension: 2, maxDimension: 3)->validate($file);

        $this->addToAssertionCount(1);
    }

    public function testValidateRejectsGifImages(): void
    {
        $gif = $this->createFile(
            $this->createTempFile(base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')),
            'image/gif',
        );

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Invalid product image file type: image/gif.');

        $this->validator(minDimension: 1, maxDimension: 1)->validate($gif);
    }

    public function testValidateRejectsMimeTypeThatDoesNotMatchTheImageContent(): void
    {
        $file = $this->createFile($this->createPng(1, 1), 'image/jpeg');

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Invalid product image file type: image/jpeg.');

        $this->validator(minDimension: 1, maxDimension: 1)->validate($file);
    }

    public function testValidateRejectsEmptyAndTooLargeFiles(): void
    {
        $empty = $this->createFile($this->createTempFile(''), 'image/png', 0);

        try {
            $this->validator()->validate($empty);
            self::fail('An empty upload must be rejected.');
        } catch (InvalidProductImageException $exception) {
            $this->assertSame('No product image file provided.', $exception->getMessage());
        }

        $png = $this->createFile($this->createPng(1, 1), 'image/png', 101);

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Product image file exceeds the maximum allowed size (100 bytes).');

        $this->validator(maxSize: 100, minDimension: 1, maxDimension: 1)->validate($png);
    }

    public function testValidateRejectsUnreadableImages(): void
    {
        $file = $this->createFile($this->createTempFile('not-an-image'), 'image/png');

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Product image file is not a readable image.');

        $this->validator()->validate($file);
    }

    public static function provideRejectedDimensions(): Generator
    {
        yield 'Width below the minimum' => [1, 2];
        yield 'Height below the minimum' => [2, 1];
        yield 'Width above the maximum' => [4, 3];
        yield 'Height above the maximum' => [3, 4];
    }

    #[DataProvider('provideRejectedDimensions')]
    public function testValidateRejectsDimensionsOutsideConfiguredRange(int $width, int $height): void
    {
        $file = $this->createFile($this->createPng($width, $height), 'image/png');

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Product image dimensions must be between 2 and 3 pixels.');

        $this->validator(minDimension: 2, maxDimension: 3)->validate($file);
    }

    /**
     * PNG niveaux de gris de la taille demandee, construit octet par octet : l'image `ci`
     * n'embarque pas GD, et `getimagesize()` n'a besoin que d'un en-tete valide.
     */
    private function createPng(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            . $type . $data . pack('N', crc32($type . $data));

        $rows = str_repeat("\x00" . str_repeat("\x80", $width), $height);

        return $this->createTempFile(
            "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($rows))
            . $chunk('IEND', ''),
        );
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'product-image-validator-');
        if (false === $path) {
            self::fail('Unable to create temporary product image file.');
        }

        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    private function createFile(string $path, string $mimeType, ?int $size = null): FileInterface
    {
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getPathname')->willReturn($path);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getSize')->willReturn($size ?? filesize($path));

        return $file;
    }

    private function validator(int $maxSize = 1000, int $minDimension = 1, int $maxDimension = 2000): NativeProductImageValidator
    {
        return new NativeProductImageValidator(new ParameterBag([
            'app.product_image.max_size' => $maxSize,
            'app.product_image.min_dimension' => $minDimension,
            'app.product_image.max_dimension' => $maxDimension,
        ]));
    }
}
