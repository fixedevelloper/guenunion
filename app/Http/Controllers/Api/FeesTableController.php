<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeesTable;
use App\Models\Staff;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FeesTableController extends Controller
{
    /**
     * LISTER LES RÈGLES DE FRAIS (Avec filtres par corridor et type).
     * Accessible par : super_admin, country_admin, manager.
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Initialisation de la requête avec les relations réelles du modèle
            $query = FeesTable::with(['sourceCountry', 'destinationCountry']);

            // Restriction de sécurité via le profil Staff : Un Admin Pays ne voit que les frais de son pays d'affectation
            if ($user->hasRole('country_admin')) {
                if (!$staff || !$staff->country_id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Erreur de contexte : Aucun pays d'affectation trouvé pour votre profil."
                    ], 403);
                }
                $query->where('source_country_id', $staff->country_id);
            }

            // Filtres dynamiques envoyés par l'interface Next.js
            if ($request->filled('source_country_id')) {
                // Si l'utilisateur est country_admin, on applique une double sécurité sur le paramètre reçu
                $sourceId = $user->hasRole('country_admin') ? $staff->country_id : $request->input('source_country_id');
                $query->where('source_country_id', $sourceId);
            }

            if ($request->filled('destination_country_id')) {
                $query->where('destination_country_id', $request->input('destination_country_id'));
            }

            if ($request->filled('transaction_type')) {
                $query->where('transaction_type', $request->input('transaction_type'));
            }

            if ($request->has('is_active') && $request->input('is_active') !== '') {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Tri par corridor géographique puis par paliers de montants croissants
            $fees = $query->orderBy('source_country_id')
                ->orderBy('destination_country_id')
                ->orderBy('min_amount', 'asc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data'    => $fees->items(),
                'meta'    => [
                    'current_page' => $fees->currentPage(),
                    'last_page'    => $fees->lastPage(),
                    'per_page'     => $fees->perPage(),
                    'total'        => $fees->total(),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération de la table des frais : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * CRÉER UNE NOUVELLE RÈGLE TARIFAIRE (Palier / Corridor).
     * Accessible uniquement par : super_admin, country_admin.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();

        // Validation des données de paramétrage
        $validated = $request->validate([
            'transaction_type'       => 'required|string|in:transfer,cash_in,cash_out,remittance,merchant_payment',
            'source_country_id'      => 'required|exists:countries,id',
            'destination_country_id' => 'required|exists:countries,id',
            'min_amount'             => 'required|numeric|min:0',
            'max_amount'             => 'required|numeric|gt:min_amount',
            'fixed_fee'              => 'required|numeric|min:0',
            'percentage_fee'         => 'required|numeric|min:0|max:100',
            'tax_percentage'         => 'required|numeric|min:0|max:100',
        ]);

        // Barrière de sécurité pour l'Admin Pays via sa juridiction Staff
        if ($user->hasRole('country_admin')) {
            if (!$staff || (int)$validated['source_country_id'] !== (int)$staff->country_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Interdit : En tant qu'Admin Pays, vous ne pouvez configurer que des frais au départ de votre pays d'affectation."
                ], 403);
            }
        }

        try {
            return DB::transaction(function () use ($validated, $user) {

                // Algorithme anti-chevauchement des paliers sur le corridor ciblé
                $overlapExists = FeesTable::where('transaction_type', $validated['transaction_type'])
                    ->where('source_country_id', $validated['source_country_id'])
                    ->where('destination_country_id', $validated['destination_country_id'])
                    ->where('is_active', true)
                    ->where(function ($query) use ($validated) {
                        $query->whereBetween('min_amount', [$validated['min_amount'], $validated['max_amount']])
                            ->orWhereBetween('max_amount', [$validated['min_amount'], $validated['max_amount']])
                            ->orWhere(function ($q) use ($validated) {
                                $q->where('min_amount', '<=', $validated['min_amount'])
                                    ->where('max_amount', '>=', $validated['max_amount']);
                            });
                    })
                    ->exists();

                if ($overlapExists) {
                    throw new Exception("Conflit de configuration : Un palier tarifaire actif chevauche déjà les montants saisis pour ce corridor.");
                }

                $feeRule = FeesTable::create([
                    'uuid'                   => (string) Str::uuid(),
                    'transaction_type'       => $validated['transaction_type'],
                    'source_country_id'      => $validated['source_country_id'],
                    'destination_country_id' => $validated['destination_country_id'],
                    'min_amount'             => (float) $validated['min_amount'],
                    'max_amount'             => (float) $validated['max_amount'],
                    'fixed_fee'              => (float) $validated['fixed_fee'],
                    'percentage_fee'         => (float) $validated['percentage_fee'],
                    'tax_percentage'         => (float) $validated['tax_percentage'],
                    'is_active'              => true
                ]);

                Log::notice("FEES_CONFIG: Nouvelle règle tarifaire créée par User ID [{$user->id}] pour le corridor [{$feeRule->source_country_id} -> {$feeRule->destination_country_id}]");

                return response()->json([
                    'success' => true,
                    'message' => 'La règle tarifaire a été enregistrée avec succès.',
                    'data'    => $feeRule
                ], 201);
            });

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * METTRE À ZONE UNE RÈGLE DE FRAIS EXISTANTE.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $feeRule = FeesTable::where('uuid', $uuid)->firstOrFail();

        // Validation de l'accès géographique
        if ($user->hasRole('country_admin')) {
            if (!$staff || $feeRule->source_country_id !== $staff->country_id) {
                return response()->json(['success' => false, 'message' => 'Accès non autorisé à cette juridiction tarifaire.'], 403);
            }
        }

        $validated = $request->validate([
            'min_amount'     => 'required|numeric|min:0',
            'max_amount'     => 'required|numeric|gt:min_amount',
            'fixed_fee'      => 'required|numeric|min:0',
            'percentage_fee' => 'required|numeric|min:0|max:100',
            'tax_percentage' => 'required|numeric|min:0|max:100',
            'is_active'      => 'required|boolean'
        ]);

        try {
            // Analyse des chevauchements en isolant l'ID en cours de traitement
            $overlapExists = FeesTable::where('transaction_type', $feeRule->transaction_type)
                ->where('source_country_id', $feeRule->source_country_id)
                ->where('destination_country_id', $feeRule->destination_country_id)
                ->where('id', '!=', $feeRule->id)
                ->where('is_active', true)
                ->where(function ($query) use ($validated) {
                    $query->whereBetween('min_amount', [$validated['min_amount'], $validated['max_amount']])
                        ->orWhereBetween('max_amount', [$validated['min_amount'], $validated['max_amount']])
                        ->orWhere(function ($q) use ($validated) {
                            $q->where('min_amount', '<=', $validated['min_amount'])
                                ->where('max_amount', '>=', $validated['max_amount']);
                        });
                })
                ->exists();

            if ($overlapExists && $validated['is_active']) {
                throw new Exception("Modification impossible : Ce changement provoque un conflit de palier avec une règle active.");
            }

            $feeRule->update([
                'min_amount'     => (float) $validated['min_amount'],
                'max_amount'     => (float) $validated['max_amount'],
                'fixed_fee'      => (float) $validated['fixed_fee'],
                'percentage_fee' => (float) $validated['percentage_fee'],
                'tax_percentage' => (float) $validated['tax_percentage'],
                'is_active'      => $validated['is_active']
            ]);

            Log::notice("FEES_CONFIG: Règle tarifaire mise à jour (UUID: {$uuid}) par l'utilisateur ID: {$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'Tarification mise à jour avec succès.',
                'data'    => $feeRule
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * DÉSACTIVER OU ACTIVER RAPIDEMENT UNE RÈGLE (Toggle status).
     */
    public function toggleStatus(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();
            $feeRule = FeesTable::where('uuid', $uuid)->firstOrFail();

            if ($user->hasRole('country_admin')) {
                if (!$staff || $feeRule->source_country_id !== $staff->country_id) {
                    return response()->json(['success' => false, 'message' => 'Action non autorisée sur ce corridor.'], 403);
                }
            }

            // Si on tente de réactiver la règle, on effectue une vérification rapide de sécurité anti-chevauchement
            if (!$feeRule->is_active) {
                $overlapExists = FeesTable::where('transaction_type', $feeRule->transaction_type)
                    ->where('source_country_id', $feeRule->source_country_id)
                    ->where('destination_country_id', $feeRule->destination_country_id)
                    ->where('id', '!=', $feeRule->id)
                    ->where('is_active', true)
                    ->where(function ($query) use ($feeRule) {
                        $query->whereBetween('min_amount', [$feeRule->min_amount, $feeRule->max_amount])
                            ->orWhereBetween('max_amount', [$feeRule->min_amount, $feeRule->max_amount]);
                    })
                    ->exists();

                if ($overlapExists) {
                    return response()->json([
                        'success' => false,
                        'message' => "Réactivation impossible : Un autre palier actif entre en conflit avec les montants de cette règle."
                    ], 422);
                }
            }

            $feeRule->is_active = !$feeRule->is_active;
            $feeRule->save();

            return response()->json([
                'success' => true,
                'message' => $feeRule->is_active ? 'Frais activés avec succès.' : 'Frais désactivés.',
                'data'    => ['is_active' => $feeRule->is_active]
            ], 200);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * SUPPRIMER UNE RÈGLE TARIFAIRE.
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();
            $feeRule = FeesTable::where('uuid', $uuid)->firstOrFail();

            if ($user->hasRole('country_admin')) {
                if (!$staff || $feeRule->source_country_id !== $staff->country_id) {
                    return response()->json(['success' => false, 'message' => 'Action non autorisée.'], 403);
                }
            }

            $feeRule->delete(); // Exécute un Soft Delete si configuré dans le modèle

            return response()->json([
                'success' => true,
                'message' => 'Règle tarifaire supprimée avec succès.'
            ], 200);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Récupère la grille tarifaire régionale selon le scope de l'utilisateur.
     */
    public function getRegionalFees(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staffProfile = Staff::where('user_id', $user->id)->first();

            $query = FeesTable::with(['sourceCountry', 'destinationCountry']);

            // 1. Cloisonnement strict : Filtrer par le pays émetteur de l'admin connecté
            if (!$user->hasRole('super_admin')) {
                if (!$staffProfile || !$staffProfile->country_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Périmètre géographique introuvable.'
                    ], 403);
                }
                $query->where('source_country_id', $staffProfile->country_id);
            }

            // 2. Filtres applicatifs optionnels (Rôle, Type de transaction)
            if ($request->filled('type') && $request->input('type') !== 'all') {
                $query->where('transaction_type', $request->input('type'));
            }

            // Optionnel : Ajout d'un filtre par pays de destination si présent dans la requête
            if ($request->filled('destination_country_id')) {
                $query->where('destination_country_id', $request->input('destination_country_id'));
            }

            // 3. Tri et exécution
            $fees = $query->orderBy('min_amount', 'asc')->get();

            // 4. Normalisation pour l'API / Frontend
            $formatted = $fees->map(function ($fee) {
                return [
                    'id'                       => $fee->id,
                    'uuid'                     => $fee->uuid,
                    'transaction_type'         => $fee->transaction_type,
                    'source_country_name'      => $fee->sourceCountry?->name ?? 'Inconnu',
                    'destination_country_name' => $fee->destinationCountry?->name ?? 'Inconnu',
                    'min_amount'               => (float) $fee->min_amount,
                    'max_amount'               => (float) $fee->max_amount,
                    'fixed_fee'                => (float) $fee->fixed_fee,
                    'percentage_fee'           => (float) $fee->percentage_fee,
                    'tax_percentage'           => (float) $fee->tax_percentage,
                    'is_active'                => (bool) $fee->is_active,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération des tarifs : " . $e->getMessage()
            ], 500);
        }
    }

    public function storeFee(Request $request): JsonResponse
    {
        $user = Auth::user();
        $staffProfile = Staff::where('user_id', $user->id)->first();

        $validated = $request->validate([
            'transaction_type'       => 'required|in:transfer,cash_in,cash_out,remittance,merchant_payment',
            'destination_country_id' => 'required|exists:countries,id',
            'min_amount'             => 'required|numeric|min:0',
            'max_amount'             => 'required|numeric|gt:min_amount',
            'fixed_fee'              => 'required|numeric|min:0',
            'percentage_fee'         => 'required|numeric|min:0|max:100',
            'tax_percentage'         => 'required|numeric|min:0|max:100',
        ]);

        try {
            // Forcer le source_country_id si l'utilisateur est un country_admin
            $sourceCountryId = $user->hasRole('super_admin')
                ? $request->input('source_country_id')
                : $staffProfile->country_id;

            if (!$sourceCountryId) {
                return response()->json(['message' => 'Action non autorisée sans territoire assigné.'], 403);
            }

            $feeRule = FeesTable::create(array_merge($validated, [
                'source_country_id' => $sourceCountryId,
                'is_active' => true
            ]));

            return response()->json(['success' => true, 'data' => $feeRule], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
