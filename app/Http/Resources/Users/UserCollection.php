<?php

namespace App\Http\Resources\Users;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     * @scramble-return list<array{id: int, name: string, email: string, phone: string|null, rut: string|null, business_name: string|null, is_active: bool, last_login: string|null, roles: list<string>, created_at: string, updated_at: string}>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'rut' => $user->rut,
                'business_name' => $user->business_name,
                'is_active' => $user->is_active,
                'last_login' => $user->last_login,
                'roles' => $user->roles ? $user->roles->pluck('name') : [],
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];
        })->toArray();
    }
}
