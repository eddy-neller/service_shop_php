<?php

declare(strict_types=1);

namespace App\Application\Shared\ReadModel;

final readonly class Pagination
{
    private const int DEFAULT_PAGE = 1;

    private const int DEFAULT_ITEMS_PER_PAGE = 30;

    private function __construct(
        public int $page,
        public int $itemsPerPage,
    ) {
    }

    public static function fromRaw(mixed $page, mixed $itemsPerPage): self
    {
        $pageValue = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $itemsValue = filter_var($itemsPerPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return new self(
            page: false === $pageValue ? self::DEFAULT_PAGE : (int) $pageValue,
            itemsPerPage: false === $itemsValue ? self::DEFAULT_ITEMS_PER_PAGE : (int) $itemsValue,
        );
    }

    public static function fromValues(int $page, int $itemsPerPage): self
    {
        $normalizedPage = $page > 0 ? $page : self::DEFAULT_PAGE;
        $normalizedItemsPerPage = $itemsPerPage > 0 ? $itemsPerPage : self::DEFAULT_ITEMS_PER_PAGE;

        return new self(
            page: $normalizedPage,
            itemsPerPage: $normalizedItemsPerPage,
        );
    }
}
