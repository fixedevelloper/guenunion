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
     * 1. Ouvrir ou récupérer un chat P2P entre deux clients
     */
    public function startPeerChat(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_id' => 'required|integer|exists:users,id'
        ]);

        $authId = Auth::id();
        $recipientId = (int) $request->recipient_id;

        // Sécurité : Éviter de créer un chat avec soi-même
        if ($authId === $recipientId) {
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
                $q->latest()->limit(50); // Pré-chargement des 50 derniers messages pour le mobile
            }])
            ->first();

        // Si elle n'existe pas, création atomique sécurisée
        if (!$conversation) {
            $conversation = DB::transaction(function () use ($authId, $recipientId) {
                $newConversation = Conversation::create([
                    'type'   => 'peer_to_peer',
                    'status' => 'open'
                ]);

                $newConversation->users()->attach([$authId, $recipientId]);
                return $newConversation;
            });

            // Recharger les relations pour la ressource
            $conversation->load(['users', 'messages']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation initialisée avec succès.',
            'data'    => new ConversationResource($conversation)
        ], 200);
    }

    /**
     * 2. Ouvrir ou créer le ticket de chat actif avec le Support client
     */
    public function startSupportChat(): JsonResponse
    {
        $authId = Auth::id();

        // Récupérer la conversation de support ouverte existante pour ce client
        $conversation = Conversation::where('type', 'support')
            ->where('status', 'open')
            ->whereHas('users', function($q) use ($authId) { $q->where('users.id', $authId); })
            ->with(['users', 'messages' => function($q) {
                $q->latest()->limit(50);
            }])
            ->first();

        // Si aucun ticket de support n'est ouvert, on en génère un nouveau
        if (!$conversation) {
            $conversation = DB::transaction(function () use ($authId) {
                $newConversation = Conversation::create([
                    'type'   => 'support',
                    'status' => 'open'
                ]);

                $newConversation->users()->attach($authId);
                return $newConversation;
            });

            $conversation->load(['users', 'messages']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Chat support initialisé.',
            'data'    => new ConversationResource($conversation)
        ], 200);
    }

    /**
     * 3. Envoyer un message (Texte, Document ou Image média)
     */
    public function sendMessage(Request $request, $conversationId): JsonResponse
    {

        // Validation stricte des flux médias de l'application
        $request->validate([
            'body' => 'required_if:type,text|nullable|string',
            'type' => 'required|string|in:text,image,document',
            'file' => 'required_if:type,image,document|nullable|file|max:5120', // Max 5MB
        ]);

        // 1. Vérifier si la conversation existe TOUT COURT
        $baseConversation = Conversation::find($conversationId);

        if (!$baseConversation) {
            return response()->json([
                'success' => false,
                'message' => "La conversation avec l'ID {$conversationId} n'existe pas du tout dans la base de données."
            ], 404);
        }

// 2. Vérifier si l'utilisateur y est rattaché
        $authId = Auth::id();
        $userIsParticipant = $baseConversation->users()->where('users.id', $authId)->exists();

        if (!$userIsParticipant) {
            return response()->json([
                'success' => false,
                'message' => "Sécurité : L'utilisateur connecté (ID: {$authId}) ne fait pas partie des participants de cette conversation."
            ], 403); // 403 Forbidden au lieu d'un faux 404
        }

// Si tout est OK, on récupère le modèle complet
        $conversation = $baseConversation;

        $filePath = null;

        // Gestion du téléversement de fichier multi-format (Images ou Documents d'affaires)
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $path = $request->file('file')->store('chats', 'public');
            $filePath = Storage::url($path); // Exemple de rendu : /storage/chats/xyz.jpg
        }

        // Sauvegarde de l'entrée de message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $authId,
            'type'            => $request->type,
            'body'            => $request->body,
            'file_path'       => $filePath,
        ]);

        // ⚡ WebSocket : Dispatch sur Reverb pour la mise à jour en temps réel des interfaces
        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé avec succès.',
            'data'    => new MessageResource($message)
        ], 201);
    }

    /**
     * 4. Récupérer l'historique complet des messages d'une discussion
     * @param $conversationId
     * @return JsonResponse
     */
    public function getMessages($conversationId): JsonResponse
    {
        $authId = Auth::id();

        // Récupérer la conversation en vérifiant les accès du demandeur
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

        // Récupération indexée par ordre chronologique ascendant (idéal pour le flux d'un chat)
        $messages = $conversation->messages()
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => MessageResource::collection($messages)
        ], 200);
    }
}
