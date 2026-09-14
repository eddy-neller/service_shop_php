<?php

declare(strict_types=1);

namespace App\Domain\Customer\Model;

use App\Domain\Customer\Exception\InvalidAddressException;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use DateTimeImmutable;

/**
 * Entite **interne** a l'agregat `Customer`, pas une racine.
 *
 * En faire une racine avec son propre repository imposerait a PostgreSQL de tenir
 * les deux invariants transverses : le plafond de 5 par un verrou pessimiste sur la ligne
 * client, l'unicite du defaut par un index unique partiel. MongoDB n'a ni l'un ni l'autre.
 * Ces regles vivent donc dans `Customer`, ou elles se verifient en memoire et se commitent
 * dans une seule ecriture atomique.
 *
 * Aucune methode ci-dessous n'enregistre d'evenement : c'est la racine qui publie, parce que
 * c'est elle que le depot ecrit. `ownerId` est conserve — redondant avec l'imbrication, mais
 * il porte `belongsTo()` et alimente le read model sans le modifier.
 */
final class Address
{
    private function __construct(
        private AddressId $id,
        private CustomerId $ownerId,
        private string $label,
        private string $firstname,
        private string $lastname,
        private ?string $company,
        private string $street,
        private string $zipCode,
        private string $city,
        private string $country,
        private string $phone,
        private bool $isDefault,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        AddressId $id,
        CustomerId $ownerId,
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
        bool $isDefault = false,
    ): self {
        return new self(
            id: $id,
            ownerId: $ownerId,
            label: self::assertLength($label, 2, 100, 'Address label'),
            firstname: self::assertLength($firstname, 2, 32, 'Firstname'),
            lastname: self::assertLength($lastname, 2, 32, 'Lastname'),
            company: self::assertOptionalLength($company, 2, 50, 'Company'),
            street: self::assertLength($street, 2, 150, 'Street'),
            zipCode: self::assertLength($zipCode, 2, 30, 'Zip code'),
            city: self::assertLength($city, 2, 50, 'City'),
            country: self::assertLength($country, 2, 50, 'Country'),
            phone: self::assertLength($phone, 2, 30, 'Phone'),
            isDefault: $isDefault,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        AddressId $id,
        CustomerId $ownerId,
        string $label,
        string $firstname,
        string $lastname,
        string $street,
        string $zipCode,
        string $city,
        string $country,
        string $phone,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?string $company = null,
        bool $isDefault = false,
    ): self {
        return new self(
            id: $id,
            ownerId: $ownerId,
            label: self::assertLength($label, 2, 100, 'Address label'),
            firstname: self::assertLength($firstname, 2, 32, 'Firstname'),
            lastname: self::assertLength($lastname, 2, 32, 'Lastname'),
            company: self::assertOptionalLength($company, 2, 50, 'Company'),
            street: self::assertLength($street, 2, 150, 'Street'),
            zipCode: self::assertLength($zipCode, 2, 30, 'Zip code'),
            city: self::assertLength($city, 2, 50, 'City'),
            country: self::assertLength($country, 2, 50, 'Country'),
            phone: self::assertLength($phone, 2, 30, 'Phone'),
            isDefault: $isDefault,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function update(
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
        $this->label = self::assertLength($label, 2, 100, 'Address label');
        $this->firstname = self::assertLength($firstname, 2, 32, 'Firstname');
        $this->lastname = self::assertLength($lastname, 2, 32, 'Lastname');
        $this->company = self::assertOptionalLength($company, 2, 50, 'Company');
        $this->street = self::assertLength($street, 2, 150, 'Street');
        $this->zipCode = self::assertLength($zipCode, 2, 30, 'Zip code');
        $this->city = self::assertLength($city, 2, 50, 'City');
        $this->country = self::assertLength($country, 2, 50, 'Country');
        $this->phone = self::assertLength($phone, 2, 30, 'Phone');

        $this->touch($now);
    }

    public function markAsDefault(DateTimeImmutable $now): void
    {
        $this->isDefault = true;
        $this->touch($now);
    }

    public function unsetDefault(DateTimeImmutable $now): void
    {
        $this->isDefault = false;
        $this->touch($now);
    }

    public function belongsTo(CustomerId $ownerId): bool
    {
        return $this->ownerId->equals($ownerId);
    }

    public function getId(): AddressId
    {
        return $this->id;
    }

    public function getOwnerId(): CustomerId
    {
        return $this->ownerId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getZipCode(): string
    {
        return $this->zipCode;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertLength(string $value, int $min, int $max, string $label): string
    {
        $trimmed = trim($value);
        $length = strlen($trimmed);

        if ($length < $min || $length > $max) {
            throw InvalidAddressException::lengthOutOfRange($label, $min, $max);
        }

        return $trimmed;
    }

    private static function assertOptionalLength(?string $value, int $min, int $max, string $label): ?string
    {
        if (null === $value) {
            return null;
        }

        return self::assertLength($value, $min, $max, $label);
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
