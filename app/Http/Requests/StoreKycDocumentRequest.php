<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // Correspond au typePart Android
            'type' => ['required', 'string', Rule::in(['national_id', 'passport', 'driving_license', 'residence_permit'])],

            // Correspond au docPart Android ("N/A" ou un vrai numéro si vous l'ajoutez plus tard)
            'document_number' => ['required', 'string', 'min:4', 'max:50'],

            // Fichier Recto : Toujours requis, doit être une image (JPEG, PNG) de max 4Mo
            'front_image' => ['required', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],

            // Fichier Verso : Requis UNIQUEMENT si le type n'est pas un passeport
            'back_image' => [
                Rule::requiredIf(function () {
                    return $this->input('type') !== 'passport';
                }),
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:4096'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document_number.required' => 'Le numéro du document est obligatoire.',
            'document_number.min'      => 'Le numéro du document semble trop court.',
            'front_image.required' => 'La photo recto du document est obligatoire.',
            'back_image.required_if' => 'La photo verso est obligatoire pour ce type de document.',
            'front_image.max' => 'L\'image recto ne doit pas dépasser 4 Mo.',
            'back_image.max' => 'L\'image verso ne doit pas dépasser 4 Mo.',
        ];
    }
}
