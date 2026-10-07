<?php

namespace App\Http\Requests\Orders;

use App\Enums\PaymentDocumentType;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
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
            'customer_id' => [
                'sometimes',
                'integer',
                function ($attribute, $value, $fail) {
                    $customer = User::find($value);

                    if (!$customer || !$this->user()->can('placeFor', [Order::class, $customer])) {
                        $fail('No puede emitir pedidos para el cliente indicado.');
                    }
                },
            ],
            'address_id' => [
                'required',
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    $belongsToCustomer = Address::where('id', $value)
                        ->where('user_id', $this->customerId())
                        ->exists();

                    if (!$belongsToCustomer) {
                        $fail('La dirección no pertenece al cliente del pedido.');
                    }
                },
            ],
            'payment_method' => [
                'required',
                'string',
                'exists:payment_methods,code',
            ],
            'payment_document_type' => [
                'required',
                Rule::in(PaymentDocumentType::values())
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
            ]
        ];
    }

    /**
     * User the order is placed for: the requested customer, or the authenticated user.
     */
    public function customer(): User
    {
        return $this->has('customer_id')
            ? User::findOrFail($this->integer('customer_id'))
            : $this->user();
    }

    private function customerId(): int
    {
        return filter_var($this->input('customer_id'), FILTER_VALIDATE_INT) ?: $this->user()->id;
    }

    protected function prepareForValidation()
    {
        if ($this->has('user_id')) {
            $this->merge([
                'user_id' => (int) $this->user_id
            ]);
        }

        if (!$this->has('notes')) {
            $this->merge(['notes' => '']);
        }
    }
}
