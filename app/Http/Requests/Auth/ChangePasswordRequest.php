<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autoriser l'accès (la route sera protégée par un middleware auth)
    }

    public function rules(): array
    {
        return [
            // Ces clés doivent correspondre EXACTEMENT aux clés envoyées dans la Map Kotlin
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                Password::min(6), // Minimum 6 caractères (comme votre validation Compose)
                'different:current_password' // Le nouveau mot de passe doit être différent de l'ancien
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Le mot de passe actuel est requis.',
            'new_password.required' => 'Le nouveau mot de passe est requis.',
            'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
            'new_password.different' => 'Le nouveau mot de passe doit être différent du mot de passe actuel.',
        ];
    }
}
