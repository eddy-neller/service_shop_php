<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence\Customer;

use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Exception\ConcurrentModificationException;
use App\Tests\Infrastructure\Integration\Persistence\MongoPersistenceTestCase;
use DateTimeImmutable;
use Throwable;

/**
 * Ce que seul un vrai MongoDB peut prouver : que les adresses embarquees font l'aller-retour
 * sans perte, que les deux index tiennent leurs promesses, et que le verrou optimiste rejette
 * bien l'ecriture perdante.
 *
 * Les tests d'unite ne voient rien de tout cela : ils manipulent un agregat en memoire.
 */
final class CustomerRepositoryTest extends MongoPersistenceTestCase
{
    public function testAddressesSurviveTheRoundTrip(): void
    {
        $customer = $this->aCustomer();
        $this->addAddress($customer, 'Domicile', new DateTimeImmutable('2025-01-01 10:00:00'));
        $this->addAddress($customer, 'Bureau', new DateTimeImmutable('2025-02-01 10:00:00'));

        $this->transactional->transactional(function () use ($customer): void {
            $this->customers->save($customer);
        });
        $this->documentManager->clear();

        $reloaded = $this->customers->findById($customer->getId());

        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getAddresses());
        self::assertSame('Domicile', $reloaded->getAddresses()[0]->getLabel());
        self::assertTrue($reloaded->getAddresses()[0]->isDefault());
        self::assertFalse($reloaded->getAddresses()[1]->isDefault());
        self::assertSame(
            $customer->getId()->toString(),
            $reloaded->getAddresses()[0]->getOwnerId()->toString(),
        );
    }

    /**
     * Le mapper reecrit les adresses integralement plutot que de les reconcilier. Ce test
     * verifie que la suppression est bien repercutee : un rapprochement partiel laisserait
     * l'ancienne ligne dans le document.
     */
    public function testRemovingAnAddressIsPersisted(): void
    {
        $customer = $this->aCustomer();
        $first = AddressId::fromString($this->customers->nextAddressIdentity()->toString());
        $this->addAddress($customer, 'Domicile', new DateTimeImmutable('2025-01-01 10:00:00'), $first);
        $this->addAddress($customer, 'Bureau', new DateTimeImmutable('2025-02-01 10:00:00'));

        $this->transactional->transactional(function () use ($customer): void {
            $this->customers->save($customer);
        });

        $customer->removeAddress($first, new DateTimeImmutable('2025-03-01 10:00:00'));
        $this->transactional->transactional(function () use ($customer): void {
            $this->customers->save($customer);
        });
        $this->documentManager->clear();

        $reloaded = $this->customers->findById($customer->getId());

        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getAddresses());
        self::assertSame('Bureau', $reloaded->getAddresses()[0]->getLabel());
        // La promotion du remplacant doit avoir survecu elle aussi.
        self::assertTrue($reloaded->getAddresses()[0]->isDefault());
    }

    public function testTwoCustomersCannotShareAUserAccount(): void
    {
        $accountId = UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011');

        $this->transactional->transactional(function () use ($accountId): void {
            $this->customers->save(Customer::create(
                $this->customers->nextIdentity(),
                new DateTimeImmutable('2025-01-01 10:00:00'),
                $accountId,
            ));
        });

        $failed = null;

        try {
            $this->transactional->transactional(function () use ($accountId): void {
                $this->customers->save(Customer::create(
                    $this->customers->nextIdentity(),
                    new DateTimeImmutable('2025-01-02 10:00:00'),
                    $accountId,
                ));
            });
        } catch (Throwable $exception) {
            $failed = $exception;
        }

        self::assertInstanceOf(Throwable::class, $failed);
        self::assertSame(1, $this->countIn('customer', ['userAccountId' => $accountId->toString()]));
    }

    /**
     * `sparse: true` sur l'index unique n'est pas cosmetique : `userAccountId` est nullable,
     * et sans lui le **second** client cree sans compte serait rejete sur une cle `null`
     * dupliquee. Aucun autre test n'emprunte ce chemin.
     */
    public function testSeveralCustomersMayExistWithoutAUserAccount(): void
    {
        $this->transactional->transactional(function (): void {
            $this->customers->save(Customer::create(
                $this->customers->nextIdentity(),
                new DateTimeImmutable('2025-01-01 10:00:00'),
            ));
            $this->customers->save(Customer::create(
                $this->customers->nextIdentity(),
                new DateTimeImmutable('2025-01-02 10:00:00'),
            ));
        });

        self::assertSame(2, $this->countIn('customer', ['userAccountId' => null]));
    }

    /**
     * Le garde-fou de l'imbrication.
     *
     * Deux ecrivains concurrents produisent chacun un document valide d'au plus cinq
     * adresses : sans verrou optimiste, le dernier ecraserait l'autre **sans erreur**, et
     * une adresse disparaitrait en silence. Retirer `#[MongoDB\Version]` de
     * `CustomerDocument` fait passer ce seul test au rouge.
     *
     * La concurrence est simulee par une ecriture **directe** dans la collection, et non par
     * un second `findById()` : `save()` relit toujours le document avant d'y reporter
     * l'agregat, donc dans un meme `DocumentManager` la peremption est invisible. Ce que le
     * verrou protege reellement, c'est l'intervalle entre la lecture de **ce** processus et
     * son flush — pendant lequel un autre processus a commite.
     */
    public function testAWriteLosingTheRaceIsRejectedAsAConflict(): void
    {
        $customer = $this->aCustomer();
        $this->transactional->transactional(function () use ($customer): void {
            $this->customers->save($customer);
        });
        $this->documentManager->clear();

        // Ce processus lit l'agregat : le document est desormais gere, a sa version courante.
        $mine = $this->customers->findById($customer->getId());
        self::assertNotNull($mine);

        // Un autre processus commite entre-temps, hors de portee de l'ODM.
        $this->database()->selectCollection('customer')->updateOne(
            ['_id' => $customer->getId()->toString()],
            ['$inc' => ['version' => 1], '$set' => ['status' => 2]],
        );

        $this->addAddress($mine, 'Domicile', new DateTimeImmutable('2025-02-01 10:00:00'));

        $failed = null;

        try {
            $this->transactional->transactional(function () use ($mine): void {
                $this->customers->save($mine);
            });
        } catch (Throwable $exception) {
            $failed = $exception;
        }

        self::assertInstanceOf(ConcurrentModificationException::class, $failed);
        // L'ecriture de l'autre processus n'a pas ete ecrasee.
        self::assertSame(1, $this->countIn('customer', ['status' => 2]));
    }

    public function testFindByUserAccountIdResolvesTheCustomer(): void
    {
        $accountId = UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011');
        $customer = Customer::create(
            $this->customers->nextIdentity(),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            $accountId,
        );

        $this->transactional->transactional(function () use ($customer): void {
            $this->customers->save($customer);
        });
        $this->documentManager->clear();

        $found = $this->customers->findByUserAccountId($accountId);

        self::assertNotNull($found);
        self::assertSame($customer->getId()->toString(), $found->getId()->toString());
        self::assertNull($this->customers->findByUserAccountId(
            UserAccountId::fromString('550e8400-e29b-41d4-a716-4466554400ff'),
        ));
    }

    private function aCustomer(): Customer
    {
        return Customer::create(
            CustomerId::fromString($this->customers->nextIdentity()->toString()),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );
    }

    private function addAddress(
        Customer $customer,
        string $label,
        DateTimeImmutable $now,
        ?AddressId $addressId = null,
    ): void {
        $customer->addAddress(
            addressId: $addressId ?? $this->customers->nextAddressIdentity(),
            label: $label,
            firstname: 'John',
            lastname: 'Doe',
            street: '1 rue de la Paix',
            zipCode: '75000',
            city: 'Paris',
            country: 'France',
            phone: '0102030405',
            now: $now,
        );
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function countIn(string $collection, array $filter): int
    {
        return $this->database()->selectCollection($collection)->countDocuments($filter);
    }
}
