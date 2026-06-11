<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\CashOperation;
use App\Models\Commission;
use App\Models\Country;
use App\Models\FraudCheck;
use App\Models\LoginHistory;
use App\Models\SystemAuditLog;
use App\Models\Till;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Wallet;
use App\Models\Staff;
use App\Services\FraudCheckService;
use App\Services\Reporting\ReportingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportingController extends Controller
{
    protected ReportingService $reportingService;
    protected $fraudService;

    /**
     * Injection du service de reporting global.
     */
    public function __construct(ReportingService $reportingService, FraudCheckService $fraudCheckService)
    {
        $this->reportingService = $reportingService;
        $this->fraudService = $fraudCheckService;
    }

    /**
     * DASHBOARD GLOBAL ADMIN
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:7d,30d,12m',
        ]);

        $period = $validated['period'] ?? '30d';

        $data = $this->reportingService->getDashboardMetrics($period);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * JOURNAL GLOBAL DES TRANSACTIONS
     * @param Request $request
     * @return JsonResponse
     */
    public function globalTransactions(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->with(['senderCountry', 'recipientCountry'])
            ->orderByDesc('created_at');

        // Filtre statut
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Recherche multicritère
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('sender_name', 'LIKE', "%{$search}%")
                    ->orWhere('recipient_name', 'LIKE', "%{$search}%")
                    ->orWhere('sender_phone', 'LIKE', "%{$search}%");
            });
        }

        $paginated = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        ], 200);
    }

    /**
     * DASHBOARD CAISSIER / AGENT (Indicateurs du tiroir-caisse décentralisé)
     */
    public function dashboardCashier(): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Alignement sur le rôle Spatie 'cashier'
            if (!$user->hasRole('cashier')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé. Profil réservé aux agents de guichet.'
                ], 403);
            }

            // 2. Recherche du profil métier (Rappel : $staff->till_id n'existe pas)
            $staff = Staff::with(['agency.country'])->where('user_id', $user->id)->first();

            if (!$staff || !$staff->agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune agence rattachée à votre profil opérateur.'
                ], 404);
            }

            $agency = $staff->agency;

            // 3. Identification de la caisse via le journal (Dernier Till ouvert ou manipulé par ce Staff)
            $lastOpeningOp = CashOperation::where('agency_id', $agency->id)
                ->where('staff_id', $staff->id)
                ->where('type', 'opening')
                ->orderByDesc('id')
                ->first();

            $till = null;
            if ($lastOpeningOp) {
                $till = Till::where('id', $lastOpeningOp->till_id)
                    ->where('agency_id', $agency->id)
                    ->first();
            }

            // Si l'agent n'a jamais ouvert de caisse ou qu'elle est introuvable
            if (!$till || !$till->is_active) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'session_status' => 'closed',
                        'opening_time' => null,
                        'currency' => $agency->country->currency_code ?? 'XAF',
                        'current_balance' => 0.0,
                        'today_deposits_count' => 0,
                        'today_deposits_amount' => 0.0,
                        'today_withdrawals_count' => 0,
                        'today_withdrawals_amount' => 0.0,
                        'recent_logs' => []
                    ]
                ], 200);
            }

            /**
             * 4. DÉTERMINATION DYNAMIQUE ET SÉCURISÉE DE L'ÉTAT DE SESSION
             */
            $lastCycleOp = CashOperation::where('till_id', $till->id)
                ->whereIn('type', ['opening', 'closing'])
                ->orderByDesc('id')
                ->first();

            $isOpen = $lastCycleOp && $lastCycleOp->type === 'opening' && $lastCycleOp->staff_id === $staff->id;

            /**
             * 5. CALCUL DES INDICATEURS MÉTIERS DE LA JOURNÉE (Fonds de roulement)
             * ALIGNEMENT :
             * - Versements/Dépôts clients = 'cash_in'
             * - Retraits clients = 'cash_out'
             */
            $today = Carbon::today();
            $operationsToday = CashOperation::where('till_id', $till->id)
                ->whereDate('created_at', $today)
                ->get();

            $cashInToday = $operationsToday->where('type', 'cash_in');
            $cashOutToday = $operationsToday->where('type', 'cash_out');

            /**
             * 6. RÉCUPÉRATION DES 5 DERNIÈRES ACTIONS SUR CE GUICHET
             */
            $recentLogs = CashOperation::where('till_id', $till->id)
                ->orderByDesc('id')
                ->take(5)
                ->get()
                ->map(function ($op) {
                    // Détermination comptable dynamique du flux visuel (crédit/débit du coffre)
                    $isCredit = in_array($op->type, ['opening', 'cash_in', 'adjustment']);
                    return [
                        'id' => $op->id,
                        'type' => $op->type,
                        'entry_type' => $isCredit ? 'credit' : 'debit',
                        'amount' => (float)$op->amount,
                        'reference' => 'OP-' . str_pad($op->id, 7, '0', STR_PAD_LEFT),
                        'description' => $op->description,
                        'time' => $op->created_at->format('H:i'),
                    ];
                })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_status' => $isOpen ? 'open' : 'closed',
                    'opening_time' => $isOpen && $lastCycleOp ? $lastCycleOp->created_at->format('H:i') : null,
                    'currency' => $agency->country->currency_code ?? 'XAF',
                    'current_balance' => (float)$till->current_balance,

                    // Métriques Dépôts / Versements Clients (cash_in)
                    'today_deposits_count' => $cashInToday->count(),
                    'today_deposits_amount' => (float)$cashInToday->sum('amount'),

                    // Métriques Retraits Clients (cash_out)
                    'today_withdrawals_count' => $cashOutToday->count(),
                    'today_withdrawals_amount' => (float)$cashOutToday->sum('amount'),

                    'recent_logs' => $recentLogs
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur dashboard caissier (User ID: " . Auth::id() . ") : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Impossible de charger les indicateurs de performance guichet."
            ], 500);
        }
    }

    /**
     * DASHBOARD COMPTABILITÉ & SUIVI RÉGIONAL (Pour les Country Admins)
     */

    public function getRegionalMetrics(): JsonResponse
    {
        $user = Auth::user();

        // 1. Sécurité : Vérifier l'habilitation Spatie
        if (!$user->hasRole('country_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès restreint à la hiérarchie régionale.'
            ], 403);
        }

        // 2. Extraction de la juridiction nationale assignée
        $staff = Staff::with(['country'])->where('user_id', $user->id)->first();
        $country = $staff?->country;

    if (!$country) {
        return response()->json([
            'success' => false,
            'message' => 'Aucune juridiction nationale assignée à votre profil.'
        ], 403);
    }

    // --- CORRECTION : CALCUL DU TOTAL WALLET BALANCE (Pays + Agences) ---

    // A. Récupération de la balance du portefeuille central du PAYS
    $countryWalletBalance = Wallet::where('owner_type', Country::class)
            ->where('owner_id', $country->id)
            ->where('type', 'main')
            ->where('is_active', true)
            ->value('balance') ?? 0;

    // B. Récupération de la Trésorerie Globale des AGENCES du pays
    $agenciesWalletBalance = Wallet::where('owner_type', Agency::class)
        ->where('type', 'main')
        ->where('is_active', true)
        ->whereHasMorph('owner', [Agency::class], function ($query) use ($country) {
            $query->where('country_id', $country->id);
        })
        ->sum('balance');

    // C. Consolidation totale
    $totalWalletBalance = (float)$countryWalletBalance + (float)$agenciesWalletBalance;

    // --- FIN DE LA CORRECTION ---

    // 3. Récupération de l'encaisse physique totale des guichets (Tills) du pays
    $totalPhysicalCash = Wallet::where('owner_type', 'App\Models\Till')
        ->where('type', 'main')
        ->where('is_active', true)
        ->whereIn('owner_id', function ($query) use ($country) {
            $query->select('id')
                ->from('tills')
                ->where('is_active', true)
                ->whereIn('agency_id', function ($subQuery) use ($country) {
                    $subQuery->select('id')
                        ->from('agencies')
                        ->where('country_id', $country->id);
                });
        })
        ->sum('balance');

    // 4. Chargement de la synthèse des agences (Relations Eloquent natives préférées aux whereRaw)
    $agencies = Agency::where('country_id', $country->id)
        ->with([
            'wallets' => function ($query) {
                $query->where('type', 'main')->where('is_active', true);
            },
            'tills' => function ($query) {
                $query->where('is_active', true);
            }
        ])
        ->withCount([
            'tills as total_tills_count' => function ($query) {
                $query->where('is_active', true);
            },
            // Compte des guichets ouverts basé sur l'état de la dernière opération
            'tills as open_tills_count' => function ($query) {
                $query->where('is_active', true)
                    ->whereHas('operations', function ($subQuery) {
                        $subQuery->whereIn('id', function ($q) {
                            $q->select(DB::raw('max(id)'))
                                ->from('cash_operations')
                                ->groupBy('till_id');
                        })->where('type', 'opening');
                    });
            }
        ])
        // Agrégation de la balance du portefeuille des guichets rattachés
        ->withSum(['wallets as total_till_cash' => function ($query) {
            $query->where('owner_type', 'App\Models\Till')
                ->where('type', 'main')
                ->where('is_active', true);
        }], 'balance')
        ->get();

    // 5. Structuration du tableau de synthèse des agences
    $agenciesSummary = [];
    $totalOpenTillsNetwork = 0;
    $totalActiveTillsNetwork = 0;

    foreach ($agencies as $agency) {
        $mainWallet = $agency->wallets->first();
        $walletBalance = $mainWallet ? (float)$mainWallet->balance : 0.00;
        $tillCash = (float)($agency->total_till_cash ?? 0);

        $totalOpenTillsNetwork += $agency->open_tills_count;
        $totalActiveTillsNetwork += $agency->total_tills_count;

        $agenciesSummary[] = [
            'id'                   => $agency->id,
            'name'                 => $agency->name,
            'code'                 => $agency->code ?? 'AG-' . $agency->id,
            'total_tills'          => (int)$agency->total_tills_count,
            'open_tills'           => (int)$agency->open_tills_count,
            'wallet_balance'       => $walletBalance,
            'vault_cash'           => $tillCash,
            'consolidated_balance' => $walletBalance + $tillCash,
            'status'               => $agency->status ?? 'active'
        ];
    }

    // 6. Réponse unifiée pour le Front-end
    return response()->json([
        'success' => true,
        'data' => [
            'region_name'           => $country->name,
            'country_vault_balance' => (float)$countryWalletBalance, // Ajouté pour transparence sur le front
            'total_wallet_balance'  => (float)$totalWalletBalance,   // Inclus désormais le solde Pays + Agences
            'total_physical_cash'   => (float)$totalPhysicalCash,
            'active_tills_count'    => $totalActiveTillsNetwork,
            'open_tills_count'      => $totalOpenTillsNetwork,
            'agencies_count'        => $agencies->count(),
            'currency'              => $country->currency_code ?? 'XAF',
            'user' => [
                'name' => trim($user->first_name . ' ' . $user->last_name)
            ],
            'agencies_summary'      => $agenciesSummary
        ]
    ], 200);
}

    /**
     * Obtenir les indicateurs de performance des agences pour l'administrateur connecté.
     */
    public function getRegionalAgencies(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staffProfile = Staff::where('user_id', $user->id)->first();

            // Initialisation de la requête sur les agences
            // On charge les relations nécessaires pour éviter le problème de requêtes N+1
            $query = Agency::with(['city', 'wallets', 'staff']);

            // Cloisonnement : Si ce n'est pas un Super Admin, on filtre obligatoirement par le pays de l'Admin Pays
            if (!$user->hasRole('super_admin')) {
                if (!$staffProfile || !$staffProfile->country_id) {
                    return response()->json(['message' => 'Périmètre géographique introuvable pour cet opérateur.'], 403);
                }
                $query->where('country_id', $staffProfile->country_id);
            }

            $agencies = $query->orderBy('name', 'asc')->get();

            // Formatage des données pour le composant Next.js
            $formatted = $agencies->map(function ($agency) {

                // 1. Calcul du cash total dans les portefeuilles de type 'main' ou 'vault' de l'agence
                $totalCash = $agency->wallets
                    ->whereIn('type', ['main', 'vault']) // À ajuster selon vos enums de Wallet
                    ->sum('balance');

                // 2. Calcul des guichets/caisses actifs (à adapter selon votre logique de session de caisse)
                // Exemple ici : on compte le staff rattaché à l'agence qui est actif et possède le rôle cashier
                $activeTillsCount = $agency->staff
                    ->where('is_active', true)
                    ->filter(function ($staff) {
                        return $staff->user ?->hasRole('cashier');
                    })->count();

                return [
                    'id' => $agency->id,
                    'uuid' => $agency->uuid,
                    'code' => $agency->code,
                    'name' => $agency->name,
                    'city' => $agency->city ?->name ?? '—',
                    'total_cash'              => (float)$totalCash,
                    'active_tills_count'      => $activeTillsCount,
                    'total_tills_count'       => $agency->staff->count(),
                    'status'                  => $agency->is_active ? 'active' : 'inactive',
                    'transactions_count_today'=> 0, // Optionnel : à lier avec votre table de transactions
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération des données du réseau : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir l'état de l'intégralité des caisses (Tills) pour le territoire de l'administrateur connecté.
     */
    public function getRegionalTills(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staffProfile = Staff::where('user_id', $user->id)->first();

            /*
             * REMARQUE ARCHITECTURALE :
             * Si vous possédez un modèle 'Till' ou 'CashDrawer', utilisez-le directement : Till::with(...)
             * Voici la logique de regroupement et de calcul standardisée pour votre architecture WalletRemittance.
             */

            // Étape 1 : Récupérer le personnel de type "caissier" (Cashier) rattaché au périmètre
            $staffQuery = Staff::with(['user', 'agency', 'wallets'])
                ->whereHas('user', function ($query) {
                    $query->whereHas('roles', function ($r) {
                        $r->where('name', 'cashier');
                    });
                });

            // Cloisonnement strict au pays de l'administrateur connecté
            if (!$user->hasRole('super_admin')) {
                if (!$staffProfile || !$staffProfile->country_id) {
                    return response()->json(['message' => 'Périmètre géographique introuvable.'], 403);
                }
                $staffQuery->where('country_id', $staffProfile->country_id);
            }

            $cashiers = $staffQuery->get();

            // Étape 2 : Modéliser les lignes de caisses (Tills) basées sur les portefeuilles de caisses
            $formattedTills = $cashiers->map(function ($cashier) {

                // Extraction du portefeuille de caisse (type 'till' ou 'cash_drawer')
                $tillWallet = $cashier->wallets->where('type', 'till')->first();
                $currentBalance = $tillWallet ? (float)$tillWallet->balance : 0.0;

                // Plafond de sécurité par défaut (ex: 5 000 000 XAF selon les règles d'assurance de l'agence)
                $maxLimit = $tillWallet && isset($tillWallet->max_limit) ? (float)$tillWallet->max_limit : 5000000.0;

                // Détermination de l'état de session de la caisse
                // Idéalement lié à une table 'cashier_sessions'. Exemple dynamique ici :
                $status = 'closed';
                if ($cashier->is_active && $tillWallet) {
                    $status = $cashier->user->is_active ? 'open' : 'locked';
                }

                return [
                    'uuid' => $tillWallet->uuid ?? $cashier->uuid,
                    'code' => $cashier->employee_code ? 'TILL-' . $cashier->employee_code : 'TILL-' . $cashier->id,
                    'label' => 'Caisse Guichet — ' . ($cashier->user->last_name ?? 'Agent'),
                    'current_balance' => $currentBalance,
                    'status' => $status, // 'open', 'closed', 'locked'
                    'cashier_name' => $cashier->user ? $cashier->user->first_name . ' ' . $cashier->user->last_name : 'Non assigné',
                    'agency_name' => $cashier->agency ? $cashier->agency->name : 'Hors-Guichet / Siège',
                    'max_limit' => $maxLimit,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedTills
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la consolidation des terminaux de caisse : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Centralise et audite le flux de transactions du territoire de l'administrateur connecté.
     */
    public function getRegionalTransactions(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            // On récupère le profil de l'agent connecté avec son pays de rattachement
            $staffProfile = Staff::where('user_id', $user->id)->first();

            // 1. Optimisation Eager Loading (Zéro problème N+1) basée sur votre schéma réel
            // On charge l'agence d'origine (sourceAgency) et le pays émetteur
            $query = Transaction::with(['sourceAgency', 'senderCountry']);

            // 2. Cloisonnement Géographique Strict (Sécurité Multi-Boutique / Multi-Pays)
            if (!$user->hasRole('super_admin')) {
                if (!$staffProfile || !$staffProfile->country_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Périmètre géographique introuvable pour cet opérateur.'
                    ], 403);
                }

                // Filtrage : On capte les flux financiers du territoire national de l'administrateur
                $query->where(function ($q) use ($staffProfile) {
                    $q->where('sender_country_id', $staffProfile->country_id)
                        ->orWhereHas('sourceAgency', function ($agencyQuery) use ($staffProfile) {
                            $agencyQuery->where('country_id', $staffProfile->country_id);
                        });
                });
            }

            // 3. Filtrage dynamique par Nature de Mouvement (Enums : cash_in, merchant_payment, etc.)
            if ($request->has('type') && $request->input('type') !== 'all') {
                $query->where('type', $request->input('type'));
            }

            // 4. Moteur de recherche textuelle indexé (Référence, KYC clients et Téléphones)
            if ($request->has('search') && !empty($request->input('search'))) {
                $search = trim(strtolower($request->input('search')));

                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'LIKE', "%{$search}%")
                        ->orWhere('sender_name', 'LIKE', "%{$search}%")
                        ->orWhere('recipient_name', 'LIKE', "%{$search}%")
                        ->orWhere('sender_phone', 'LIKE', "%{$search}%")
                        ->orWhere('recipient_phone', 'LIKE', "%{$search}%")
                        ->orWhere('secure_code', 'LIKE', "%{$search}%");
                });
            }

            // 5. Extraction du journal d'audit (Live Ledger) trié par récence - Limite de performance à 150
            $transactions = $query->orderBy('created_at', 'desc')->take(150)->get();

            // 6. Mapping et Normalisation de la charge utile (Payload JSON) pour Next.js
            $formatted = $transactions->map(function ($tx) {
                return [
                    'uuid' => $tx->uuid,
                    'reference' => $tx->reference,
                    'type' => $tx->type, // Ex: 'cash_in', 'transfer', 'merchant_payment', etc.
                    'amount' => (float)$tx->amount,
                    'fees' => (float)$tx->fees,
                    'taxes' => (float)$tx->taxes,
                    'currency' => $tx->currency, // Devise configurée (Default: XAF)
                    'status' => $tx->status,   // Ex: 'initiated', 'completed', 'reversed', etc.
                    'sender_name' => $tx->sender_name ?? 'Déposit / Système',
                    'sender_phone' => $tx->sender_phone,
                    'receiver_name' => $tx->recipient_name ?? '—', // Aligné sur votre colonne recipient_name
                    'receiver_phone' => $tx->recipient_phone,
                    'agency_name' => $tx->sourceAgency ? $tx->sourceAgency->name : 'Hors-Réseau / Distant',
                    'created_at' => $tx->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la génération du journal des transactions : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère l'annuaire des collaborateurs opérationnels du territoire de l'administrateur connecté.
     */
    public function getRegionalStaff(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staffProfile = Staff::where('user_id', $user->id)->first();

            // 1. Chargement des relations clés pour éviter le problème N+1 (user, agency, roles)
            $query = Staff::with(['user.roles', 'agency']);

            // 2. Cloisonnement Géographique : Un Admin Pays ne voit que les agents de son pays
            if (!$user->hasRole('super_admin')) {
                if (!$staffProfile || !$staffProfile->country_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Périmètre géographique introuvable pour cet opérateur.'
                    ], 403);
                }
                $query->where('country_id', $staffProfile->country_id);
            }

            // 3. Filtre par rôle fonctionnel (ex: 'cashier', 'agency_manager')
            if ($request->has('role') && $request->input('role') !== 'all') {
                $roleName = $request->input('role');
                $query->whereHas('user.roles', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            }

            // 4. Recherche textuelle multi-critères
            if ($request->has('search') && !empty($request->input('search'))) {
                $search = trim(strtolower($request->input('search')));

                $query->where(function ($q) use ($search) {
                    $q->where('employee_code', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%")
                                ->orWhere('phone', 'LIKE', "%{$search}%");
                        });
                });
            }

            $staffCollection = $query->orderBy('id', 'desc')->get();

            // 5. Mapping pour correspondre exactement aux clés lues par le composant Next.js
            $formatted = $staffCollection->map(function ($staff) {
                // Récupération du premier rôle attaché à l'utilisateur technique
                $role = $staff->user ?->roles->first();

                return [
                    'id' => $staff->id,
                    'uuid' => $staff->uuid,
                    'name' => $staff->user ? ($staff->user->first_name . ' ' . $staff->user->last_name) : 'Collaborateur Inconnu',
                    'email' => $staff->user ?->email ?? '—',
                    'phone'         => $staff->user ?->phone ?? $staff->phone ?? null,
                    'employee_code' => $staff->employee_code,
                    'role_name'     => $role ?->name ?? 'no_role',
                    'role_label'    => $role ?->title ?? $role ?->name ?? 'Non assigné', // Utilisez 'title' ou 'display_name' selon votre package de rôles
                    'agency_name'   => $staff->agency ?->name ?? 'Siège / Hors-Structure',
                    'is_active'     => (bool)$staff->is_active,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'extraction de l'annuaire des équipes : " . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupère l'historique complet des contrôles anti-fraude (Table fraud_checks)
     * Filtrable par statut de blocage, niveau de risque et recherche textuelle.
     */
    public function fraudCheckHistory(Request $request): JsonResponse
    {
        try {
            // 1. Initialisation de la requête avec les relations indispensables
            $query = FraudCheck::query()->with([
                'transaction:id,reference,type,status,amount,currency,sender_name,recipient_name'
            ]);

            // 2. FILTRE : Par indicateur de vigilance (is_flagged)
            if ($request->filled('is_flagged')) {
                $query->where('is_flagged', $request->boolean('is_flagged'));
            }

            // 3. FILTRE : Par sévérité de score (ex: ?risk_level=high)
            if ($request->filled('risk_level')) {
                switch ($request->risk_level) {
                    case 'high':
                        $query->where('risk_score', '>=', 80);
                        break;
                    case 'medium':
                        $query->whereBetween('risk_score', [40, 79]);
                        break;
                    case 'low':
                        $query->where('risk_score', '<', 40);
                        break;
                }
            }

            // 4. RECHERCHE TEXTUELLE MUTLICRITÈRE (Référence ou Motif de fraude)
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('reason', 'LIKE', "%{$search}%")
                        ->orWhereHas('transaction', function ($trxQuery) use ($search) {
                            $trxQuery->where('reference', 'LIKE', "%{$search}%")
                                ->orWhere('sender_name', 'LIKE', "%{$search}%")
                                ->orWhere('recipient_name', 'LIKE', "%{$search}%");
                        });
                });
            }

            // 5. PAGINATION ET TRI (Du plus récent au plus ancien)
            $perPage = (int)$request->input('per_page', 15);
            $checks = $query->orderByDesc('created_at')->paginate($perPage);

            // 6. NORMALISATION DES DONNÉES POUR L'UI NEXT.JS
            $formattedData = collect($checks->items())->map(function ($check) {
                // Déduction dynamique d'un badge de sévérité pour l'UI
                $severity = 'low';
                if ($check->risk_score >= 80) {
                    $severity = 'critical';
                } elseif ($check->risk_score >= 40) {
                    $severity = 'medium';
                }

                return [
                    'id' => $check->id,
                    'uuid' => $check->uuid,
                    'risk_score' => (int)$check->risk_score,
                    'severity' => $severity,
                    'is_flagged' => (bool)$check->is_flagged,
                    'is_blocked' => $check->risk_score >= 80, // Bloqué si score >= 80 selon vos règles
                    'reason' => $check->reason,
                    'date' => $check->created_at->toIso8601String(),
                    'transaction' => $check->transaction ? [
                        'id' => $check->transaction->id,
                        'reference' => $check->transaction->reference,
                        'type' => $check->transaction->type,
                        'status' => $check->transaction->status,
                        'amount' => (float)$check->transaction->amount,
                        'currency' => $check->transaction->currency,
                        'sender_name' => $check->transaction->sender_name,
                        'recipient_name' => $check->transaction->recipient_name,
                    ] : null,
                ];
            });

            // 7. ENVOI DE LA RÉPONSE STRUCTURÉE
            return response()->json([
                'success' => true,
                'message' => 'Registres de contrôle anti-fraude récupérés avec succès.',
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $checks->currentPage(),
                    'last_page' => $checks->lastPage(),
                    'total' => $checks->total(),
                    'per_page' => $checks->perPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des fraud_checks : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Une erreur technique est survenue lors de l'extraction des données de conformité."
            ], 500);
        }
    }

    /**
     * Récupère l'historique des événements et audits système.
     */
    public function systemeLogs(Request $request): JsonResponse
    {
        try {
            $query = SystemAuditLog::query()->with(['user:id,username,first_name,last_name', 'agency:id,name']);

            // 1. FILTRE : Par sévérité (info, warning, critical)
            if ($request->filled('severity')) {
                $query->where('severity', $request->severity);
            }

            // 2. FILTRE : Par type d'événement (ex: REMITTANCE.INITIATED)
            if ($request->filled('event_type')) {
                $query->where('event_type', $request->event_type);
            }

            // 3. RECHERCHE TEXTUELLE MUTLICRITÈRE (Message, IP, Code Employé)
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'LIKE', "%{$search}%")
                        ->orWhere('ip_address', 'LIKE', "%{$search}%")
                        ->orWhere('event_type', 'LIKE', "%{$search}%");
                });
            }

            // 4. PAGINATION AUTOMATIQUE
            $perPage = (int)$request->input('per_page', 20);
            $logs = $query->orderByDesc('created_at')->paginate($perPage);

            // 5. NORMALISATION POUR NEXT.JS
            $formattedLogs = collect($logs->items())->map(function ($log) {
                return [
                    'id' => $log->id,
                    'event_type' => $log->event_type,
                    'severity' => $log->severity,
                    'message' => $log->message,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'payload' => $log->payload, // Les métadonnées JSON de l'action
                    'date' => $log->created_at->toIso8601String(),
                    'operator' => $log->user ? [
                        'username' => $log->user->username,
                        'full_name' => $log->user->first_name . ' ' . $log->user->last_name,
                    ] : null,
                    'agency_name' => $log->agency ?->name ?? 'Système Central',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedLogs,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur extraction Logs Système : " . $e->getMessage());
            return response()->json(['success' => false, 'message' => "Erreur serveur."], 500);
        }
    }

    /**
     * Liste l'historique des tentatives de connexion à la plateforme.
     */

    public function historyLogs(Request $request): JsonResponse
    {
        try {
            // 1. Initialisation de la requête avec la relation User pour éviter le problème N+1
            $query = LoginHistory::query()->with(['user:id,first_name,last_name,username']);

            // 2. FILTRE : Statut (success, failed, etc.)
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // 3. RECHERCHE TEXTUELLE MULTICRITÈRE (Téléphone tenté, IP, ou nom de l'utilisateur résolu)
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('phone_attempted', 'LIKE', "%{$search}%")
                        ->orWhere('ip_address', 'LIKE', "%{$search}%")
                        ->orWhere('user_agent', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('username', 'LIKE', "%{$search}%")
                                ->orWhere('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%");
                        });
                });
            }

            // 4. PAGINATION ET TRI (Utilisation de created_at décroissant comme configuré dans votre modèle)
            $perPage = (int)$request->input('per_page', 20);
            $logs = $query->orderByDesc('created_at')->paginate($perPage);

            // 5. MAPPING ET NORMALISATION DES DONNÉES POUR L'UI NEXT.JS
            $formattedLogs = collect($logs->items())->map(function ($log) {
                return [
                    'id' => $log->id,
                    'phone_attempted' => $log->phone_attempted,
                    'ip_address' => $log->ip_address,
                    'status' => $log->status,
                    'failure_reason' => $log->failure_reason,
                    'user_agent' => $log->user_agent,
                    'date' => $log->created_at ? $log->created_at->toIso8601String() : null,
                    'logged_out_at' => $log->logged_out_at ? $log->logged_out_at->toIso8601String() : null,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'username' => $log->user->username,
                        'full_name' => $log->user->first_name . ' ' . $log->user->last_name,
                    ] : null,
                    'agency_id' => $log->agency_id, // Utile si vous voulez matcher avec un store d'agences au front
                ];
            });

            // 6. ENVOI DE LA RÉPONSE COMPATIBLE
            return response()->json([
                'success' => true,
                'data' => $formattedLogs,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                    'per_page' => $logs->perPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur d'extraction des Logs de Connexion (LoginHistory) : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => "Une erreur technique est survenue lors de la lecture des registres d'accès."
            ], 500);
        }
    }

    /**
     * Génère les données de prévisualisation en fonction du type de document.
     */
    public function previewDocument(Request $request): JsonResponse
    {
        $request->validate([
            'doc_type' => 'required|in:grand_livre,balance_wallets,synthese_retrocessions'
        ]);

        $docType = $request->doc_type;
        $data = [];

        switch ($docType) {
            case 'grand_livre':
                // 1. Chargement des commissions avec la relation Wallet et son propriétaire polymorphique (owner)
                $data = Commission::with([
                    'transaction:id,reference,amount,currency',
                    'wallet.owner'
                ])
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                    ->map(function ($com) {
                        $wallet = $com->wallet;
                        $displayName = 'Système';

                        // Résolution du nom du propriétaire pour le Grand Livre
                        if ($wallet && $wallet->owner) {
                            $owner = $wallet->owner;
                            if (isset($owner->name)) {
                                $displayName = $owner->name;
                            } elseif (isset($owner->first_name) || isset($owner->last_name)) {
                                $displayName = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
                            } else {
                                $displayName = class_basename($wallet->owner_type) . ' N°' . $wallet->owner_id;
                            }
                        }

                        return [
                            'col1' => $com->created_at->format('d/m/Y H:i'),
                            'col2' => $com->transaction ?->reference ?? 'N/A',
                'col3' => $displayName . ($wallet ? " ({$wallet->wallet_number})" : ""),
                'col4' => number_format($com->transaction ?->amount ?? 0) . ' ' . ($com->transaction ?->currency ?? 'XAF'),
                'col5' => number_format($com->amount) . ' ' . ($com->transaction ?->currency ?? 'XAF'),
            ];
        });

                $headers = ['Date/Heure', 'Réf. Transaction', 'Compte Affecté (Titulaire)', 'Volume Principal', 'Commission'];
                break;

            case 'balance_wallets':
                // 1. Calcul des cumuls financiers groupés par wallet_id
                $rawData = Commission::select(
                    'wallet_id',
                    DB::raw('SUM(amount) as total_commissions'),
                    DB::raw('COUNT(id) as count_operations')
                )
                    ->groupBy('wallet_id')
                    ->orderByDesc('total_commissions')
                    ->limit(10)
                    ->get();

                // 2. Chargement optimisé de la relation polymorphique pour éviter le problème N+1
                $rawData->load(['wallet.owner']);

                // 3. Normalisation et formatage pour l'interface Next.js
                $data = $rawData->map(function ($row) {
                    $wallet = $row->wallet;
                    $displayName = 'Compte Inconnu';

                    if ($wallet && $wallet->owner) {
                        $owner = $wallet->owner;

                        if (isset($owner->name)) {
                            $displayName = $owner->name;
                        } elseif (isset($owner->first_name) || isset($owner->last_name)) {
                            $displayName = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
                        } else {
                            $displayName = class_basename($wallet->owner_type) . ' N°' . $wallet->owner_id;
                        }
                    }

                    return [
                        'col1' => $displayName,
                        'col2' => $row->count_operations . ' transaction(s)',
                        'col3' => $wallet ? $wallet->wallet_number : 'N/A',
                        'col4' => $wallet ? strtoupper($wallet->type) : 'N/A',
                        'col5' => number_format($row->total_commissions) . ' ' . ($wallet->currency ?? 'XAF'),
                    ];
                });

                $headers = ['Titulaire (Owner)', 'Activité', 'Numéro de Compte', 'Type de Wallet', 'Solde Commissions'];
                break;

            case 'synthese_retrocessions':
                // Analyse par taux appliqué (Rapprochement analytique)
                $data = Commission::select(
                    'percentage',
                    DB::raw('SUM(amount) as volume_gagne'),
                    DB::raw('AVG(amount) as moyenne')
                )
                    ->groupBy('percentage')
                    ->orderByDesc('percentage')
                    ->get()
                    ->map(function ($row) {
                        return [
                            'col1' => "Règle tarifaire à " . $row->percentage . "%",
                            'col2' => 'N/A',
                            'col3' => 'N/A',
                            'col4' => 'Moyenne : ' . number_format($row->moyenne, 2) . ' XAF',
                            'col5' => number_format($row->volume_gagne) . ' XAF',
                        ];
                    });

                $headers = ['Clé de Répartition', '', '', 'Indicateur Moyen', 'Total Collecté'];
                break;
        }

        return response()->json([
            'success' => true,
            'headers' => $headers,
            'rows' => $data
        ]);
    }
}
