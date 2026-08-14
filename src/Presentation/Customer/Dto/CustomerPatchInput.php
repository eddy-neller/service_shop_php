<?php

declare(strict_types=1);

namespace App\Presentation\Customer\Dto;

use App\Domain\Customer\ValueObject\CustomerStatus;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CustomerPatchInput
{
    #[Groups(['shop_customer:write'])]
    #[Assert\NotNull]
    #[Assert\Choice(
        choices: [CustomerStatus::DISABLED],
        message: 'Only disabling a customer is allowed.'
    )]
    public ?int $status = null;
}
