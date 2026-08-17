<?php

declare(strict_types=1);

namespace App\Domain\Customer\Model;

use App\Domain\Customer\Event\Address\AddressAddedEvent;
use App\Domain\Customer\Event\Address\AddressRemovedEvent;
use App\Domain\Customer\Event\Address\AddressUpdatedEvent;
use App\Domain\Customer\Event\Address\DefaultAddressChangedEvent;
use App\Domain\Customer\Event\Customer\CustomerActivatedEvent;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\Event\Customer\CustomerDisabledEvent;
use App\Domain\Customer\Exception\AddressLimitReachedException;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventTrait;
use DateTimeImmutable;

/**
 * Racine d'agregat. Elle porte ses adresses (voir `Address`) et garantit au plus
 * `MAX_ADDRESSES`, avec exactement une adresse
 * par defaut des qu'il en existe au moins une.
 */
final class Customer
{
    use DomainEventTrait;

    public const int MAX_ADDRESSES = 5;

    /**
     * @param list<Address> $addresses
     */
    private function __construct(
        private CustomerId $id,
        private ?UserAccountId $userAccountId,
        private CustomerStatus $status,
        private array $addresses,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        CustomerId $id,
        DateTimeImmutable $now,
        ?UserAccountId $userAccountId = null,
    ): self {
        $customer = new self(
            id: $id,
            userAccountId: $userAccountId,
            status: CustomerStatus::active(),
            addresses: [],
            createdAt: $now,
            updatedAt: $now,
        );

        $customer->recordEvent(new CustomerCreatedEvent($id, $userAccountId, $now));

        return $customer;
    }

    /**
     * @param list<Address> $addresses
     */
    public static function reconstitute(
        CustomerId $id,
        CustomerStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?UserAccountId $userAccountId = null,
        array $addresses = [],
    ): self {
        return new self(
            id: $id,
            userAccountId: $userAccountId,
            status: $status,
            addresses: $addresses,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Sort tot si le client est deja actif.
     *
     * Une garde de transition est necessaire : un evenement en decoule. Sans elle, chaque nouvelle
     * tentative du relais de provisionnement en republierait un.
     */
    public function activate(DateTimeImmutable $now): void
    {
        if ($this->status->isActive()) {
            return;
        }

        $this->status = CustomerStatus::active();
        $this->touch($now);

        $this->recordEvent(new CustomerActivatedEvent($this->id, $this->userAccountId, $now));
    }

    public function disable(DateTimeImmutable $now): void
    {
        if ($this->status->isDisabled()) {
            return;
        }

        $this->status = CustomerStatus::disabled();
        $this->touch($now);

        $this->recordEvent(new CustomerDisabledEvent($this->id, $this->userAccountId, $now));
    }

    /**
     * La premiere adresse devient le defaut : sans cela un client aurait des adresses mais
     * aucune selectionnee, etat qu'aucune lecture ne sait presenter.
     */
    public function addAddress(
        AddressId $addressId,
        string $label,
        string $firstname,
        string $lastname,
        string $street,
        string $zipCode,
        string $city,
        string $country,
        string $phone,
        DateTimeImmutable $now,
        ?string $company = null,
    ): void {
        if (count($this->addresses) >= self::MAX_ADDRESSES) {
            throw new AddressLimitReachedException();
        }

        $this->addresses[] = Address::create(
            id: $addressId,
            ownerId: $this->id,
            label: $label,
            firstname: $firstname,
            lastname: $lastname,
            street: $street,
            zipCode: $zipCode,
            city: $city,
            country: $country,
            phone: $phone,
            now: $now,
            company: $company,
            isDefault: [] === $this->addresses,
        );

        $this->touch($now);

        $this->recordEvent(new AddressAddedEvent($this->id, $this->userAccountId, $addressId, $now));
    }

    public function updateAddress(
        AddressId $addressId,
        string $label,
        string $firstname,
        string $lastname,
        string $street,
        string $zipCode,
        string $city,
        string $country,
        string $phone,
        DateTimeImmutable $now,
        ?string $company = null,
    ): void {
        $address = $this->getAddress($addressId);

        $address->update(
            label: $label,
            firstname: $firstname,
            lastname: $lastname,
            street: $street,
            zipCode: $zipCode,
            city: $city,
            country: $country,
            phone: $phone,
            now: $now,
            company: $company,
        );

        $this->touch($now);

        $this->recordEvent(new AddressUpdatedEvent($this->id, $this->userAccountId, $addressId, $now));
    }

    /**
     * Retirer le defaut promeut la plus ancienne restante, faute de quoi le client garderait
     * des adresses sans defaut. L'ordre de promotion (`createdAt` puis `id`) reproduit celui
     * de la requete qu'il remplace : deux adresses creees dans la meme seconde doivent etre
     * departagees de facon deterministe, sinon le choix depend de l'ordre de stockage.
     */
    public function removeAddress(AddressId $addressId, DateTimeImmutable $now): void
    {
        $address = $this->getAddress($addressId);
        $wasDefault = $address->isDefault();

        $this->addresses = array_values(array_filter(
            $this->addresses,
            static fn (Address $candidate): bool => !$candidate->getId()->equals($addressId),
        ));

        $this->touch($now);

        $this->recordEvent(new AddressRemovedEvent($this->id, $this->userAccountId, $addressId, $now));

        if (!$wasDefault) {
            return;
        }

        $replacement = $this->oldestAddress();
        if (null === $replacement) {
            return;
        }

        $replacement->markAsDefault($now);

        $this->recordEvent(new DefaultAddressChangedEvent(
            $this->id,
            $this->userAccountId,
            $replacement->getId(),
            $now,
        ));
    }

    public function setDefaultAddress(AddressId $addressId, DateTimeImmutable $now): void
    {
        $target = $this->getAddress($addressId);

        if ($target->isDefault()) {
            return;
        }

        foreach ($this->addresses as $address) {
            if ($address->isDefault()) {
                $address->unsetDefault($now);
            }
        }

        $target->markAsDefault($now);
        $this->touch($now);

        $this->recordEvent(new DefaultAddressChangedEvent($this->id, $this->userAccountId, $addressId, $now));
    }

    public function findAddress(AddressId $addressId): ?Address
    {
        foreach ($this->addresses as $address) {
            if ($address->getId()->equals($addressId)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @return list<Address>
     */
    public function getAddresses(): array
    {
        return $this->addresses;
    }

    public function getId(): CustomerId
    {
        return $this->id;
    }

    public function getUserAccountId(): ?UserAccountId
    {
        return $this->userAccountId;
    }

    public function getStatus(): CustomerStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function getAddress(AddressId $addressId): Address
    {
        return $this->findAddress($addressId) ?? throw new AddressNotFoundException();
    }

    private function oldestAddress(): ?Address
    {
        $candidates = $this->addresses;

        usort($candidates, static fn (Address $firstAddress, Address $secondAddress): int => [
            $firstAddress->getCreatedAt(), $firstAddress->getId()->toString(),
        ] <=> [
            $secondAddress->getCreatedAt(), $secondAddress->getId()->toString(),
        ]);

        return $candidates[0] ?? null;
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
