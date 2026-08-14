<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Customer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Domain\Customer\Model\Customer as DomainCustomer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Infrastructure\Adapter\Uuid\UuidGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;

final readonly class MongoCustomerRepository implements CustomerRepositoryInterface
{
    public function __construct(
        private DocumentManager $documentManager,
        private UuidGeneratorInterface $uuidGenerator,
        private CustomerMapper $mapper,
    ) {
    }

    public function nextIdentity(): CustomerId
    {
        return CustomerId::fromString($this->uuidGenerator->generate());
    }

    public function nextAddressIdentity(): AddressId
    {
        return AddressId::fromString($this->uuidGenerator->generate());
    }

    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array
    {
        $totalItems = $this->applyFilters($this->createQueryBuilder(), $filters)
            ->count()
            ->getQuery()
            ->execute();
        $totalItems = is_int($totalItems) ? $totalItems : 0;

        $totalPages = $itemsPerPage > 0 ? (int) ceil($totalItems / $itemsPerPage) : 1;

        $builder = $this->applyFilters($this->createQueryBuilder(), $filters);
        $this->applyOrdering($builder, $orderBy);

        $builder
            ->skip(max(0, ($page - 1) * $itemsPerPage))
            ->limit($itemsPerPage);

        $items = [];
        foreach ($builder->getQuery()->execute() as $document) {
            if ($document instanceof CustomerDocument) {
                $items[] = $this->mapper->toDomain($document);
            }
        }

        return [
            'items' => $items,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Ne flushe pas : c'est `MongoTransactional` qui declenche le flush transactionnel unique
     * du cas d'usage. Les adresses partent avec le client, dans la meme ecriture de document.
     */
    public function save(DomainCustomer $customer): void
    {
        $document = $this->mapper->toDocument($customer, $this->findDocument($customer->getId()));

        $this->documentManager->persist($document);
    }

    public function delete(DomainCustomer $customer): void
    {
        $document = $this->findDocument($customer->getId());
        if (null === $document) {
            return;
        }

        $this->documentManager->remove($document);
    }

    public function findById(CustomerId $id): ?DomainCustomer
    {
        $document = $this->findDocument($id);

        return null === $document ? null : $this->mapper->toDomain($document);
    }

    public function findByUserAccountId(UserAccountId $userAccountId): ?DomainCustomer
    {
        $document = $this->documentManager
            ->getRepository(CustomerDocument::class)
            ->findOneBy(['userAccountId' => $userAccountId->toString()]);

        return $document instanceof CustomerDocument ? $this->mapper->toDomain($document) : null;
    }

    private function findDocument(CustomerId $id): ?CustomerDocument
    {
        $document = $this->documentManager->find(CustomerDocument::class, $id->toString());

        return $document instanceof CustomerDocument ? $document : null;
    }

    private function createQueryBuilder(): Builder
    {
        return $this->documentManager->createQueryBuilder(CustomerDocument::class);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $builder, array $filters): Builder
    {
        $userAccountId = $filters['userAccountId'] ?? null;
        if (is_string($userAccountId) && '' !== trim($userAccountId)) {
            $builder->field('userAccountId')->equals(trim($userAccountId));
        }

        $status = $filters['status'] ?? null;
        if (is_numeric($status)) {
            $builder->field('status')->equals((int) $status);
        }

        return $builder;
    }

    /**
     * `username` a disparu des champs triables : ce tri passait par une jointure vers la
     * table des comptes utilisateurs, qui vit dans un autre service et une autre base. Il est
     * ignore ici plutot que traduit — aucune donnee de ce service ne permet de le rendre.
     *
     * @param array<string, mixed> $orderBy
     */
    private function applyOrdering(Builder $builder, array $orderBy): void
    {
        $allowedFields = [
            'status' => 'status',
            'createdAt' => 'createdAt',
        ];

        foreach ($orderBy as $field => $direction) {
            if (!isset($allowedFields[$field])) {
                continue;
            }

            $normalizedDirection = 'desc' === strtolower((string) $direction) ? 'desc' : 'asc';

            $builder->sort($allowedFields[$field], $normalizedDirection);
        }

        $builder->sort('id', 'asc');
    }
}
