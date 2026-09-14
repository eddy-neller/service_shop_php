<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\EventListener;

use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class LastModifiedListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (
            !$request->isMethodCacheable()
            || !$response->isSuccessful()
            || $response->headers->has('Last-Modified')
        ) {
            return;
        }

        $data = $request->attributes->get('data');
        if (null === $data) {
            return;
        }

        $lastModified = $this->resolveLastModified($data);
        if (null === $lastModified) {
            return;
        }

        $response->setLastModified($lastModified);
        $response->isNotModified($request);
    }

    private function resolveLastModified(mixed $data): ?DateTimeImmutable
    {
        if (is_iterable($data)) {
            $latest = null;

            foreach ($data as $item) {
                $itemDate = $this->extractDate($item);
                if (null === $itemDate) {
                    continue;
                }

                if (null === $latest || $itemDate->getTimestamp() > $latest->getTimestamp()) {
                    $latest = $itemDate;
                }
            }

            return $latest;
        }

        return $this->extractDate($data);
    }

    private function extractDate(mixed $item): ?DateTimeImmutable
    {
        if ($item instanceof DateTimeImmutable) {
            return $item;
        }

        if (is_array($item)) {
            return $this->firstDate($item['updatedAt'] ?? null, $item['createdAt'] ?? null);
        }

        if (is_object($item)) {
            return $this->extractObjectDate($item);
        }

        return null;
    }

    private function extractObjectDate(object $item): ?DateTimeImmutable
    {
        foreach (['getUpdatedAt', 'getCreatedAt'] as $method) {
            if (method_exists($item, $method)) {
                $date = $item->{$method}();
                if ($date instanceof DateTimeImmutable) {
                    return $date;
                }
            }
        }

        $publicVars = get_object_vars($item);

        return $this->firstDate($publicVars['updatedAt'] ?? null, $publicVars['createdAt'] ?? null);
    }

    private function firstDate(mixed ...$values): ?DateTimeImmutable
    {
        foreach ($values as $value) {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
        }

        return null;
    }
}
