<?php

namespace App\Http\Resources\Addresses;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
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
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'is_default' => $this->is_default,
            /** @var 'billing'|'shipping' */
            'type' => $this->type,
            'phone' => $this->phone,
            'contact_name' => $this->contact_name,
            'municipality_name' => $this->municipality->name,    
            'region_name' => $this->municipality->region->name,
            'alias' => $this->alias,
            /**
             * Whether the address is managed by the Random ERP sync. Synced addresses cannot be updated or
             * deleted (`403`).
             */
            'is_synced' => (bool) $this->is_synced,
        ];
    }
}
