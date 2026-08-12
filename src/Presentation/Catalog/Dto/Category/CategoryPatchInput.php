<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\Dto\Category;

use App\Presentation\Catalog\ApiResource\CategoryResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CategoryPatchInput
{
    #[Groups(['shop_category:write'])]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'The title must be at least {{ limit }} characters long.',
        maxMessage: 'The title must be at most {{ limit }} characters long.'
    )]
    public ?string $title = null;

    #[Groups(['shop_category:write'])]
    #[Assert\Length(
        min: 2,
        max: 1000,
        minMessage: 'The description must be at least {{ limit }} characters long.',
        maxMessage: 'The description must be at most {{ limit }} characters long.'
    )]
    public ?string $description = null;

    #[Groups(['shop_category:write'])]
    public ?CategoryResource $parent = null;
}
