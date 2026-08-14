<?php

declare(strict_types=1);

namespace App\Presentation\Customer\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CustomerPostInput
{
    #[Groups(['shop_customer:write'])]
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $userAccountId;
}
