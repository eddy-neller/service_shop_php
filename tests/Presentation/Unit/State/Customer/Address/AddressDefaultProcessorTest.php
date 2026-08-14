<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Address;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Command\SetDefaultAddress\SetDefaultAddressCommand;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\Model\Address as DomainAddress;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\State\Address\AddressDefaultProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AddressDefaultProcessorTest extends TestCase
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

    public function testProcessSetsDefaultAddress(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440600'));

        $processor = new AddressDefaultProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            new AddressResourcePresenter(),
        );

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440601');
        $addressId = AddressId::fromString('550e8400-e29b-41d4-a716-446655440602');
        $customerOutput = new CurrentCustomerItem($customerId->toString());
        $address = DomainAddress::create(
            id: $addressId,
            ownerId: $customerId,
            label: 'Office',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            isDefault: true,
        );
        $output = AddressItem::fromAddress($address);

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440600', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($customerId, $addressId, $output): AddressItem {
                $this->assertInstanceOf(SetDefaultAddressCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->ownerId);
                $this->assertSame($addressId->toString(), $command->addressId);

                return $output;
            });

        $result = $processor->process(null, $this->operation, ['id' => $addressId->toString()]);

        $this->assertInstanceOf(AddressResource::class, $result);
        $this->assertTrue($result->isDefault);
    }

    public function testProcessThrowsOnInvalidIdType(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())
            ->method('getUser');

        $processor = new AddressDefaultProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            new AddressResourcePresenter(),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(null, $this->operation, ['id' => 123]);
    }
}
