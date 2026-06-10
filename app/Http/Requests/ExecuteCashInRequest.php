<?php


namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteCashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_id'   => 'required|integer|exists:customers,id',
            'amount'         => 'required|numeric|min:100',
            'reference_note' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_id.required' => "Le bénéficiaire est obligatoire.",
            'recipient_id.exists'   => "Le client bénéficiaire spécifié est introuvable.",
            'amount.min'            => "Le montant minimum pour un dépôt est de 100.",
        ];
    }
}
