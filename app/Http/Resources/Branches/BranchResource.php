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
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'billing_email' => $this->billing_email,
            'phone' => $this->phone,
            'rut' => $this->rut,
            'business_name' => $this->business_name,
            'user_code' => $this->user_code,
            'branch_code' => $this->branch_code,
            'branch_type' => $this->branch_type,
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
        ];
    }
}
