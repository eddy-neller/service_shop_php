<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Customer;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Customer\UseCase\Command\DisableCustomer\DisableCustomerCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Presentation\Customer\Dto\CustomerPatchInput;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Customer\State\Customer\CustomerPatchProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CustomerPatchProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private Operation&MockObject $operation;

    private CustomerPatchProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');

        $this->processor = new CustomerPatchProcessor(
            $this->commandBus,
            new CustomerResourcePresenter(new AddressResourcePresenter()),
        );
    }

    public function testProcessWithValidPayloadDispatchesDisableAndReturnsResource(): void
    {
        $rawId = '550e8400-e29b-41d4-a716-446655440710';
        $expectedCustomerId = CustomerId::fromString($rawId);
        $customer = Customer::create(
            id: $expectedCustomerId,
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            userAccountId: UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440711'),
        );
        $data = new CustomerPatchInput();
        $data->status = 2;

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($expectedCustomerId, $customer): CustomerItem {
                $this->assertInstanceOf(DisableCustomerCommand::class, $command);
                $this->assertSame($expectedCustomerId->toString(), $command->customerId);

                return CustomerItem::fromCustomer($customer);
            });

        $result = $this->processor->process($data, $this->operation, ['id' => $rawId]);

        $this->assertSame($customer->getId()->toString(), $result->id);
        $this->assertSame($customer->getUserAccountId()?->toString(), $result->userAccountId);
    }

    public function testProcessThrowsLogicExceptionWhenPayloadTypeIsInvalid(): void
    {
        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process(null, $this->operation, ['id' => '550e8400-e29b-41d4-a716-446655440710']);
    }

    public function testProcessThrowsLogicExceptionWhenIdMissing(): void
    {
        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $data = new CustomerPatchInput();
        $data->status = 2;

        $this->processor->process($data, $this->operation, []);
    }
}
