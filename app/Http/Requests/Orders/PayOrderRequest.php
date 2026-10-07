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
            /**
             * User (Random ERP branch) the order is placed for: the authenticated user, or one of the active
             * secondary branches of its entity when the authenticated user is a primary branch. Defaults to
             * the authenticated user.
             */
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
            /** Delivery address. It must belong to the customer of the order. */
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
            /**
             * Payment method `code`: `random_credit` charges the customer's Random ERP credit line; any other
             * method pays with Webpay.
             *
             * @example transbank
             */
            'payment_method' => [
                'required',
                'string',
                'exists:payment_methods,code',
            ],
            /** Tax document Random ERP issues for the sales note: `invoice` (factura) or `receipt` (boleta). */
            'payment_document_type' => [
                'required',
                Rule::in(PaymentDocumentType::values())
            ],
            /** Notes for the order, forwarded to the Random ERP sales note. */
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
