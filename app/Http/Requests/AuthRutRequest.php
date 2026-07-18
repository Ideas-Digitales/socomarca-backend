<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Override;

class AuthRutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->has('rut')) {
            $rut = $this->input('rut');
            $exists = DB::table('users')
                ->where('rut', $rut)
                ->exists();

            if (!$exists) {
                return false;
            }
        }

        return true;
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
            ],
        ];
    }

    /**
     * Prepare data for validation
     *
     *
     * @return void
     */
    #[Override]
    public function prepareForValidation(): void
    {
        $this->prepareRut();
        parent::prepareForValidation();
    }

    /**
     * RUT pre-processing
     *
     * @return void
     */
    private function prepareRut(): void
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
    }
}
