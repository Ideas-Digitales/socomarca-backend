<?php

namespace App\Http\Requests\Orders;

use App\Enums\PaymentDocumentType;
use App\Models\Address;
use App\Models\Contact;
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
             * User (Random ERP branch) the order ships to: the authenticated user, or one of the active
             * secondary branches of its entity when the authenticated user is a primary branch. Defaults to
             * the authenticated user. The authenticated user pays and is billed either way.
             */
            'customer_id' => [
                'sometimes',
                'integer',
                function ($attribute, $value, $fail) {
                    $customer = User::find($value);

                    if (! $customer || ! $this->user()->can('placeFor', [Order::class, $customer])) {
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

                    if (! $belongsToCustomer) {
                        $fail('La dirección no pertenece al cliente del pedido.');
                    }
                },
            ],
            /**
             * Optional contact person of a branch the authenticated user manages. It must be authorized.
             * When present, its RUT is sent to Random ERP as `rutContacto` on the sales note. Omit it to
             * check out without a contact.
             */
            'contact_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:contacts,id',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $contact = Contact::query()->find($value);

                    if (! $contact || ! $this->user()->canUseContact($contact)) {
                        $fail('El contacto no pertenece a tu sucursal.');

                        return;
                    }

                    if (! $contact->authorized) {
                        $fail('El contacto no está autorizado.');
                    }
                },
            ],
            /**
             * Payment method `code`: `random_credit` charges the authenticated user's Random ERP credit line; any other
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
                Rule::in(PaymentDocumentType::values()),
            ],
            /** Notes for the order, forwarded to the Random ERP sales note. */
            'notes' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }

    /**
     * User the order ships to: the requested customer, or the authenticated user.
     */
    public function customer(): User
    {
        return $this->has('customer_id')
            ? User::findOrFail($this->integer('customer_id'))
            : $this->user();
    }

    /**
     * Contact person whose RUT is written on the Random ERP sales note, or null when the
     * checkout does not name one.
     */
    public function contact(): ?Contact
    {
        if (! $this->filled('contact_id')) {
            return null;
        }

        return Contact::query()->findOrFail($this->integer('contact_id'));
    }

    private function customerId(): int
    {
        return filter_var($this->input('customer_id'), FILTER_VALIDATE_INT) ?: $this->user()->id;
    }

    protected function prepareForValidation()
    {
        if ($this->has('user_id')) {
            $this->merge([
                'user_id' => (int) $this->user_id,
            ]);
        }

        if (! $this->has('notes')) {
            $this->merge(['notes' => '']);
        }
    }
}
