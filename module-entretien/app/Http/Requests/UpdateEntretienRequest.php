<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEntretienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categorie'      => ['sometimes', 'string'],
            'description'    => ['sometimes', 'string'],
            'date_entretien' => ['sometimes', 'date'],
            'kilometrage'    => ['sometimes', 'integer', 'min:0'],
            'cout'           => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $entretien = $this->route('entretien');
            $cat = $this->input('categorie');

            if ($entretien && is_string($cat)
                && ! array_key_exists($cat, (array) config("entretien.types.{$entretien->type}", []))) {
                $v->errors()->add('categorie', "La categorie {$cat} ne correspond pas au type {$entretien->type}.");
            }
        });
    }
}
