<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
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
     */
    public function adjustVault(Request $request, string $uuid): JsonResponse
    {
        // Alignement des rôles Spatie
        if (!Auth::user()->hasAnyRole(['super_admin', 'country_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Action refusée. Niveau d\'accréditation insuffisant.'
            ], 403);
        }

        $request->validate([
            'action'      => 'required|string|in:fund,debit',
            'amount'      => 'required|numeric|min:5000',
            'description' => 'required|string|max:255|min:5',
        ]);

        try {
            $operator = Auth::user();
            $agency = Agency::where('uuid', $uuid)->firstOrFail();
            $amount = (float) $request->input('amount');
            $action = $request->input('action');

            DB::transaction(function () use ($operator, $agency, $amount, $action, $request) {

                // Verrouillage pessimiste de la ligne pour éviter les race conditions de solde (FinTech-safe)
                $agencyWallet = Wallet::where('owner_type', Agency::class)
                    ->where('owner_id', $agency->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$agencyWallet || !$agencyWallet->is_active) {
                    throw new Exception("Coffre d'agence introuvable, suspendu ou non initialisé.");
                }

                $balanceBefore = (float) $agencyWallet->balance;

                if ($action === 'fund') {
                    $agencyWallet->balance = $balanceBefore + $amount;
                    $entryType = 'credit';
                } else {
                    if ($balanceBefore < $amount) {
                        throw new Exception("Solde de coffre insuffisant pour effectuer ce décaissement.");
                    }
                    $agencyWallet->balance = $balanceBefore - $amount;
                    $entryType = 'debit';
                }

                $agencyWallet->save();

                // Signature cryptographique de la ligne du registre Ledger pour l'immuabilité financière
                $signature = $this->ledgerService->generateSignature(
                    $agencyWallet->id,
                    $amount,
                    $entryType,
                    $balanceBefore,
                    (float) $agencyWallet->balance
                );

                // Écriture dans le grand livre comptable (Ledger Entry)
                TransactionEntry::create([
                    'transaction_id' => null, // NULL car il s'agit d'un réajustement de fonds propre et non d'un virement client
                    'wallet_id'      => $agencyWallet->id,
                    'entry_type'     => $entryType,
                    'amount'         => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $agencyWallet->balance,
                    'row_signature'  => $signature
                ]);

                // Synchronisation de la colonne de cache dénormalisée sur la table agences
                if ($action === 'fund') {
                    $agency->increment('current_balance', $amount);
                } else {
                    $agency->decrement('current_balance', $amount);
                }

                // Journalisation d'audit de sécurité
                SystemAuditLog::create([
                    'uuid'       => (string) Str::uuid(),
                    'user_id'    => $operator->id,
                    'agency_id'  => $agency->id,
                    'event_type' => 'VAULT.ADJUSTMENT',
                    'severity'   => $action === 'fund' ? 'info' : 'warning',
                    'message'    => "Ajustement manuel du coffre de l'agence par l'opérateur. Action : {$action}.",
                    'payload'    => [
                        'agency_code'    => $agency->code,
                        'action_type'    => $action,
                        'amount'         => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $agencyWallet->balance,
                        'description'    => $request->input('description')
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => "Ajustement de coffre effectué et signé cryptographiquement avec succès."
            ], 200);

        } catch (Exception $e) {
            Log::error("Échec ajustement coffre : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
