<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomersExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return User::with(['billing_address.municipality.region'])
            ->whereHas('roles', function($q) {
                $q->where('name', 'customer');
            })
            ->get()
            ->map(function ($user) {
                $address = $user->billing_address;
                $municipality = $address ? $address->municipality : null;
                $region = $municipality ? $municipality->region : null;

                return [
                    'ID' => $user->id,
                    'Nombre' => $user->name,
                    'Email' => $user->email,
                    'Dirección' => $address ? $address->address_line1 : null,
                    'Comuna' => $municipality ? $municipality->name : null,
                    'Región' => $region ? $region->name : null,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'Email',
            'Dirección',
            'Comuna',
            'Región',
        ];
    }
}