<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Adapter\Catalog\Storage;

use App\Infrastructure\Adapter\Catalog\Storage\ProductImageUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ProductImageUrlResolverTest extends TestCase
{
    public function testResolveReturnsNullWhenImageNameIsMissing(): void
    {
        $resolver = new ProductImageUrlResolver(new ParameterBag([
            'app.product_image.upload_url' => '/uploads/images/catalog/product',
        ]));

        $this->assertNull($resolver->resolve(null));
        $this->assertNull($resolver->resolve(''));
    }

    public function testResolveBuildsUrlFromConfiguredBaseUrl(): void
    {
        $resolver = new ProductImageUrlResolver(new ParameterBag([
            'app.product_image.upload_url' => '/products/',
        ]));

        $this->assertSame('/products/product.webp', $resolver->resolve('/product.webp'));
    }
}
