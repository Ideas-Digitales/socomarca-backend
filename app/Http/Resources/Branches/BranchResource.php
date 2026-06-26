<?php

namespace App\Http\Resources\Branches;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'code' => $this->code,
            'email' => $this->email,
            'commercial_email' => $this->commercial_email,
            'phone' => $this->phone,
            'rut' => $this->rut,
            'business_name' => $this->business_name,
            'user' => $this->whenLoaded('user'),
        ];
    }
}
