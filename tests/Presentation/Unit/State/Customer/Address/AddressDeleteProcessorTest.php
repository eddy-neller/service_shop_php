<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Address;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Command\DeleteAddress\DeleteAddressCommand;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Customer\State\Address\AddressDeleteProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AddressDeleteProcessorTest extends TestCase
{
    use CustomerUserTrait;

    private CommandBusInterface&MockObject $commandBus;

    private QueryBusInterface&MockObject $queryBus;

    private Operation&MockObject $operation;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->queryBus = $this->createMock(QueryBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())->method('getName');
    }

    public function testProcessDeletesAddress(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440600'));

        $processor = new AddressDeleteProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
        );

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440601');
        $customerOutput = new CurrentCustomerItem($customerId->toString());

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440600', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($customerId): mixed {
                $this->assertInstanceOf(DeleteAddressCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->ownerId);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440602', $command->addressId);

                return null;
            });

        $processor->process(null, $this->operation, ['id' => '550e8400-e29b-41d4-a716-446655440602']);
    }

    public function testProcessThrowsOnInvalidId(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())
            ->method('getUser');

        $processor = new AddressDeleteProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(null, $this->operation, ['id' => '']);
    }
}
