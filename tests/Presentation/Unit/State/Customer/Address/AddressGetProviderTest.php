<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Address;

use ApiPlatform\Metadata\Get;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Query\DisplayAddress\DisplayAddressQuery;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\Model\Address as DomainAddress;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\State\Address\AddressGetProvider;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AddressGetProviderTest extends TestCase
{
    use CustomerUserTrait;

    public function testItReturnsAddressResource(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $security = $this->createMock(Security::class);

        $user = $this->createUser('550e8400-e29b-41d4-a716-446655440300');
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440301');
        $customerOutput = new CurrentCustomerItem($customerId->toString());
        $address = $this->createAddress($customerId);
        $addressOutput = AddressItem::fromAddress($address);

        $queryBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput, $addressOutput): CurrentCustomerItem|AddressItem {
                if ($query instanceof DisplayMyCustomerQuery) {
                    $this->assertSame('550e8400-e29b-41d4-a716-446655440300', $query->userAccountId);

                    return $customerOutput;
                }

                if ($query instanceof DisplayAddressQuery) {
                    $this->assertSame('550e8400-e29b-41d4-a716-446655440302', $query->addressId);
                    $this->assertSame('550e8400-e29b-41d4-a716-446655440301', $query->ownerId);

                    return $addressOutput;
                }

                $this->fail('Unexpected query dispatched.');
            });

        $provider = new AddressGetProvider($queryBus, new CurrentCustomerResolver($queryBus, $security), new AddressResourcePresenter());

        $result = $provider->provide(
            new Get(name: 'shop-addresses-me-get'),
            ['id' => '550e8400-e29b-41d4-a716-446655440302'],
        );

        $this->assertInstanceOf(AddressResource::class, $result);
        $this->assertSame('Office', $result->name);
    }

    public function testItThrowsOnInvalidId(): void
    {
        $queryBus = $this->createStub(QueryBusInterface::class);
        $security = $this->createStub(Security::class);

        $provider = new AddressGetProvider($queryBus, new CurrentCustomerResolver($queryBus, $security), new AddressResourcePresenter());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $provider->provide(new Get(name: 'shop-addresses-me-get'), ['id' => '']);
    }

    private function createAddress(CustomerId $customerId): DomainAddress
    {
        return DomainAddress::reconstitute(
            id: AddressId::fromString('550e8400-e29b-41d4-a716-446655440302'),
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
        );
    }
}
