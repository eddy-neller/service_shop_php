<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Ordering;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart as DomainCart;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Infrastructure\Adapter\Uuid\UuidGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

final readonly class MongoCartRepository implements CartRepositoryInterface
{
    public function __construct(
        private DocumentManager $documentManager,
        private UuidGeneratorInterface $uuidGenerator,
        private CartMapper $mapper,
    ) {
    }

    public function nextIdentity(): CartId
    {
        return CartId::fromString($this->uuidGenerator->generate());
    }

    public function nextLineIdentity(): CartLineId
    {
        return CartLineId::fromString($this->uuidGenerator->generate());
    }

    public function findByOwner(CustomerId $ownerId): ?DomainCart
    {
        $document = $this->findDocument($ownerId);

        return null === $document ? null : $this->mapper->toDomain($document);
    }

    /**
     * Ne flushe pas : `MongoTransactional` s'en charge. Les lignes partent avec le panier,
     * dans la meme ecriture de document.
     */
    public function save(DomainCart $cart): void
    {
        $document = $this->mapper->toDocument($cart, $this->findDocument($cart->getOwnerId()));

        $this->documentManager->persist($document);
    }

    private function findDocument(CustomerId $ownerId): ?CartDocument
    {
        $document = $this->documentManager
            ->getRepository(CartDocument::class)
            ->findOneBy(['customerId' => $ownerId->toString()]);

        return $document instanceof CartDocument ? $document : null;
    }
}
