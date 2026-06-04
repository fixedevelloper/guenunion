<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\TransactionEntry;
use App\Models\SystemAuditLog;
use App\Models\City;
use App\Models\Staff;
use App\Services\LedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    protected LedgerService $ledgerService;

    /**
     * Injection du LedgerService pour sécuriser cryptographiquement les mouvements de coffre.
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * LISTE DES AGENCES (Filtres par pays/ville/statut).
     * Accessible par : super_admin, country_admin, compliance.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Récupération sécurisée du profil métier / staff de l'opérateur connecté
            $staff = Staff::where('user_id', $user->id)->first();

            // Remplacement de l'ancienne relation 'users' par 'staff' pour compter les collaborateurs rattachés
            $query = Agency::with([
                'country',
                'city',
                'parentAgency'
            ])->withCount('staff as staff_count');

            // Restriction territoriale stricte basée sur le cloisonnement de la table Staff
            if ($user->hasRole('country_admin') && $staff) {
                $query->where('country_id', $staff->country_id);
            }

            // Filtres applicatifs
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('city_id')) {
                $query->where('city_id', $request->input('city_id'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $agencies = $query->orderBy('name', 'asc')->get();

            $formatted = $agencies->map(function ($agency) {
                return [
                    'id'              => $agency->id,
                    'uuid'            => $agency->uuid,
                    'code'            => $agency->code,
                    'name'            => $agency->name,
                    'parent_name'     => $agency->parentAgency?->name, // Correction du nom de la relation Eloquent
                    'parent_code'     => $agency->parentAgency?->code,
                    'address'         => $agency->address,
                    'phone_number'    => $agency->phone_number,
                    'email'           => $agency->email,
                    'current_balance' => (float) $agency->current_balance,
                    'status'          => $agency->status,
                    'is_active'       => $agency->is_active,
                    'staff_count'     => $agency->staff_count,
                    'city_name'       => $agency->city?->name ?? '—',
                    'country_name'    => $agency->country?->name ?? '—',
                    'country_code'    => $agency->country?->code ?? '—',
                    'currency_code'   => $agency->country?->currency_code ?? 'XAF',
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ENREGISTRER UNE NOUVELLE AGENCE OU FILIALE.
     */
    public function store(Request $request): JsonResponse
    {
        // Alignement sur les nouveaux rôles Spatie validés
        if (!Auth::user()->hasAnyRole(['super_admin', 'country_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée pour ce profil.'
            ], 403);
        }

        $validated = $request->validate([
            'code'             => 'required|string|max:50|unique:agencies,code',
            'name'             => 'required|string|max:150',
            'parent_agency_id' => 'nullable|exists:agencies,id',
            'country_id'       => 'required|exists:countries,id',
            'city_id'          => 'required|exists:cities,id',
            'address'          => 'nullable|string|max:255',
            'phone_number'     => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:100',
            'status'           => 'required|in:active,suspended,closed',
            'is_active'        => 'required|boolean'
        ]);

        try {
            // Validation de la cohérence géographique ville/pays
            $city = City::with('country')->findOrFail($validated['city_id']);

            if ($city->country_id !== (int) $validated['country_id']) {
                return response()->json([
                    'success' => false,
                    'message' => "Incohérence : La ville de [{$city->name}] n'appartient pas au pays sélectionné."
                ], 422);
            }

            $staff = Staff::where('user_id', Auth::id())->first();

            // Restriction souveraine : Un country_admin ne peut pas créer une agence en dehors de son pays assigné
            if (Auth::user()->hasRole('country_admin')) {
                if (!$staff || (int) $staff->country_id !== (int) $validated['country_id']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez pas créer une agence hors de votre juridiction nationale.'
                    ], 403);
                }
            }

            $agency = DB::transaction(function () use ($validated, $city) {
                $validated['uuid'] = (string) Str::uuid();
                $validated['current_balance'] = 0.00;

                $newAgency = Agency::create($validated);

                // Initialisation du Wallet de trésorerie (Compte de coffre fort principal)
                Wallet::create([
                    'uuid'          => (string) Str::uuid(),
                    'wallet_number' => 'W-AG-' . strtoupper(Str::random(4)) . rand(100, 999), // Génération dynamique et propre plutôt qu'une valeur en dur
                    'owner_type'    => Agency::class,
                    'owner_id'      => $newAgency->id,
                    'type'          => 'main',
                    'balance'       => 0.00,
                    'currency'      => $city->country->currency_code ?? 'XAF',
                    'is_active'     => $newAgency->is_active,
                ]);

                // Audit Trace
                SystemAuditLog::create([
                    'uuid'       => (string) Str::uuid(),
                    'user_id'    => Auth::id(),
                    'agency_id'  => $newAgency->id,
                    'event_type' => 'AGENCY.CREATION',
                    'severity'   => 'info',
                    'message'    => "Création de l'agence [{$newAgency->name}]",
                    'payload'    => [
                        'code'             => $newAgency->code,
                        'parent_agency_id' => $newAgency->parent_agency_id,
                        'currency'         => $city->country->currency_code ?? 'XAF',
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $newAgency;
            });

            return response()->json([
                'success' => true,
                'message' => "Agence créée avec succès.",
                'data'    => $agency
            ], 201);

        } catch (Exception $e) {
            Log::error("Échec du déploiement agence : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur système : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * CONSULTER UNE AGENCE EXPÉDITIÈRE OU LOCALE.
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            $agency = Agency::with(['country', 'city'])->where('uuid', $uuid)->firstOrFail();

            // Restriction d'accès basée sur le cloisonnement de la table Staff (Rôle 'manager')
            if ($user->hasRole('manager')) {
                if (!$staff || (int) $staff->agency_id !== (int) $agency->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé. Vous ne pouvez visualiser que votre propre agence.'
                    ], 403);
                }
            }

            // Récupération de la tréso de coffre liée
            $mainWallet = Wallet::where('owner_type', Agency::class)
                ->where('owner_id', $agency->id)
                ->where('type', 'main')
                ->first();

            return response()->json([
                    'success' => true,
                    'data'    => [
                        'agency'          => $agency,
                        'vault_balance'   => $mainWallet ? (float) $mainWallet->balance : 0,
                        'currency'        => $mainWallet?->currency ?? 'XAF',
                    'is_vault_active' => $mainWallet?->is_active ?? false,
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Agence introuvable ou accès interdit.'
            ], 404);
        }
    }

    /**
     * AJUSTEMENT ET APPROVISIONNEMENT DE COFFRE (FORCAGE OU DECAISSEMENT).
     * Enregistre une transaction parente de type 'adjustment' et ses écritures comptables.
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */

    public function adjustVault(Request $request, string $uuid): JsonResponse
    {
        Log::warning("Initialisation à l'ajustement de coffre", [
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email ?? 'Inconnu',
            'agency_uuid' => $uuid,
            'ip' => request()->ip()
        ]);
        // Alignement des rôles Spatie
        if (!Auth::user()->hasAnyRole(['super_admin', 'country_admin'])) {
            Log::warning("Tentative d'accès non autorisée à l'ajustement de coffre", [
                'user_id' => Auth::id(),
                'user_email' => Auth::user()->email ?? 'Inconnu',
                'agency_uuid' => $uuid,
                'ip' => request()->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Action refusée. Niveau d\'accréditation insuffisant.'
            ], 403);
        }

        $request->validate([
            'action'      => 'required|string|in:fund,debit',
            'amount'      => 'required|numeric|min:5000',
            'description' => 'required|string|max:255|min:5',
            'is_physical' => 'nullable|boolean'
        ]);

        $operator = Auth::user();

        // Log de début de procédure informatique
        Log::info("Début de la procédure d'ajustement de coffre", [
            'operator_id' => $operator->id,
            'agency_uuid' => $uuid,
            'action'      => $request->input('action'),
            'amount'      => $request->input('amount'),
            'is_physical' => $request->input('is_physical', false)
        ]);

        try {
            $agency = Agency::where('uuid', $uuid)->firstOrFail();
            $amount = (float) $request->input('amount');
            $action = $request->input('action');
            $isPhysical = (bool) $request->input('is_physical', false);

            $transaction = DB::transaction(function () use ($operator, $agency, $amount, $action, $isPhysical, $request) {

                // 1. Verrouillage du portefeuille virtuel principal de l'agence (Cible)
                $agencyWallet = Wallet::where('owner_type', Agency::class)
                    ->where('owner_id', $agency->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$agencyWallet || !$agencyWallet->is_active) {
                    Log::error("Échec ajustement : Portefeuille agence introuvable ou inactif", ['agency_id' => $agency->id]);
                    throw new Exception("Coffre virtuel d'agence introuvable, suspendu ou non initialisé.");
                }

                // 2. Verrouillage du portefeuille de contrepartie du Système
                $systemMasterWallet = Wallet::where('type', 'escrow')
                    ->where('currency', $agencyWallet->currency)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$systemMasterWallet) {
                    Log::error("Échec ajustement : Trésorerie centrale introuvable", ['currency' => $agencyWallet->currency]);
                    throw new Exception("Compte de trésorerie centrale indisponible pour cette devise.");
                }

                // 3. CRÉATION DE LA TRANSACTION PARENTE (Audit principal)
                $reference = 'ADJ-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $transaction = Transaction::create([
                    'uuid'                  => (string) Str::uuid(),
                    'reference'             => $reference,
                    'type'                  => 'adjustment',
                    'status'                => 'initiated',
                    'amount'                => $amount,
                    'fees'                  => 0,
                    'taxes'                 => 0,
                    'currency'              => $agencyWallet->currency,
                    'source_agency_id'      => $action === 'debit' ? $agency->id : null,
                    'destination_agency_id' => $action === 'fund' ? $agency->id : null,
                    'initiator_id'          => $operator->id,
                    'description'           => $request->input('description'),
                    'metadata'              => [
                        'is_physical' => $isPhysical,
                        'action_type' => $action,
                        'triggered_by' => $operator->name
                    ]
                ]);

                $agencyBalanceBefore = (float) $agencyWallet->balance;
                $systemBalanceBefore = (float) $systemMasterWallet->balance;

                if ($action === 'fund') {
                    if ($systemBalanceBefore < $amount) {
                        Log::warning("Échec ajustement : Solde système insuffisant pour fund", [
                            'reference' => $reference,
                            'system_balance' => $systemBalanceBefore,
                            'requested_amount' => $amount
                        ]);
                        throw new Exception("Trésorerie centrale insuffisante pour allouer cette ligne de provision.");
                    }

                    $agencyWallet->increment('balance', $amount);
                    $systemMasterWallet->decrement('balance', $amount);

                    $agencyEntryType = 'credit';
                    $systemEntryType = 'debit';
                } else {
                    if ($agencyBalanceBefore < $amount) {
                        Log::warning("Échec ajustement : Solde agence insuffisant pour debit", [
                            'reference' => $reference,
                            'agency_balance' => $agencyBalanceBefore,
                            'requested_amount' => $amount
                        ]);
                        throw new Exception("Solde de coffre virtuel insuffisant pour effectuer ce retrait.");
                    }

                    $agencyWallet->decrement('balance', $amount);
                    $systemMasterWallet->increment('balance', $amount);

                    $agencyEntryType = 'debit';
                    $systemEntryType = 'credit';
                }

                // 4. DOUBLE ÉCRITURE DANS LE GRAND LIVRE

                // Écriture Agence (Cible)
                $agencySignature = $this->ledgerService->generateSignature(
                    $agencyWallet->id, $amount, $agencyEntryType, $agencyBalanceBefore, (float) $agencyWallet->fresh()->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $agencyWallet->id,
                    'entry_type'     => $agencyEntryType,
                    'amount'         => $amount,
                    'balance_before' => $agencyBalanceBefore,
                    'balance_after'  => $agencyWallet->fresh()->balance,
                    'row_signature'  => $agencySignature
                ]);

                // Écriture Système (Contrepartie comptable)
                $systemSignature = $this->ledgerService->generateSignature(
                    $systemMasterWallet->id, $amount, $systemEntryType, $systemBalanceBefore, (float) $systemMasterWallet->fresh()->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $systemMasterWallet->id,
                    'entry_type'     => $systemEntryType,
                    'amount'         => $amount,
                    'balance_before' => $systemBalanceBefore,
                    'balance_after'  => $systemMasterWallet->fresh()->balance,
                    'row_signature'  => $systemSignature
                ]);

                // 5. MISE À JOUR DE L'ENCAISSE PHYSIQUE (Optionnelle)
                if ($isPhysical) {
                    if ($action === 'fund') {
                        $agency->increment('current_balance', $amount);
                    } else {
                        if ((float) $agency->current_balance < $amount) {
                            Log::warning("Échec ajustement : Encaisse physique insuffisante pour l'agence", [
                                'reference' => $reference,
                                'physical_balance' => $agency->current_balance,
                                'requested_amount' => $amount
                            ]);
                            throw new Exception("L'encaisse physique en espèces de l'agence est inférieure au montant du décaissement demandé.");
                        }
                        $agency->decrement('current_balance', $amount);
                    }

                    Log::info("Encaisse physique mise à jour pour l'agence", [
                        'reference' => $reference,
                        'agency_id' => $agency->id,
                        'new_physical_balance' => $agency->fresh()->current_balance
                    ]);
                }

                // 6. CLÔTURE DE LA TRANSACTION PARENTE
                $transaction->update([
                    'status'       => 'completed',
                    'completed_at' => now()
                ]);

                // 7. JOURNALISATION D'AUDIT SÉCURITÉ (Base de données)
                SystemAuditLog::create([
                    'uuid'       => (string) Str::uuid(),
                    'user_id'    => $operator->id,
                    'agency_id'  => $agency->id,
                    'event_type' => 'VAULT.ADJUSTMENT',
                    'severity'   => $action === 'fund' ? 'info' : 'warning',
                    'message'    => "Ajustement de coffre effectué via transaction #{$reference}.",
                    'payload'    => [
                        'transaction_uuid' => $transaction->uuid,
                        'reference'        => $reference,
                        'action_type'      => $action,
                        'is_physical'      => $isPhysical,
                        'amount'           => $amount,
                        'description'      => $request->input('description')
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $transaction;
            });

            // Log d'application de succès final (Fichiers logs de Laravel)
            Log::info("Ajustement de coffre validé avec succès", [
                'reference'   => $transaction->reference,
                'operator_id' => $operator->id,
                'agency_id'   => $agency->id,
                'action'      => $action,
                'amount'      => $amount
            ]);

            return response()->json([
                'success' => true,
                'message' => "Ajustement de coffre traité, signé et enregistré sous la référence {$transaction->reference}.",
                'data'    => [
                    'reference' => $transaction->reference,
                    'status'    => $transaction->status
                ]
            ], 200);

        } catch (Exception $e) {
            // Log d'erreur critique avec contexte complet et trace
            Log::error("Échec critique de l'ajustement de coffre", [
                'message'     => $e->getMessage(),
                'operator_id' => $operator->id ?? null,
                'agency_uuid' => $uuid,
                'payload'     => $request->only(['action', 'amount', 'is_physical']),
                'trace'       => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
