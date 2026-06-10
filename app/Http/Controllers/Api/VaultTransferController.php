<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VaultTransferRequestService;
use App\Models\VaultTransferRequest;
use App\Models\Agency;
use App\Models\Till;
use App\Models\Country;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class VaultTransferController extends Controller
{
    protected VaultTransferRequestService $vaultService;

    public function __construct(VaultTransferRequestService $vaultService)
    {
        $this->vaultService = $vaultService;
    }

    /**
     * 1. CRÉATION D'UNE DEMANDE (Guichet -> Agence OU Agence -> Pays)
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'level'  => 'required|in:till_to_agency,agency_to_country', // Niveau de la demande
            'type'   => 'required|in:supply,deposit',                   // Direction du flux
            'amount' => 'required|numeric|gt:0',
            'notes'  => 'nullable|string|max:255'
        ]);

        try {
            $requester = null;
            $target = null;

            if ($validated['level'] === 'till_to_agency') {
                // Scénario : Le Caissier demande à son Agence
                $staff = Staff::where('user_id', $user->id)->with('currentTill')->first();
                $requester = $staff?->currentTill;
                $target = $requester ? Agency::find($requester->agency_id) : null;

                if (!$requester || $requester->status !== 'open') {
                    return response()->json(['success' => false, 'message' => "Votre guichet actif doit être ouvert pour initier cette demande."], 403);
                }

            } else {
                // Scénario : Le Superviseur demande au Pays rattaché
                // (Ici nous récupérons l'ID d'agence via une méthode helper de votre architecture)
                $agencyId = $user->staff?->agency_id ?? $request->header('X-Agency-Id');
                $requester = Agency::with('city.country')->find($agencyId);
                $target = $requester?->city?->country;

                if (!$user->isSupervisor()) {
                    return response()->json(['success' => false, 'message' => "Seul un superviseur d'agence peut formuler des requêtes vers la direction nationale."], 403);
                }
            }

            if (!$requester || !$target) {
                return response()->json(['success' => false, 'message' => "Impossible de résoudre les entités financières source et cible."], 422);
            }

            // Appel au Service unifié
            $vaultRequest = $this->vaultService->createRequest(
                $validated,
                $requester,
                $target,
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Demande de mouvement de trésorerie enregistrée et transmise avec succès.',
                'data'    => $vaultRequest
            ], 201);

        } catch (Exception $e) {
            Log::error("Erreur création demande trésorerie : " . $e->getMessage());
            return response()->json(['success' => false, 'message' => "Une erreur interne a bloqué l'enregistrement de la demande."], 500);
        }
    }

    /**
     * 2. LOGIQUE DE VALIDATION / REJET (Le Cœur Comptable)
     */
    public function process(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action'           => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|max:255'
        ]);

        try {
            // Sécurité : On vérifie l'existence de la demande avant traitement
            $vaultRequest = VaultTransferRequest::findOrFail($id);

            // Vérification des privilèges de traitement selon la cible polymorphe
            if ($vaultRequest->target_type === Country::class && !auth()->user()->isCountryAdmin()) {
                return response()->json(['success' => false, 'message' => "Droits insuffisants. Seul un Country Admin peut valider cette ligne."], 403);
            }

            if ($vaultRequest->target_type === Agency::class && !auth()->user()->isSupervisor()) {
                return response()->json(['success' => false, 'message' => "Droits insuffisants. Seul un Superviseur peut valider cette ligne."], 403);
            }

            // Exécution financière via le Service
            $this->vaultService->processRequest(
                $id,
                $validated['action'],
                auth()->id(),
                $validated['rejection_reason'] ?? null
            );

            $msg = $validated['action'] === 'approve'
                ? 'L\'opération de trésorerie a été liquidée et inscrite au Grand Livre avec succès.'
                : 'La demande de transfert de fonds a été officiellement rejetée.';

            return response()->json(['success' => true, 'message' => $msg], 200);

        } catch (Exception $e) {
            Log::error("Erreur traitement flux coffre : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode());
            }

            return response()->json(['success' => false, 'message' => "Échec critique lors de l'inscription comptable du mouvement."], 500);
        }
    }

    /**
     * 3. LISTING ADAPTATIF DES DEMANDES EN ATTENTE (Tableau de bord de validation)
     */
    public function pendingRequests(Request $request): JsonResponse
    {
        $user = auth()->user();

        // On charge les relations polymorphes pour afficher les noms ("Guichet Ouest", "Agence Douala") au Front
        $query = VaultTransferRequest::with(['requester', 'target', 'creator'])
            ->where('status', 'pending');

        // Filtrage intelligent selon la casquette de l'utilisateur connecté
        if ($user->isCountryAdmin()) {
            $query->where('target_type', Country::class);
        } elseif ($user->isSupervisor()) {
            $agencyId = $user->staff?->agency_id ?? $request->header('X-Agency-Id');
            $query->where('target_type', Agency::class)->where('target_id', $agencyId);
        } else {
            // Un simple caissier ne voit que ses propres requêtes en attente
            $query->where('creator_id', $user->id);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->get()
        ], 200);
    }

    /**
     * 4. JOURNAL / ARCHIVES DES MOUVEMENTS TRAITÉS (Pour l'Audit et les Rapports)
     */
    public function history(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = VaultTransferRequest::with(['requester', 'target', 'creator', 'validator'])
            ->where('status', '!=', 'pending');

        // Filtres optionnels passés par l'UI Next.js
        if ($request->filled('status')) {
            $query->where('status', $request->status); // approved ou rejected
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type); // supply ou deposit
        }

        // Restriction de périmètre
        if ($user->isSupervisor() && !$user->isCountryAdmin()) {
            $agencyId = $user->staff?->agency_id ?? $request->header('X-Agency-Id');
            $query->where(function($q) use ($agencyId) {
                $q->where(fn($sub) => $sub->where('requester_type', Agency::class)->where('requester_id', $agencyId))
                    ->orWhere(fn($sub) => $sub->where('target_type', Agency::class)->where('target_id', $agencyId));
            });
        } elseif (!$user->isCountryAdmin()) {
            $query->where('creator_id', $user->id);
        }

        $history = $query->orderByDesc('processed_at')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success'    => true,
            'data'       => $history->items(),
            'pagination' => [
                'current_page' => $history->currentPage(),
                'last_page'    => $history->lastPage(),
                'total'        => $history->total(),
            ]
        ], 200);
    }

    /**
     * 5. ANNULATION D'UNE DEMANDE PAR SON CRÉATEUR (Tant qu'elle est 'pending')
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $vaultRequest = VaultTransferRequest::where('id', $id)
                ->where('creator_id', auth()->id())
                ->firstOrFail();

            if ($vaultRequest->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible d'annuler cette ligne : elle a déjà été validée ou rejetée par votre hiérarchie."
                ], 422);
            }

            // Suppression logique ou physique selon votre politique d'audit trail
            $vaultRequest->delete();

            return response()->json([
                'success' => true,
                'message' => 'Votre demande de transfert de fonds a été retirée avec succès.'
            ], 200);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Demande introuvable ou action non autorisée.'], 404);
        }
    }
}
