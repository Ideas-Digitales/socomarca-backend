<?php

namespace App\Http\Requests\ProductImages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;

class ProductImageSyncStoreRequest extends FormRequest
{
    public function authorize()
    {
        return true; 
    }

    public function rules()
    {
        return [
            'sync_file_path' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (!Storage::disk('s3')->exists($value)) {
                        $fail('El archivo especificado no existe en el almacenamiento en la nube.');
                    }
                },
            ],
        ];
    }
}