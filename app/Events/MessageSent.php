<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
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
        // On charge la relation user pour avoir le nom de l'expéditeur sur l'application mobile
        $this->message = $message->load('user');
    }

    public function broadcastOn(): array
    {
        // Diffuse uniquement sur le canal privé de la conversation spécifique
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'type'            => $this->message->type, // 'text' ou 'image'
            'body'            => $this->message->body,
            'file_path'       => $this->message->file_path, // Contient l'URL de l'image
            'sender_id'       => $this->message->user_id,
            'sender_name'     => $this->message->user->first_name,
            'created_at'      => $this->message->created_at->toIso8601String(),
        ];
    }
}
