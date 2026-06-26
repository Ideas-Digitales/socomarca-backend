<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Override;

class AuthRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {

        return true;
    }

    #[Override]
    public function prepareForValidation()
    {
        $rut = $this->input('rut');

        if (empty($rut)) return;

        if (DB::table('users')->where('rut', $rut)->exists()) {
            return;
        }

        $rut = \Laragear\Rut\Rut::parse($rut);

        if ($rut->isValid()) {
            $rutNumber = $rut->num;
            if (
                DB::table('users')
                    ->where('rut', $rutNumber)
                    ->exists()
            ) {
                $this->merge([
                    'rut' => $rutNumber
                ]);
                return;
            }
            $rutInRaw = $rut->format(\Laragear\Rut\RutFormat::Raw);
            if (
                DB::table('users')
                    ->where('rut', $rutInRaw)
                    ->exists()
            ) {
                $this->merge([
                    'rut' => $rutInRaw,
                ]);
                return;
            }

            $rutInBasicFormat = $rut->format(\Laragear\Rut\RutFormat::Basic);
            if (
                DB::table('users')
                    ->where('rut', $rutInBasicFormat)
                    ->exists()
            ) {
                $this->merge([
                    'rut' => $rutInBasicFormat,
                ]);
                return;
            }

            $rutInStrictFormat = $rut->format(\Laragear\Rut\RutFormat::Basic);
            if (
                DB::table('users')
                    ->where('rut', $rutInStrictFormat)
                    ->exists()
            ) {
                $this->merge([
                    'rut' => $rutInStrictFormat,
                ]);
                return;
            }
        }

        return parent::prepareForValidation();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        return [
            'rut' => [
                'required',
                'exists:users,rut',
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Error de validación',
            'errors' => $validator->errors()
        ], 422));
    }

    protected function passedValidation()
    {
        $user = User::where('rut', $this->input('rut'))->first();

        if (!$user) {
            abort(401, 'Usuario no encontrado');
        }

        $isPasswordValid = Hash::check(
            $this->input('password'),
            $user->password
        );

        if (!$user || !$isPasswordValid || !$user->is_active) {
            abort(401, 'Unauthorized');
        }

        $this->merge(['auth_user' => $user]); // Authenticated user merged into request
    }
}
