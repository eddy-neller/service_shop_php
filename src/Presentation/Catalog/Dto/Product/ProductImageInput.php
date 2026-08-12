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
    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['image/png', 'image/gif', 'image/jpeg', 'image/pjpeg'],
    )]
    #[Assert\Image(
        minWidth: 200,
        maxWidth: 2000,
        maxHeight: 2000,
        minHeight: 200,
    )]
    public ?UploadedFile $imageFile = null;
}
