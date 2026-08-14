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
 * Ses cinq methodes du monolithe ont ete absorbees par l'agregat, et ce n'est pas un choix
 * de style : `countByOwnerForUpdate()` s'appuyait sur un `SELECT … FOR UPDATE` que MongoDB
 * n'a pas. Reimplementee ici, elle aurait compte sans verrouiller — meme signature, meme
 * nom rassurant, et deux ecritures concurrentes auraient produit six adresses sans qu'aucune
 * erreur n'apparaisse nulle part. Ne pas la faire revenir.
 */
interface CustomerRepositoryInterface
{
    /**
     * `username` a disparu de la liste du monolithe : ce tri s'appuyait sur une jointure DQL
     * vers la table des comptes utilisateurs, qui vit desormais dans un autre service et une
     * autre base. Le read model n'expose de toute facon pas ce champ — seul le tri est perdu.
     */
    public const array SORT_FIELDS = ['status', 'createdAt'];

    /**
     * Champs de tri des adresses. Elles n'ont plus de depot, mais la liste blanche reste
     * exposee ici : c'est `Presentation` qui la consomme pour valider le parametre `order`,
     * et le tri lui-meme est applique par `DisplayListAddressQueryHandler`.
     */
    public const array ADDRESS_SORT_FIELDS = ['name', 'city', 'country', 'createdAt'];

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
