<?php

declare(strict_types=1);

namespace App\Presentation\Customer\Presenter;

use App\Application\Customer\ReadModel\AddressItem;
use App\Presentation\Customer\ApiResource\AddressResource;

final readonly class AddressResourcePresenter
{
    public function toResource(AddressItem $address): AddressResource
    {
        $resource = new AddressResource();

        $resource->id = $address->id;
        $resource->name = $address->name;
        $resource->firstname = $address->firstname;
        $resource->lastname = $address->lastname;
        $resource->company = $address->company;
        $resource->address = $address->address;
        $resource->zip = $address->zip;
        $resource->city = $address->city;
        $resource->country = $address->country;
        $resource->phone = $address->phone;
        $resource->isDefault = $address->isDefault;
        $resource->createdAt = $address->createdAt;
        $resource->updatedAt = $address->updatedAt;

        return $resource;
    }
}
