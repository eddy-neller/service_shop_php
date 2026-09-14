<?php

declare(strict_types=1);

namespace App\Presentation\Customer\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class AddressPatchInput
{
    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'The name must be at least {{ limit }} characters long.',
        maxMessage: 'The name must be at most {{ limit }} characters long.'
    )]
    public ?string $name = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 32,
        minMessage: 'The firstname must be at least {{ limit }} characters long.',
        maxMessage: 'The firstname must be at most {{ limit }} characters long.'
    )]
    public ?string $firstname = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 32,
        minMessage: 'The lastname must be at least {{ limit }} characters long.',
        maxMessage: 'The lastname must be at most {{ limit }} characters long.'
    )]
    public ?string $lastname = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'The company must be at least {{ limit }} characters long.',
        maxMessage: 'The company must be at most {{ limit }} characters long.'
    )]
    public ?string $company = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 150,
        minMessage: 'The address must be at least {{ limit }} characters long.',
        maxMessage: 'The address must be at most {{ limit }} characters long.'
    )]
    public ?string $address = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 30,
        minMessage: 'The zipcode must be at least {{ limit }} characters long.',
        maxMessage: 'The zipcode must be at most {{ limit }} characters long.'
    )]
    public ?string $zip = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'The city must be at least {{ limit }} characters long.',
        maxMessage: 'The city must be at most {{ limit }} characters long.'
    )]
    public ?string $city = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'The country must be at least {{ limit }} characters long.',
        maxMessage: 'The country must be at most {{ limit }} characters long.'
    )]
    public ?string $country = null;

    #[Groups(['shop_address:write'])]
    #[Assert\Length(
        min: 2,
        max: 30,
        minMessage: 'The phone must be at least {{ limit }} characters long.',
        maxMessage: 'The phone must be at most {{ limit }} characters long.'
    )]
    public ?string $phone = null;
}
