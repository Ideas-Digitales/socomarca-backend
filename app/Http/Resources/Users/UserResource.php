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
            /** Lowercase email. */
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'phone' => $this->phone,
            'rut' => $this->rut,
            'business_name' => $this->business_name,
            /**
             * Inactive users cannot log in.
             *
             * @var bool
             */
            'is_active' => $this->is_active,
            /** Whether the user is synced from Random ERP; its data can't be edited, only `is_active`. */
            'is_synced' => $this->isSyncedFromRandom(),
            /**
             * Random ERP branch type: `P` primary, `S` secondary; `null` for internal users.
             *
             * @var 'P'|'S'|null
             */
            'branch_type' => $this->branch_type,
            /**
             * Date and time of the last login.
             *
             * @var string|null
             * @format date-time
             */
            'last_login' => $this->last_login,
            /**
             * When the user last changed the password; `null` while a temporary or initial password must be changed.
             *
             * @var string|null
             * @format date-time
             */
            'password_changed_at' => $this->password_changed_at,
            /** @var \App\Models\Address|null */
            'billing_address' => $this->billing_address,
            'shipping_addresses' => $this->shipping_addresses,
            /** @var list<string> */
            'roles' => $this->roles ? $this->roles->pluck('name') : [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
