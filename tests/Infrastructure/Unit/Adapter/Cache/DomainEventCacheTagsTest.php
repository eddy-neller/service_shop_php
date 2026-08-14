<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Adapter\Cache;

use App\Domain\Catalog\Event\Product\ProductRepricedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\Event\Address\AddressAddedEvent;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\Ordering\Event\Cart\CartLineAddedEvent;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Infrastructure\Adapter\Cache\DomainEventCacheTags;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * `DomainEventCacheTags` decide seule de ce qu'une ecriture perime. Une erreur ici ne casse
 * rien visiblement : elle sert simplement des lectures perimees, ou purge trop large.
 */
final class DomainEventCacheTagsTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private const string ADDRESS_ID = '550e8400-e29b-41d4-a716-446655440020';

    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440030';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440040';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440041';

    private DomainEventCacheTags $tags;

    protected function setUp(): void
    {
        $this->tags = new DomainEventCacheTags();
    }

    /**
     * Les deux collections partent ensemble parce que les read models se citent : un produit
     * reprice change la liste des produits, et `CategoryItem` porte `nbProduct`.
     */
    public function testACatalogFactPurgesBothCollections(): void
    {
        $event = new ProductRepricedEvent(
            ProductId::fromString(self::PRODUCT_ID),
            CategoryId::fromString(self::CATEGORY_ID),
            Money::fromEuros(12.5),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame(
            ['categories-collection', 'products-collection'],
            $this->tags->forEvent($event),
        );
    }

    /**
     * Le tag qui compte : `customer-of-user-{id}` porte la traduction jeton -> client, cachee
     * 24 h et rejouee a chaque requete `/me`. Sans lui, un client provisionne resterait
     * introuvable pendant une journee.
     */
    public function testACustomerFactPurgesTheAccountTranslation(): void
    {
        $event = new CustomerCreatedEvent(
            CustomerId::fromString(self::CUSTOMER_ID),
            UserAccountId::fromString(self::ACCOUNT_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame(
            ['customer-' . self::CUSTOMER_ID, 'customer-of-user-' . self::ACCOUNT_ID],
            $this->tags->forEvent($event),
        );
    }

    /**
     * Un client sans compte n'a pas de traduction a purger.
     *
     * L'`assertSame` est volontairement exact : il echouerait aussi si `array_filter` avait
     * laisse un trou d'index (`[1 => …]` n'est pas identique a `[0 => …]`), ce que
     * `invalidateTags()` recevrait alors sous une forme inattendue.
     */
    public function testACustomerWithoutAccountOnlyPurgesItsOwnTag(): void
    {
        $event = new CustomerCreatedEvent(
            CustomerId::fromString(self::CUSTOMER_ID),
            null,
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame(['customer-' . self::CUSTOMER_ID], $this->tags->forEvent($event));
    }

    public function testAnAddressFactIsTreatedAsACustomerFact(): void
    {
        $event = new AddressAddedEvent(
            CustomerId::fromString(self::CUSTOMER_ID),
            UserAccountId::fromString(self::ACCOUNT_ID),
            AddressId::fromString(self::ADDRESS_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame(
            ['customer-' . self::CUSTOMER_ID, 'customer-of-user-' . self::ACCOUNT_ID],
            $this->tags->forEvent($event),
        );
    }

    /**
     * Le panier se lit par son proprietaire, jamais par son identifiant : c'est donc
     * `ownerId` qui porte le tag.
     */
    public function testACartFactPurgesTheOwnerTag(): void
    {
        $event = new CartLineAddedEvent(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            ProductId::fromString(self::PRODUCT_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame(['cart-of-' . self::CUSTOMER_ID], $this->tags->forEvent($event));
    }

    /**
     * Un evenement d'un contexte inconnu ne purge rien : purger large « au cas ou » viderait
     * le cache a chaque ecriture et le rendrait inutile.
     */
    public function testAnUnknownEventPurgesNothing(): void
    {
        $event = new class implements DomainEventInterface {
            use DomainEventIdentityTrait;

            public function __construct()
            {
                $this->eventId = self::generateEventId();
            }

            public function aggregateId(): string
            {
                return 'irrelevant';
            }

            public function occurredOn(): DateTimeImmutable
            {
                return new DateTimeImmutable('2025-01-01 10:00:00');
            }

            public function eventName(): string
            {
                return 'shop.unknown.thing.happened';
            }
        };

        self::assertSame([], $this->tags->forEvent($event));
    }
}
