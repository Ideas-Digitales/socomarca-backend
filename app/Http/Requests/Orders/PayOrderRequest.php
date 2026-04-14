<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

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
            'payment_method' => ['required', 'string', 'in:webpay,credit_line'],
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
