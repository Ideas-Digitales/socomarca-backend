<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Rules\ValidateRut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Chilean RUT of the contact. Stored without dots (`12345678-5`).
             *
             * @example 86027191-5
             */
            'rut' => [
                'bail',
                'required',
                'string',
                'max:12',
                new ValidateRut,
                Rule::unique('contacts', 'rut')->where('entity_code', $this->entityCode()),
            ],
            /** @example Samuel Alderete Rayún */
            'name' => ['bail', 'required', 'string', 'max:255'],
            /** @example salderete@ideasdigitales.cl */
            'email' => ['bail', 'required', 'email', 'max:255'],
            /**
             * Phone, with or without the country code.
             *
             * @example +5215656780748
             */
            'phone' => ['bail', 'required', 'string', 'max:30'],
            /**
             * Branch user the contact belongs to. When omitted, the contact belongs to the
             * authenticated user. A primary branch may send its own id or the id of one of its
             * secondary branches.
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
                        return;
                    }

                    $owner = User::query()->find($value);
                    if (! $owner || ! $this->user()?->managesBranchUser($owner)) {
                        $fail('La sucursal no pertenece a tu entidad.');
                    }
                },
            ],
            /** Whether the contact is authorized in Random ERP. */
            'authorized' => ['bail', 'required', 'boolean'],
            /**
             * Address (`DIRECON`), 50 characters at most.
             *
             * @example San Francisco 22, Los Héroes Coacalco
             */
            'address' => ['bail', 'required', 'string', 'max:50'],
        ];
    }

    public function owner(): ?User
    {
        $actor = $this->user();

        if (! $actor) {
            return null;
        }

        if (! $this->filled('user_id')) {
            return $actor;
        }

        return User::query()->find($this->integer('user_id'));
    }

    public function entityCode(): string
    {
        return trim((string) $this->owner()?->user_code);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->entityCode() === '') {
                $validator->errors()->add('entity_code', 'El usuario no tiene una entidad de Random.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('rut')) {
            $merge['rut'] = Contact::normalizeRut((string) $this->input('rut'));
        }

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
