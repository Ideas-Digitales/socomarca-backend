<?php

namespace App\Http\Resources\Contacts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Random ERP entity code (KOEN) the contact belongs to. */
            'entity_code' => $this->entity_code,
            /** Chilean RUT, without dots. Sent to Random ERP as `rutContacto`. */
            'rut' => $this->rut,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'fax' => $this->fax,
            'position' => $this->position,
            'area' => $this->area,
            /** Branch user this contact belongs to. */
            'user_id' => $this->user_id,
            /**
             * Branch of the contact. `branch_type` `P` is the primary branch and `S` is a secondary
             * branch. The buyer compares `user_id` with their own id to tell their contacts from
             * those of a secondary branch.
             *
             * @var array{id: int, name: string, branch_code: string|null, branch_type: string|null}|null
             */
            'branch' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'branch_code' => $this->user->branch_code,
                'branch_type' => $this->user->branch_type,
            ],
            'authorized' => (bool) $this->authorized,
            /** Address (`DIRECON`), 50 characters at most when written from this API. */
            'address' => $this->address,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
