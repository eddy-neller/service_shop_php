<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\Dto\Cart;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CartLinePatchInput
{
    #[Groups(['shop_cart:write'])]
    #[Assert\Range(min: 0, max: 99)]
    public int $quantity;
}
