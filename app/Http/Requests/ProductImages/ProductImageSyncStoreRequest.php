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
            /**
             * `path` returned by Create a product images upload URL. The ZIP must already be uploaded.
             *
             * @example product-sync/6703f1a2b4c5d.zip
             */
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