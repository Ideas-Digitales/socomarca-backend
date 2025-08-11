<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        
        return Product::with('prices')->get()->map(function ($product) {
        
            $price = $product->prices->first();

            return [
                'ID' => $product->id,
                'Nombre' => $product->name,
                'SKU' => $product->sku,
                'Precio' => $price ? $price->price : null,
                'Unidad' => $price ? $price->unit : null,
                'Stock' => $price ? $price->stock : null,
                
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'SKU',
            'Precio',
            'Unidad',
            'Stock',
            
        ];
    }
}