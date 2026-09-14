<?php

declare(strict_types=1);

namespace App\Presentation\Shared\State;

trait CollectionParameterNormalizerTrait
{
    private function normalizePaginationParameter(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param list<string> $allowedFields
     *
     * @return array<string, 'ASC'|'DESC'>
     */
    private function normalizeOrderBy(mixed $requestedOrder, array $allowedFields): array
    {
        if (!is_array($requestedOrder)) {
            return [];
        }

        $orderBy = [];
        foreach ($allowedFields as $field) {
            $direction = $requestedOrder[$field] ?? null;
            if (!is_string($direction)) {
                continue;
            }

            $direction = strtoupper(trim($direction));
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                continue;
            }

            $orderBy[$field] = $direction;
        }

        return $orderBy;
    }
}
