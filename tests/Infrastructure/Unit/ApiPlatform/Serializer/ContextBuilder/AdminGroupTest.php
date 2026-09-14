<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\ApiPlatform\Serializer\ContextBuilder;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Infrastructure\ApiPlatform\Serializer\ContextBuilder\AdminGroup;
use App\Infrastructure\Symfony\Security\RoleSet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class AdminGroupTest extends TestCase
{
    private SerializerContextBuilderInterface&Stub $decorated;

    private Security&MockObject $security;

    private AdminGroup $adminGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decorated = $this->createStub(SerializerContextBuilderInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->adminGroup = new AdminGroup($this->decorated, $this->security);
    }

    public function testDoesNotCreateAdminGroupForOperationWithoutShortName(): void
    {
        $operation = new Get();
        $this->decorated->method('createFromRequest')->willReturn(['operation' => $operation]);
        $this->security->expects(self::once())->method('isGranted')->with(RoleSet::ROLE_ADMIN)->willReturn(true);

        $context = $this->adminGroup->createFromRequest(new Request(), true);

        self::assertSame(['operation' => $operation], $context);
    }
}
