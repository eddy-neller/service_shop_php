<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Address;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Command\CreateAddress\CreateAddressCommand;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\Model\Address as DomainAddress;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Dto\AddressPostInput;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\State\Address\AddressPostProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;

final class AddressPostProcessorTest extends TestCase
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

    public function testProcessWithValidInput(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440400'));

        $processor = new AddressPostProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            new AddressResourcePresenter(),
        );

        $input = new AddressPostInput();
        $input->name = 'Office';
        $input->firstname = 'John';
        $input->lastname = 'Doe';
        $input->company = 'ACME';
        $input->address = '12 Main St';
        $input->zip = '12345';
        $input->city = 'Paris';
        $input->country = 'France';
        $input->phone = '+33 1 23 45 67 89';

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440401');
        $customerOutput = new CurrentCustomerItem($customerId->toString());

        $address = $this->createAddress($customerId);
        $output = AddressItem::fromAddress($address);

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440400', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($input, $customerId, $output): AddressItem {
                $this->assertInstanceOf(CreateAddressCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->ownerId);
                $this->assertSame($input->name, $command->label);
                $this->assertSame($input->firstname, $command->firstname);
                $this->assertSame($input->lastname, $command->lastname);
                $this->assertSame($input->company, $command->company);
                $this->assertSame($input->address, $command->street);
                $this->assertSame($input->zip, $command->zipCode);
                $this->assertSame($input->city, $command->city);
                $this->assertSame($input->country, $command->country);
                $this->assertSame($input->phone, $command->phone);

                return $output;
            });

        $result = $processor->process($input, $this->operation);

        $this->assertInstanceOf(AddressResource::class, $result);
        $this->assertSame('Office', $result->name);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())
            ->method('getUser');

        $processor = new AddressPostProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            new AddressResourcePresenter(),
        );

        $invalidInput = new stdClass();

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process($invalidInput, $this->operation);
    }

    private function createAddress(CustomerId $customerId): DomainAddress
    {
        return DomainAddress::reconstitute(
            id: AddressId::fromString('550e8400-e29b-41d4-a716-446655440402'),
            ownerId: $customerId,
            label: 'Office',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-01-02 10:00:00'),
            company: 'ACME',
        );
    }
}
