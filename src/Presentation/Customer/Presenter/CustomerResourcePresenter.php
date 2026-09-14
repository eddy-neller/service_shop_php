<?php

declare(strict_types=1);

namespace App\Presentation\Customer\Presenter;

use App\Application\Customer\ReadModel\CustomerItem;
use App\Presentation\Customer\ApiResource\CustomerResource;

final readonly class CustomerResourcePresenter
{
    public function __construct(
        private AddressResourcePresenter $addressResourcePresenter,
    ) {
    }

    public function toResource(CustomerItem $customerItem): CustomerResource
    {
        $resource = $this->mapCustomer($customerItem);

        $resource->nbAddress = count($customerItem->addresses);

        foreach ($customerItem->addresses as $address) {
            $resource->addresses[] = $this->addressResourcePresenter->toResource($address);
        }

        return $resource;
    }

    public function toSummaryResource(CustomerItem $customer): CustomerResource
    {
        return $this->mapCustomer($customer);
    }

    /**
     * Flat mapping to prevent addresses queries in list/get payloads.
     */
    private function mapCustomer(CustomerItem $customer): CustomerResource
    {
        $resource = new CustomerResource();

        $resource->id = $customer->id;
        $resource->userAccountId = $customer->userAccountId ?? '';
        $resource->status = $customer->status;
        $resource->createdAt = $customer->createdAt;
        $resource->updatedAt = $customer->updatedAt;

        return $resource;
    }
}
