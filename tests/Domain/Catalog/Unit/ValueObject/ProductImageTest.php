<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\ValueObject\ProductImage;
use PHPUnit\Framework\TestCase;

final class ProductImageTest extends TestCase
{
    public function testConstructorStoresFileName(): void
    {
        $image = ProductImage::create('image.jpg');

        $this->assertSame('image.jpg', $image->fileName());
    }

    public function testConstructorAllowsNull(): void
    {
        $image = ProductImage::create();

        $this->assertNull($image->fileName());
    }

    public function testWithFileReturnsNewInstance(): void
    {
        $image = ProductImage::create('old.jpg');

        $updated = $image->withFile('new.jpg');

        $this->assertNotSame($image, $updated);
        $this->assertSame('old.jpg', $image->fileName());
        $this->assertSame('new.jpg', $updated->fileName());
    }

    public function testWithFileAllowsClearingFileName(): void
    {
        $image = ProductImage::create('old.jpg');

        $updated = $image->withFile(null);

        $this->assertNull($updated->fileName());
    }
}
