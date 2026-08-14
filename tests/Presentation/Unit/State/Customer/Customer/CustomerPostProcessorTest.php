<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Customer;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Customer\UseCase\Command\CreateCustomer\CreateCustomerCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Presentation\Customer\ApiResource\CustomerResource;
use App\Presentation\Customer\Dto\CustomerPostInput;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Customer\State\Customer\CustomerPostProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CustomerPostProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private Operation&MockObject $operation;

    private CustomerPostProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');

        $this->processor = new CustomerPostProcessor(
            $this->commandBus,
            new CustomerResourcePresenter(new AddressResourcePresenter()),
        );
    }

    public function testProcessWithValidInputDispatchesCommand(): void
    {
        $input = new CustomerPostInput();
        $input->userAccountId = '550e8400-e29b-41d4-a716-446655440700';

        $expectedUserAccountId = UserAccountId::fromString($input->userAccountId);

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($expectedUserAccountId): CustomerItem {
                $this->assertInstanceOf(CreateCustomerCommand::class, $command);
                $this->assertSame($expectedUserAccountId->toString(), $command->userAccountId);

                return CustomerItem::fromCustomer(Customer::reconstitute(
                    id: CustomerId::fromString('550e8400-e29b-41d4-a716-446655440701'),
                    status: CustomerStatus::active(),
                    createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
                    updatedAt: new DateTimeImmutable('2025-01-01 10:00:00'),
                    userAccountId: $expectedUserAccountId,
                ));
            });

        $result = $this->processor->process($input, $this->operation);

        $this->assertInstanceOf(CustomerResource::class, $result);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440701', $result->id);
        $this->assertSame($input->userAccountId, $result->userAccountId);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process(new stdClass(), $this->operation);
    }
}
