<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authId = Auth::id();

        // 🎯 On récupère le premier utilisateur de la relation qui n'est PAS l'utilisateur connecté
        $contact = $this->users->first(function ($user) use ($authId) {
            return $user->id !== $authId;
        });

        return [
            'id' => $this->id,
            'type' => $this->type,     // 'peer_to_peer' ou 'support'
            'status' => $this->status, // 'open' ou 'resolved'
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,

            // 👤 Informations du correspondant pour ton PeerChatUiState côté Android
            'contact' => $contact ? [
                'id' => $contact->id,
                'username' => $contact->username,
                'first_name' => $contact->first_name ?? '',
                'last_name' => $contact->last_name ?? '',
                'avatar' => $contact->avatar, // S'aligne sur ton UserModel.kt (avatar: String?)
            ] : null
        ];
    }
}
