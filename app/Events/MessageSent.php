<?php

namespace App\Events;

use App\Models\Message;
use App\Http\Resources\MessageResource; // 🔄 Importez votre ressource harmonisée
use Illuminate\Broadcasting\Channel;   // 💡 Changé en canal public pour correspondre à React
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load('user');
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        // 🔄 Aligné sur l'écouteur React : `chat.{id}` (Canal simple/public d'après votre JS)
        return [
            new Channel('chat.' . $this->message->conversation_id),
        ];
    }

    /**
     * Data to broadcast.
     */
    public function broadcastWith(): array
    {
        // 🔑 ON UNIFIE : On utilise la même structure que l'historique de messages
        return [
            'message' => [
                'id'         => $this->message->id,
                'body'       => $this->message->body, // Fixé sur 'body' selon vos préférences
                'type'       => $this->message->type,
                'file_path'  => $this->message->file_path,
                'user_id'    => $this->message->user_id, // Nécessaire pour le calcul de provenance
                'timestamp'  => $this->message->created_at ? $this->message->created_at->format('H:i') : now()->format('H:i'),
                'user'       => [
                    'id'   => $this->message->user_id,
                    'name' => $this->message->user ? $this->message->user->first_name . ' ' . $this->message->user->last_name : 'Agent',
                ],

                // --- Rétrocompatibilité avec votre application mobile si nécessaire ---
                'conversation_id' => $this->message->conversation_id,
                'sender_id'       => $this->message->user_id,
                'sender_name'     => $this->message->user ? $this->message->user->first_name : 'Agent',
                'created_at'      => $this->message->created_at->toIso8601String(),
            ]
        ];
    }
}
