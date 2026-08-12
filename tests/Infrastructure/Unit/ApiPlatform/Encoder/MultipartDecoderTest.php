<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\ApiPlatform\Encoder;

use App\Infrastructure\ApiPlatform\Encoder\MultipartDecoder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class MultipartDecoderTest extends TestCase
{
    private RequestStack&Stub $requestStack;

    private MultipartDecoder $multipartDecoder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestStack = $this->createStub(RequestStack::class);
        $this->multipartDecoder = new MultipartDecoder($this->requestStack);
    }

    public function testSupportsDecodingWithValidFormat(): void
    {
        $result = $this->multipartDecoder->supportsDecoding('multipart');

        $this->assertTrue($result);
    }

    public function testSupportsDecodingWithInvalidFormat(): void
    {
        $result = $this->multipartDecoder->supportsDecoding('json');

        $this->assertFalse($result);
    }

    public function testSupportsDecodingWithEmptyFormat(): void
    {
        $result = $this->multipartDecoder->supportsDecoding('');

        $this->assertFalse($result);
    }

    public function testSupportsDecodingWithNullFormat(): void
    {
        $result = $this->multipartDecoder->supportsDecoding('null');

        $this->assertFalse($result);
    }

    public function testDecodeWithNoCurrentRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')
            ->willReturn(null);

        $result = $this->multipartDecoder->decode('data', 'multipart');

        $this->assertNull($result);
    }

    public function testDecodeTransformsJsonElementsOnly(): void
    {
        $request = new Request([], [
            'payload' => json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR),
            'plain' => 'value',
            'jsonString' => json_encode('baz', JSON_THROW_ON_ERROR),
        ]);

        $this->requestStack->method('getCurrentRequest')
            ->willReturn($request);

        $result = $this->multipartDecoder->decode('data', 'multipart');

        $this->assertIsArray($result);
        $this->assertSame(['foo' => 'bar'], $result['payload']);
        $this->assertSame('value', $result['plain']);
        $this->assertSame('"baz"', $result['jsonString']);
    }
}
