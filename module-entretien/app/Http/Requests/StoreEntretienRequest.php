<?php

namespace App\Http\Requests;

use App\Enums\TypeEntretien;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntretienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicule_id'    => ['required', 'integer', 'min:1'],
            'type'           => ['required', Rule::enum(TypeEntretien::class)],
            'categorie'      => ['required', 'string'],
            'description'    => ['required', 'string'],
            'date_entretien' => ['required', 'date'],
            'kilometrage'    => ['required', 'integer', 'min:0'],
            'cout'           => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = $this->input('type');
            $cat  = $this->input('categorie');

            if (is_string($type) && is_string($cat) && TypeEntretien::tryFrom($type)
                && ! array_key_exists($cat, config("entretien.types.{$type}", []))) {
                $v->errors()->add('categorie', "La categorie {$cat} ne correspond pas au type {$type}.");
            }
        });
    }
}
