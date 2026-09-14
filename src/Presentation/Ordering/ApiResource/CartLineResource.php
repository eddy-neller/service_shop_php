<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\ApiResource;

use Symfony\Component\Serializer\Attribute\Groups;

final class CartLineResource
{
    #[Groups(['shop_cart:read'])]
    public string $id;

    #[Groups(['shop_cart:read'])]
    public string $productId;

    #[Groups(['shop_cart:read'])]
    public string $productTitle;

    #[Groups(['shop_cart:read'])]
    public string $productSlug;

    #[Groups(['shop_cart:read'])]
    public ?string $imageUrl = null;

    #[Groups(['shop_cart:read'])]
    public float $unitPrice;

    #[Groups(['shop_cart:read'])]
    public int $quantity;

    #[Groups(['shop_cart:read'])]
    public float $lineTotal;
}
