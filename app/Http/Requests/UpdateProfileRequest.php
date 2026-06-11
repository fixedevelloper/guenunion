<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // L'utilisateur doit être connecté
    }

    public function rules(): array
    {
        // Récupère l'ID de l'utilisateur connecté pour ignorer son propre email lors de la validation unique
        $userId = Auth::id();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'email'      => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $userId],
            'birth_date' => ['nullable', 'date_format:Y-m-d'], // Format généré par le DatePicker Android
            'city_id'    => ['nullable', 'integer', 'exists:cities,id'], // Vérifie que la ville existe bien
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'city_id.exists' => 'La ville sélectionnée n\'existe pas.',
            'birth_date.date_format' => 'Le format de la date de naissance est invalide.',
        ];
    }
}
