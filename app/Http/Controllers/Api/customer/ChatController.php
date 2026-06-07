<?php

namespace App\Http\Controllers\Api\customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    /**
     * 1. Ouvrir ou créer un chat entre deux Clients (Peer to Peer)
     */
    public function startPeerChat(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_id' => 'required|integer|exists:users,id'
        ]);

        $authId = auth()->id();
        $recipientId = $request->recipient_id;

        // Éviter de créer un chat avec soi-même
        if ($authId === (int)$recipientId) {
            return response()->json([
                'success' => false,
                'message' => 'Opération invalide : impossible de démarrer une discussion avec soi-même.'
            ], 422);
        }

        // Trouver une conversation existante contenant EXACTEMENT les deux utilisateurs
        $conversation = Conversation::where('type', 'peer_to_peer')
            ->whereHas('users', function($q) use ($authId) { $q->where('users.id', $authId); })
            ->whereHas('users', function($q) use ($recipientId) { $q->where('users.id', $recipientId); })
            ->with(['users', 'messages' => function($q) {
                $q->latest()->limit(50); // Charge les 50 derniers messages pour l'UI mobile
            }])
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create(['type' => 'peer_to_peer']);
            $conversation->users()->attach([$authId, $recipientId]);
            $conversation->load('users');
        }

        return response()->json([
            'success' => true,
            'data' => $conversation
        ]);
    }

    /**
     * 2. Ouvrir ou créer le ticket de chat avec le Support client
     */
    public function startSupportChat(): JsonResponse
    {
        $authId = auth()->id();

        // Récupérer le ticket de support ouvert pour ce client
        $conversation = Conversation::where('type', 'support')
            ->where('status', 'open')
            ->whereHas('users', function($q) use ($authId) { $q->where('users.id', $authId); })
            ->with(['users', 'messages' => function($q) {
                $q->latest()->limit(50);
            }])
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'type' => 'support',
                'status' => 'open'
            ]);

            $conversation->users()->attach($authId);
            $conversation->load('users');
        }

        return response()->json([
            'success' => true,
            'data' => $conversation
        ]);
    }

    /**
     * 3. Envoyer un message (Texte et/ou Image média)
     */

    public function sendMessage(Request $request, $conversationId)
    {
        $authId = Auth::id();

        // 1. Validation de la requête
        $request->validate([
            'body' => 'nullable|string',
            'type' => 'required|string|in:text,image,document',
            'file' => 'nullable|image|max:5120', // Max 5MB pour les images sur Guen's
        ]);

        // 2. Sécurité : Vérifier que l'expéditeur appartient bien à cette conversation
        $conversation = Conversation::where('id', $conversationId)
            ->whereHas('users', function ($q) use ($authId) {
                $q->where('users.id', $authId);
            })->firstOrFail();

        $filePath = null;

        // 3. Gestion du téléversement de l'image (Multipart)
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            // Stockage dans storage/app/public/chats
            $path = $request->file('file')->store('chats', 'public');
            $filePath = Storage::url($path); // Génère l'URL publique (/storage/chats/xyz.jpg)
        }

        // 4. Sauvegarde en Base de Données
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $authId,
            'type'            => $request->type,
            'body'            => $request->body,
            'file_path'       => $filePath,
        ]);

        // 5. ⚡ Dispatch de l'événement Reverb pour le Temps Réel
        broadcast(new MessageSent($message))->toOthers();

        // 6. Retourne le message formaté pour mettre à jour l'ID côté Android
        return response()->json([
            'success' => true,
            'message' => 'Message envoyé.',
            'data'    => new MessageResource($message)
        ], 201);
    }
    public function createOrGetConversation($contactId)
    {
        $authId = Auth::id();

        if ($authId == $contactId) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas ouvrir une discussion avec vous-même.'
            ], 400);
        }

        // 🔍 Chercher s'il existe une conversation de type 'peer_to_peer' contenant EXACTEMENT ces deux utilisateurs
        $conversation = Conversation::where('type', 'peer_to_peer')
            ->whereHas('users', function ($query) use ($authId) {
                $query->where('users.id', $authId);
            })
            ->whereHas('users', function ($query) use ($contactId) {
                $query->where('users.id', $contactId);
            })
            ->first();

        // ⚡ Si elle n'existe pas, on la crée de manière atomique (Transaction)
        if (!$conversation) {
            $conversation = DB::transaction(function () use ($authId, $contactId) {
                $newConversation = Conversation::create([
                    'type' => 'peer_to_peer',
                    'status' => 'open'
                ]);

                // Attacher les deux utilisateurs dans la table pivot 'conversation_user'
                $newConversation->users()->attach([$authId, $contactId]);

                return $newConversation;
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation initialisée avec succès.',
            'data' => new ConversationResource($conversation)
        ], 200);
    }

    public function getMessages($conversationId)
    {
        $authId = Auth::id();

        // 🔍 Récupérer la conversation en vérifiant que l'utilisateur connecté fait partie des membres (sécurité)
        $conversation = Conversation::where('id', $conversationId)
            ->whereHas('users', function ($query) use ($authId) {
                $query->where('users.id', $authId);
            })
            ->first();

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Discussion introuvable ou accès non autorisé.'
            ], 404);
        }

        // 📜 Récupérer les messages associés
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc') // Ordre chronologique pour le flux du chat mobile
            ->get();

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($messages)
        ], 200);
    }
}
