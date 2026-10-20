<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = strtolower($this->method()) === 'put' ? 'required' : 'sometimes';

        return [
            /**
             * The RUT identifies the contact in Random ERP and cannot change.
             * Sending the current RUT is accepted; a different one is rejected.
             */
            'rut' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $contact = $this->route('contact');
                    if (! $contact instanceof Contact) {
                        return;
                    }

                    if (Contact::normalizeRut((string) $value) !== $contact->rut) {
                        $fail('El RUT del contacto no se puede modificar.');
                    }
                },
            ],
            /** @example Samuel Alderete Rayún */
            'name' => ['bail', $required, 'string', 'max:255'],
            /** @example salderete@ideasdigitales.cl */
            'email' => ['bail', $required, 'email', 'max:255'],
            /**
             * Phone, with or without the country code.
             *
             * @example +5215656780748
             */
            'phone' => ['bail', $required, 'string', 'max:30'],
            /**
             * Branch user the contact belongs to. When omitted, the contact keeps its branch.
             * A primary branch may send its own id or the id of one of its secondary branches.
             *
             * @example 42
             */
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        $fail('La sucursal es obligatoria.');

                        return;
                    }

                    $owner = User::query()->find($value);
                    if (! $owner || ! $this->user()?->managesBranchUser($owner)) {
                        $fail('La sucursal no pertenece a tu entidad.');
                    }
                },
            ],
            /** Whether the contact is authorized in Random ERP. */
            'authorized' => ['bail', $required, 'boolean'],
            /**
             * Address (`DIRECON`), 50 characters at most.
             *
             * @example San Francisco 22, Los Héroes Coacalco
             */
            'address' => ['bail', $required, 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('email')) {
            $merge['email'] = User::normalizeEmail((string) $this->input('email'));
        }

        if ($this->has('address')) {
            $merge['address'] = trim((string) $this->input('address'));
        }

        if ($this->has('phone')) {
            $merge['phone'] = trim((string) $this->input('phone'));
        }

        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }

        $this->merge($merge);
    }
}
