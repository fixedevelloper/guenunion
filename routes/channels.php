<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});



Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Vérifie si l'utilisateur (ou l'agent de support) fait partie de cette conversation
    return Conversation::where('id', $conversationId)
        ->whereHas('users', function ($query) use ($user) {
            $query->where('users.id', $user->id);
        })->exists();
});
