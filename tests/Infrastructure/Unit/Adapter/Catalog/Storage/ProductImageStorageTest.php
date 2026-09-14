<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Adapter\Catalog\Storage;

use App\Application\Shared\Port\FileInterface;
use App\Infrastructure\Adapter\Catalog\Storage\ProductImageStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;

final class ProductImageStorageTest extends TestCase
{
    private Filesystem $filesystem;

    private string $directory;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir() . '/product-image-storage-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->directory);
    }

    public function testStoreGeneratesAReadableRandomNameAndRemoveDeletesIt(): void
    {
        $source = $this->createSource('product-content');
        chmod($source, 0600);
        $storage = $this->storage();

        try {
            $fileName = $storage->store($this->file($source, 'image/webp'));
            $path = $this->directory . '/' . $fileName;

            $this->assertMatchesRegularExpression('/^[a-z0-9]{32}\.webp$/', $fileName);
            $this->assertSame('product-content', file_get_contents($path));
            $this->assertSame('644', decoct(fileperms($path) & 0777));

            $storage->remove($fileName);

            $this->assertFileDoesNotExist($path);
        } finally {
            unlink($source);
        }
    }

    public function testStoreRejectsUnsupportedMimeType(): void
    {
        $source = $this->createSource('product-content');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsupported product image MIME type "image/gif".');

            $this->storage()->store($this->file($source, 'image/gif'));
        } finally {
            unlink($source);
        }
    }

    public function testRemoveIgnoresAnInvalidFileName(): void
    {
        $this->storage()->remove('../product.jpg');

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    private function createSource(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'product-image-storage-source-');
        if (false === $path) {
            self::fail('Unable to create temporary product image source.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function file(string $path, string $mimeType): FileInterface
    {
        $file = $this->createStub(FileInterface::class);
        $file->method('getPathname')->willReturn($path);
        $file->method('getMimeType')->willReturn($mimeType);

        return $file;
    }

    private function storage(): ProductImageStorage
    {
        return new ProductImageStorage(
            new ParameterBag(['app.product_image.upload_directory' => $this->directory]),
            $this->filesystem,
            new NullLogger(),
        );
    }
}
