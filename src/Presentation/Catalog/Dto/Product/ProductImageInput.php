<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\Dto\Product;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ProductImageInput
{
    #[Groups(['shop_product:write'])]
    #[Assert\NotBlank]
    public ?UploadedFile $imageFile = null;
}
