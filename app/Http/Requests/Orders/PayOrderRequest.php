<?php

namespace App\Http\Requests\Orders;

use App\Enums\PaymentDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_id' => [
                'required',
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    $address = \App\Models\Address::where('id', $value)
                        ->where('user_id', \Illuminate\Support\Facades\Auth::id())
                        ->first();

                    if (!$address) {
                        $fail('La dirección no pertenece al usuario actual.');
                    }
                },
            ],
            'payment_method' => [
                'required',
                'string',
                'exists:payment_methods,code',
            ],
            'branch_id' => [
                'required',
                'id',
                'exists:branches,id',
            ],
            'payment_document_type' => [
                'required',
                Rule::in(PaymentDocumentType::values())
            ]
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('user_id')) {
            $this->merge([
                'user_id' => (int) $this->user_id
            ]);
        }
    }
}
