<?php

namespace App\Http\Resources\Branches;

use App\Http\Resources\Addresses\AddressResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch of a Random entity (entity + branch), synced as a user.
 */
class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * User ID of the branch; send it as `customer_id` to order for this branch.
             *
             * @var int
             */
            'id' => $this->id,
            /** @var string */
            'name' => $this->name,
            /**
             * Commercial email (Random ERP `EMAILCOMER`), used to log in.
             *
             * @var string|null
             */
            'email' => $this->email,
            /**
             * Billing email (Random ERP `EMAIL`).
             *
             * @var string|null
             */
            'billing_email' => $this->billing_email,
            /** @var string|null */
            'phone' => $this->phone,
            /** @var string|null */
            'rut' => $this->rut,
            /** @var string|null */
            'business_name' => $this->business_name,
            /**
             * Random ERP entity code (`KOEN`), shared by all branches of the entity.
             *
             * @var string|null
             */
            'user_code' => $this->user_code,
            /**
             * Random ERP branch code (`SUEN`).
             *
             * @var string|null
             */
            'branch_code' => $this->branch_code,
            /**
             * Random ERP branch type: `P` primary, `S` secondary; `null` for internal users.
             *
             * @var 'P'|'S'|null
             */
            'branch_type' => $this->branch_type,
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
        ];
    }
}
