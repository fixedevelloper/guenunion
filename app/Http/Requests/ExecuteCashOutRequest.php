<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteCashOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_id'   => 'required|integer|exists:customers,id',
            'amount'         => 'required|numeric|min:100', // Montant minimum de retrait
            'reference_note' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_id.required' => "L'identifiant du client est obligatoire.",
            'recipient_id.exists'   => "Le client spécifié est introuvable.",
            'amount.min'            => "Le montant minimum pour un retrait est de 100.",
        ];
    }
}
