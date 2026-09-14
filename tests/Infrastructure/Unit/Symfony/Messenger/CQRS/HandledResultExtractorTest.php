<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS;

use App\Infrastructure\Symfony\Messenger\CQRS\HandledResultExtractor;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class HandledResultExtractorTest extends TestCase
{
    private HandledResultExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HandledResultExtractor();
    }

    public function testExtractReturnsTheSingleHandlerResult(): void
    {
        $result = new stdClass();
        $envelope = new Envelope(new stdClass(), [new HandledStamp($result, 'handler')]);

        self::assertSame($result, $this->extractor->extract($envelope));
    }

    public function testExtractReturnsNullForAVoidHandler(): void
    {
        $envelope = new Envelope(new stdClass(), [new HandledStamp(null, 'handler')]);

        self::assertNull($this->extractor->extract($envelope));
    }

    public function testExtractRejectsAnEnvelopeWithoutHandledStamp(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be handled exactly once, handled 0 time(s)');

        $this->extractor->extract(new Envelope(new stdClass()));
    }

    public function testExtractRejectsMultipleHandledStamps(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be handled exactly once, handled 2 time(s)');

        $this->extractor->extract(new Envelope(new stdClass(), [
            new HandledStamp('first', 'first_handler'),
            new HandledStamp('second', 'second_handler'),
        ]));
    }
}
