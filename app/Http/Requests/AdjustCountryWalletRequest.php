<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustCountryWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Géré par le middleware des routes
    }

    public function rules(): array
    {
        return [
            'action'         => 'required|in:credit,debit',
            'amount'         => 'required|numeric|min:1',
            'reference_note' => 'required|string|min:5|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'action.in'               => "L'action doit être soit 'credit' (ajout) soit 'debit' (retrait).",
            'amount.min'              => "Le montant doit être supérieur à 0.",
            'reference_note.required' => "Une justification ou référence bancaire est obligatoire pour l'audit trail.",
        ];
    }
}
