<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\ApiPlatform\Serializer\ContextBuilder;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Infrastructure\ApiPlatform\Serializer\ContextBuilder\AutoGroup;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AutoGroupTest extends TestCase
{
    private SerializerContextBuilderInterface&Stub $decorated;

    private AutoGroup $autoGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decorated = $this->createStub(SerializerContextBuilderInterface::class);
        $this->autoGroup = new AutoGroup($this->decorated);
    }

    public function testDoesNotCreateGroupsForOperationWithoutShortName(): void
    {
        $operation = new Get();
        $this->decorated->method('createFromRequest')->willReturn(['operation' => $operation]);

        $context = $this->autoGroup->createFromRequest(new Request(), true);

        self::assertSame(['operation' => $operation, 'groups' => []], $context);
    }
}
