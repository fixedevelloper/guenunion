<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AgentChatController extends Controller
{

    public function index(): JsonResponse
    {
        $authId = Auth::id();

        // 1. Récupérer les utilisateurs ayant le rôle "agent" (ou une liste de rôles)
        // et exclure l'utilisateur connecté
        $agents = User::role(['cashier', 'manager']) // Ajustez les noms de vos rôles Spatie ici
        ->where('id', '!=', $authId)
            ->select(['id', 'first_name','last_name']) // On sélectionne les colonnes de la table users
            ->with('roles:id,name') // Optionnel : charge les rôles Spatie associés (légère surcharge SQL mais propre)
            ->get();

        // 2. Formater la réponse pour inclure le rôle de manière lisible si nécessaire
        $formattedAgents = $agents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->first_name.' '.$agent->last_name,
                // Récupère le premier rôle de l'utilisateur (ou null s'il n'en a pas)
                'role' => $agent->roles->first()?->name
        ];
    });

        return response()->json([
            'success' => true,
            'data' => $formattedAgents
        ], 200);
    }
    public function getAgentsByCountry(): JsonResponse
    {
        $authId = Auth::id();

        // 1. On récupère d'abord le pays de l'utilisateur connecté pour filtrer automatiquement
        $currentStaff = Staff::with('agency')
            ->where('user_id', $authId)
            ->first();

        if (!$currentStaff || !$currentStaff->agency_id || !$currentStaff->agency->country_id) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de déterminer votre pays de rattachement.'
            ], 403);
        }

        $countryId = $currentStaff->agency->country_id;

        // 2. On récupère les agents appartenant au MÊME pays, en excluant l'utilisateur connecté
        $agents = User::role(['cashier', 'manager','country_admin'])
            ->where('id', '!=', $authId)
            ->whereHas('staff.agency', function ($query) use ($countryId) {
                $query->where('country_id', $countryId);
            })
            ->select(['id', 'first_name','last_name'])
            ->with('roles:id,name')
            ->get();

        // 3. Formatage propre et standardisé pour votre Sidebar React
        $formattedAgents = $agents->map(function ($agent) {
            return [
                'id'   => $agent->id,
                'name' => $agent->first_name.' '.$agent->last_name,
                'role' => $agent->roles->first()?->name ?? 'Personnel d\'agence',
        ];
    });

        return response()->json([
            'success' => true,
            'data'    => $formattedAgents
        ], 200);
    }
    public function getGlobalAgents(): JsonResponse
    {
        $authId = Auth::id();

        // 1. Récupérer TOUS les utilisateurs ayant les rôles spécifiés, sauf l'utilisateur connecté
        $agents = User::role(['cashier', 'manager','country_admin','super_admin'])
            ->where('id', '!=', $authId)
            ->select(['id', 'first_name','last_name'])
            ->with('roles:id,name')
            ->get();

        // 2. Formatage standardisé identique pour l'UI React
        $formattedAgents = $agents->map(function ($agent) {
            return [
                'id'   => $agent->id,
                'name' => $agent->first_name.' '.$agent->last_name,
                'role' => $agent->roles->first()?->name ?? 'Personnel d\'agence',
        ];
    });

        return response()->json([
            'success' => true,
            'data'    => $formattedAgents
        ], 200);
    }
    /**
     * 1. Ouvrir ou créer une discussion privée entre deux agents (Staff)
     */
    public function startAgentChat(Request $request): JsonResponse
    {
        $request->validate([
            'recipient_staff_id' => 'required|integer|exists:staff,id'
        ]);

        $authUser = Auth::user();

        $currentStaff = Staff::where('user_id', $authUser->id)->first();
        if (!$currentStaff) {
            return response()->json(['success' => false, 'message' => 'Accès restreint au personnel.'], 403);
        }

        $targetStaff = Staff::findOrFail($request->recipient_staff_id);
        $recipientUserId = $targetStaff->user_id;

        if ($authUser->id === $recipientUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Opération invalide : impossible de démarrer un chat interne avec soi-même.'
            ], 422);
        }

        $conversation = Conversation::where('type', 'agent_to_agent')
            ->whereHas('users', function($q) use ($authUser) { $q->where('users.id', $authUser->id); })
            ->whereHas('users', function($q) use ($recipientUserId) { $q->where('users.id', $recipientUserId); })
            ->with(['users', 'messages' => function($q) {
                $q->latest()->limit(50);
            }])
            ->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () use ($authUser, $recipientUserId) {
                $newConversation = Conversation::create([
                    'type'   => 'agent_to_agent',
                    'status' => 'open'
                ]);

                $newConversation->users()->attach([$authUser->id, $recipientUserId]);
                return $newConversation;
            });

            $conversation->load(['users', 'messages']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Discussion inter-agent initialisée.',
            'data'    => new ConversationResource($conversation)
        ], 200);
    }

    /**
     * 2. Rejoindre ou récupérer le canal de groupe de l'Agence de l'agent
     */
    public function getAgencyGroupChat(): JsonResponse
    {
        $authUser = Auth::user();

        $currentStaff = Staff::with('agency')->where('user_id', $authUser->id)->first();
        if (!$currentStaff || !$currentStaff->agency_id) {
            return response()->json(['success' => false, 'message' => 'Vous devez être rattaché à une agence pour accéder au canal interne.'], 403);
        }

        $agency = $currentStaff->agency;

        // /!\ RECHERCHE PAR AGENCY_ID (Nécessite l'ajout de la colonne agency_id dans votre table conversations)
        $conversation = Conversation::where('type', 'agency_group')
            ->where('agency_id', $agency->id)
            ->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () use ($agency) {
                return Conversation::create([
                    'type'      => 'agency_group',
                    'status'    => 'open',
                    'agency_id' => $agency->id
                ]);
            });
        }

        // Sécurité : Si l'agent n'est pas encore dans le pivot, on l'ajoute de manière efficiente
        if (!$conversation->users()->where('users.id', $authUser->id)->exists()) {
            $conversation->users()->attach($authUser->id);
        }

        $conversation->load(['users', 'messages' => function($q) {
            $q->latest()->limit(50);
        }]);

        return response()->json([
            'success' => true,
            'message' => "Canal interne de l'agence [{$agency->name}] chargé.",
            'data'    => new ConversationResource($conversation)
        ], 200);
    }

    public function getCountryGroupChat(): JsonResponse
    {
        $authUser = Auth::user();

        // 1. On récupère le staff avec son agence pour en déduire le pays
        $currentStaff = Staff::with('agency')->where('user_id', $authUser->id)->first();
        if (!$currentStaff || !$currentStaff->agency_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être rattaché à une agence pour accéder au canal national.'
            ], 403);
        }

        $agency = $currentStaff->agency;

        // ⚠️ Sécurité : On vérifie que l'agence est bien liée à un pays
        if (!$agency->country_id) {
            return response()->json([
                'success' => false,
                'message' => "Votre agence n'est rattachée à aucun pays configuré."
            ], 422);
        }

        $countryId = $agency->country_id;

        // 2. Recherche ou création du salon national basé sur le country_id
        $conversation = Conversation::where('type', 'country_group') // Nouveau type à gérer si besoin
        ->where('country_id', $countryId)
            ->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () use ($countryId) {
                return Conversation::create([
                    'type'       => 'country_group',
                    'status'     => 'open',
                    'country_id' => $countryId
                ]);
            });
        }

        // 3. Sécurité : Si l'agent du pays n'est pas encore dans le pivot, on l'ajoute
        if (!$conversation->users()->where('users.id', $authUser->id)->exists()) {
            $conversation->users()->attach($authUser->id);
        }

        // 4. Chargement des données (limité aux 50 derniers messages pour la performance)
        $conversation->load(['users', 'messages' => function($q) {
            $q->latest()->limit(50);
        }]);

        return response()->json([
            'success' => true,
            'message' => "Canal national des agences chargé.",
            'data'    => new ConversationResource($conversation)
        ], 200);
    }
    public function getGlobalGroupChat(): JsonResponse
    {
        $authUser = Auth::user();

        // 1. Sécurité : On s'assure que l'utilisateur fait bien partie du personnel (Staff)
        $currentStaff = Staff::where('user_id', $authUser->id)->first();
        if (!$currentStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un membre du personnel pour accéder au canal global.'
            ], 403);
        }

        // 2. Recherche ou création de l'unique salon global de l'application
        $conversation = Conversation::where('type', 'global_group')->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () {
                return Conversation::create([
                    'type'       => 'global_group',
                    'status'     => 'open',
                    'agency_id'  => null, // Universel : aucune attache locale
                    'country_id' => null  // Universel : aucune attache nationale
                ]);
            });
        }

        // 3. Intégration à la volée : Si l'agent n'est pas encore dans le pivot, on l'ajoute
        if (!$conversation->users()->where('users.id', $authUser->id)->exists()) {
            $conversation->users()->attach($authUser->id);
        }

        // 4. Chargement des données performant (limité aux 50 derniers messages)
        $conversation->load(['users', 'messages' => function($q) {
            $q->latest()->limit(50);
        }]);

        return response()->json([
            'success' => true,
            'message' => "Canal global inter-agences connecté avec succès.",
            'data'    => new ConversationResource($conversation)
        ], 200);
    }
    /**
     * 3. Rechercher un agent par son numéro de téléphone et préparer la conversation.
     */
    public function findAgentByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:4|max:20', // Abaissé à 4 pour matcher votre logique JS (query.length >= 4)
        ]);

        $authUser = Auth::user();
        // Nettoyage basique : supprime les espaces, points, tirets pour être tolérant à la saisie utilisateur
        $targetPhone = str_replace([' ', '.', '-', '(', ')'], '', $request->input('phone'));

        // 1. Vérifier que l'appelant est un agent
        $currentStaff = Staff::where('user_id', $authUser->id)->first();
        if (!$currentStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Accès restreint au personnel autorisé.'
            ], 403);
        }

        // 2. Trouver l'utilisateur cible ET s'assurer qu'il est aussi un Staff
        // Utilisation d'un LIKE optionnel pour permettre la recherche partielle si le numéro est incomplet
        $targetUser = User::where('phone_number', 'LIKE', "%{$targetPhone}%")
            ->whereHas('staff')
            ->with('staff') // Charge la relation pour récupérer son rôle d'agent si besoin
            ->first();

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => "Aucun agent de l'agence ne possède ce numéro."
            ], 404);
        }

        if ($targetUser->id === $authUser->id) {
            return response()->json([
                'success' => false,
                'message' => "Vous ne pouvez pas initier un chat privé avec vous-même."
            ], 422);
        }

        // 3. Chercher ou créer la conversation 'agent_to_agent' (Votre logique est parfaite ici)
        $conversation = Conversation::where('type', 'agent_to_agent')
            ->whereHas('users', function ($q) use ($authUser) { $q->where('users.id', $authUser->id); })
            ->whereHas('users', function ($q) use ($targetUser) { $q->where('users.id', $targetUser->id); })
            ->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () use ($authUser, $targetUser) {
                $newConversation = Conversation::create([
                    'type' => 'agent_to_agent',
                    'status' => 'open'
                ]);
                $newConversation->users()->attach([$authUser->id, $targetUser->id]);
                return $newConversation;
            });
        }

        // Charger les relations et trier les messages par ordre CHRONOLOGIQUE croissant pour le widget de chat
        $conversation->load(['users', 'messages' => function ($q) {
            $q->latest()->limit(30); // Récupère les 30 derniers, mais attention :
        }]);

        // Facultatif : Remettre les messages dans le bon sens (chronologique) pour l'affichage du chat
        $conversation->setRelation('messages', $conversation->messages->reverse()->values());

        // 4. Structuration de la réponse pour TanStack Query
        return response()->json([
            'success' => true,
            'message' => 'Liaison privée établie avec succès.',
            'data' => [
                'conversation' => new ConversationResource($conversation),
                'agent' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->first_name . ' ' . $targetUser->last_name, // Prévient le bug du .toLowerCase()
                    'role' => $targetUser->staff->role ?? 'Agent',
                    'is_online' => (bool) $targetUser->is_online
                ]
            ]
        ], 200);
    }
}
