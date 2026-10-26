<?php

declare(strict_types=1);

namespace App\Application\Customer\Port;

use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;

/**
 * Le seul depot du contexte : les adresses vivent **dans** l'agregat `Customer`, il n'y a
 * donc pas d'`AddressRepositoryInterface`.
 *
 * Ce n'est pas un choix de style : `countByOwnerForUpdate()` s'appuyait sur un `SELECT … FOR UPDATE` que MongoDB
 * n'a pas. Reimplementee ici, elle aurait compte sans verrouiller — meme signature, meme
 * nom rassurant, et deux ecritures concurrentes auraient produit six adresses sans qu'aucune
 * erreur n'apparaisse nulle part. Ne pas la faire revenir.
 */
interface CustomerRepositoryInterface
{
    public function nextIdentity(): CustomerId;

    /**
     * L'adresse n'a plus de depot a elle, mais elle garde une identite propre : l'API l'expose
     * dans ses URI (`/me/addresses/{id}`). C'est donc ce depot qui la genere.
     */
    public function nextAddressIdentity(): AddressId;

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $orderBy
     *
     * @return array{items: list<Customer>, totalItems: int, totalPages: int}
     */
    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Customer $customer): void;

    public function delete(Customer $customer): void;

    public function findById(CustomerId $id): ?Customer;

    public function findByUserAccountId(UserAccountId $userAccountId): ?Customer;
}
