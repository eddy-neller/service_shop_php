<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Event;

trait DomainEventTrait
{
    /** @var DomainEventInterface[] */
    private array $domainEvents = [];

    protected function recordEvent(DomainEventInterface $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * @return DomainEventInterface[]
     */
    public function getDomainEvents(): array
    {
        return $this->domainEvents;
    }

    /**
     * @return DomainEventInterface[]
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->clearDomainEvents();

        return $events;
    }

    public function clearDomainEvents(): void
    {
        $this->domainEvents = [];
    }
}
