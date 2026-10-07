<?php

namespace App\Http\Resources\Users;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return
        [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'phone' => $this->phone,
            'rut' => $this->rut,
            'business_name' => $this->business_name,
            'is_active' => $this->is_active,
            'is_synced' => $this->isSyncedFromRandom(),
            'branch_type' => $this->branch_type,
            'last_login' => $this->last_login,
            'password_changed_at' => $this->password_changed_at,
            'billing_address' => $this->billing_address,
            'shipping_addresses' => $this->shipping_addresses,
            'roles' => $this->roles ? $this->roles->pluck('name') : [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
