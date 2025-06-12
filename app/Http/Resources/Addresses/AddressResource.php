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
            'type' => $this->type,
            'phone' => $this->phone,
            'contact_name' => $this->contact_name,
            'municipality' => [
                'id' => $this->municipality->id,
                'name' => $this->municipality->name,
                'code' => $this->municipality->code,
                'region' => [
                    'id' => $this->municipality->region->id,
                    'name' => $this->municipality->region->name,
                    'code' => $this->municipality->region->code,
                ]
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
