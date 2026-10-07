<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Permitir a todos buscar FAQs
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Text to look for in the question or the answer, case-insensitive.
             *
             * @example pedido
             */
            'search' => 'nullable|string|min:2|max:255',
            /** @default 20 */
            'per_page' => 'nullable|integer|min:1|max:100',
            /** Conditions that every FAQ must meet. */
            'filters' => 'nullable|array',
            'filters.*.field' => 'nullable|string|in:question,answer',
            /**
             * `LIKE`, `ILIKE` and `NOT LIKE` take the value as a pattern, so add `%` wildcards. `fulltext`
             * looks for the value in both the question and the answer, case-insensitive, whatever the `field`.
             */
            'filters.*.operator' => 'nullable|string|in:=,!=,LIKE,ILIKE,NOT LIKE,fulltext',
            /** @example %pedido% */
            'filters.*.value' => 'nullable|string|max:255',
            /** Sort by `field` in this direction, before the newest-first order. */
            'filters.*.sort' => 'nullable|string|in:ASC,DESC',
        ];
    }
}
