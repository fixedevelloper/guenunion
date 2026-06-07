<?php


namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->body,
            'type' => $this->type, // text, image
            'file_path' => $this->file_path,
            'is_from_me' => $this->user_id === Auth::id(), // 🔑 Détermine l'alignement dans Compose
            'timestamp' => $this->created_at->format('H:i'),
        ];
    }
}
