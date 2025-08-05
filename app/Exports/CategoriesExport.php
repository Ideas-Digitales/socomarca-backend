<?php

namespace App\Exports;

use App\Models\Category;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CategoriesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Category::all()->map(function ($category) {
            return [
                'ID' => $category->id,
                'Nombre' => $category->name,
                'Descripción' => $category->description,
                'Código' => $category->code,
                'Nivel' => $category->level,
                'Clave' => $category->key,
                // Agrega más campos si lo necesitas
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'Descripción',
            'Código',
            'Nivel',
            'Clave',
            // Agrega más encabezados si agregas más campos
        ];
    }
}