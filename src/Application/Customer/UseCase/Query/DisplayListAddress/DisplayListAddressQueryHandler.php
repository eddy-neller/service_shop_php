<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayListAddress;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Customer\ReadModel\AddressList;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Application\Shared\ReadModel\Pagination;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Filtre, trie et pagine en memoire.
 *
 * Ce n'est pas une concession de performance : un client porte au plus cinq adresses, toutes
 * arrivees dans le document deja lu. Le faire ici represente strictement moins d'allers-retours
 * que la requete SQL qu'il remplace — et surtout, cela evite d'ajouter au depot une methode
 * de recherche sur des donnees qui ne sont pas une collection.
 */
final readonly class DisplayListAddressQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayListAddressQuery $query): AddressList
    {
        $pagination = Pagination::fromRaw($query->page, $query->itemsPerPage);

        $customer = $this->repository->findById(CustomerId::fromString($query->ownerId));
        $addresses = null === $customer ? [] : $customer->getAddresses();

        $addresses = $this->applyFilters($addresses, $query->filters);
        $this->applyOrdering($addresses, $query->orderBy);

        $totalItems = count($addresses);
        $totalPages = $pagination->itemsPerPage > 0
            ? (int) ceil($totalItems / $pagination->itemsPerPage)
            : 1;

        $page = array_slice(
            $addresses,
            max(0, ($pagination->page - 1) * $pagination->itemsPerPage),
            $pagination->itemsPerPage,
        );

        return new AddressList(
            items: array_map(
                static fn (Address $address): AddressItem => AddressItem::fromAddress($address),
                $page,
            ),
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    /**
     * Reprend les filtres exposes par l'API : `name` et `city` en correspondance partielle,
     * `country` en exact. Les noms sont ceux du read model, pas ceux du domaine.
     *
     * @param list<Address>        $addresses
     * @param array<string, mixed> $filters
     *
     * @return list<Address>
     */
    private function applyFilters(array $addresses, array $filters): array
    {
        $partial = [
            'name' => static fn (Address $address): string => $address->getLabel(),
            'city' => static fn (Address $address): string => $address->getCity(),
        ];

        foreach ($partial as $field => $accessor) {
            $value = $filters[$field] ?? null;
            if (!is_string($value) || '' === trim($value)) {
                continue;
            }

            $needle = mb_strtolower(trim($value));
            $addresses = array_values(array_filter(
                $addresses,
                static fn (Address $address): bool => str_contains(mb_strtolower($accessor($address)), $needle),
            ));
        }

        $country = $filters['country'] ?? null;
        if (is_string($country) && '' !== trim($country)) {
            $expected = trim($country);
            $addresses = array_values(array_filter(
                $addresses,
                static fn (Address $address): bool => $address->getCountry() === $expected,
            ));
        }

        return $addresses;
    }

    /**
     * @param list<Address>        $addresses
     * @param array<string, mixed> $orderBy
     */
    private function applyOrdering(array &$addresses, array $orderBy): void
    {
        $accessors = [
            'name' => static fn (Address $address): string => $address->getLabel(),
            'city' => static fn (Address $address): string => $address->getCity(),
            'country' => static fn (Address $address): string => $address->getCountry(),
            'createdAt' => static fn (Address $address): string => $address->getCreatedAt()->format('c'),
        ];

        foreach ($orderBy as $field => $direction) {
            if (!isset($accessors[$field])) {
                continue;
            }

            $accessor = $accessors[$field];
            $descending = 'desc' === strtolower((string) $direction);

            usort($addresses, static function (Address $leftAddress, Address $rightAddress) use ($accessor, $descending): int {
                $comparison = $accessor($leftAddress) <=> $accessor($rightAddress);

                return $descending ? -$comparison : $comparison;
            });
        }
    }
}
