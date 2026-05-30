<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\CashOperation;
use App\Models\Till;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Wallet;
use App\Models\Staff;
use App\Services\Reporting\ReportingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportingController extends Controller
{
    protected ReportingService $reportingService;

    /**
     * Injection du service de reporting global.
     */
    public function __construct(ReportingService $reportingService)
    {
        $this->reportingService = $reportingService;
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
            'data'    => $data
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
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
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
                        'session_status'           => 'closed',
                        'opening_time'             => null,
                        'currency'                 => $agency->country->currency_code ?? 'XAF',
                        'current_balance'          => 0.0,
                        'today_deposits_count'     => 0,
                        'today_deposits_amount'    => 0.0,
                        'today_withdrawals_count'  => 0,
                        'today_withdrawals_amount' => 0.0,
                        'recent_logs'              => []
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

            $cashInToday  = $operationsToday->where('type', 'cash_in');
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
                        'id'          => $op->id,
                        'type'        => $op->type,
                        'entry_type'  => $isCredit ? 'credit' : 'debit',
                        'amount'      => (float) $op->amount,
                        'reference'   => 'OP-' . str_pad($op->id, 7, '0', STR_PAD_LEFT),
                        'description' => $op->description,
                        'time'        => $op->created_at->format('H:i'),
                    ];
                })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_status'           => $isOpen ? 'open' : 'closed',
                    'opening_time'             => $isOpen && $lastCycleOp ? $lastCycleOp->created_at->format('H:i') : null,
                    'currency'                 => $agency->country->currency_code ?? 'XAF',
                    'current_balance'          => (float) $till->current_balance,

                    // Métriques Dépôts / Versements Clients (cash_in)
                    'today_deposits_count'     => $cashInToday->count(),
                    'today_deposits_amount'    => (float) $cashInToday->sum('amount'),

                    // Métriques Retraits Clients (cash_out)
                    'today_withdrawals_count'  => $cashOutToday->count(),
                    'today_withdrawals_amount' => (float) $cashOutToday->sum('amount'),

                    'recent_logs'              => $recentLogs
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

        // 2. Extraction du pays géré depuis le profil Staff
        $staff = Staff::with(['country'])->where('user_id', $user->id)->first();
        $country = $staff?->country;

        if (!$country) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune juridiction nationale assignée à votre profil.'
            ], 403);
        }

        // 3. Collecte du périmètre des agences nationales
        $agencies = Agency::where('country_id', $country->id)->get();
        $agencyIds = $agencies->pluck('id');

        // 4. Calculs des indicateurs macro-économiques (Cash et Guichets)
        $totalCash = Till::whereIn('agency_id', $agencyIds)->where('is_active', true)->sum('current_balance');
        $activeTillsCount = Till::whereIn('agency_id', $agencyIds)->where('is_active', true)->count();

        $openTillsCount = 0;
        $agenciesSummary = [];

        foreach ($agencies as $agency) {
            $tills = Till::where('agency_id', $agency->id)->where('is_active', true)->get();
            $agencyOpenTills = 0;
            $agencyBalance = 0.00;

            foreach ($tills as $till) {
                $agencyBalance += (float) $till->current_balance;

                // Statut opérationnel immédiat en direct du guichet
                $lastOp = CashOperation::where('till_id', $till->id)
                    ->whereIn('type', ['opening', 'closing'])
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastOp && $lastOp->type === 'opening') {
                    $agencyOpenTills++;
                    $openTillsCount++;
                }
            }

            $agenciesSummary[] = [
                'id'                   => $agency->id,
                'name'                 => $agency->name,
                'code'                 => $agency->code ?? 'AG-' . $agency->id,
                'total_tills'          => $tills->count(),
                'open_tills'           => $agencyOpenTills,
                'consolidated_balance' => $agencyBalance,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'region_name'        => $country->name,
                'total_cash'         => (float) $totalCash,
                'active_tills_count' => $activeTillsCount,
                'open_tills_count'   => $openTillsCount,
                'agencies_count'     => $agencies->count(),
                'currency'           => $country->currency_code ?? 'XAF',
                'user' => [
                    'name' => $user->first_name . ' ' . $user->last_name
                ],
                'agencies_summary'   => $agenciesSummary
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
                        return $staff->user?->hasRole('cashier');
                    })->count();

                return [
                    'id'                      => $agency->id,
                    'uuid'                    => $agency->uuid,
                    'code'                    => $agency->code,
                    'name'                    => $agency->name,
                    'city'                    => $agency->city?->name ?? '—',
                    'total_cash'              => (float) $totalCash,
                    'active_tills_count'      => $activeTillsCount,
                    'total_tills_count'       => $agency->staff->count(),
                    'status'                  => $agency->is_active ? 'active' : 'inactive',
                    'transactions_count_today'=> 0, // Optionnel : à lier avec votre table de transactions
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
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
                $currentBalance = $tillWallet ? (float) $tillWallet->balance : 0.0;

                // Plafond de sécurité par défaut (ex: 5 000 000 XAF selon les règles d'assurance de l'agence)
                $maxLimit = $tillWallet && isset($tillWallet->max_limit) ? (float) $tillWallet->max_limit : 5000000.0;

                // Détermination de l'état de session de la caisse
                // Idéalement lié à une table 'cashier_sessions'. Exemple dynamique ici :
                $status = 'closed';
                if ($cashier->is_active && $tillWallet) {
                    $status = $cashier->user->is_active ? 'open' : 'locked';
                }

                return [
                    'uuid'            => $tillWallet->uuid ?? $cashier->uuid,
                    'code'            => $cashier->employee_code ? 'TILL-' . $cashier->employee_code : 'TILL-' . $cashier->id,
                    'label'           => 'Caisse Guichet — ' . ($cashier->user->last_name ?? 'Agent'),
                    'current_balance' => $currentBalance,
                    'status'          => $status, // 'open', 'closed', 'locked'
                    'cashier_name'    => $cashier->user ? $cashier->user->first_name . ' ' . $cashier->user->last_name : 'Non assigné',
                    'agency_name'     => $cashier->agency ? $cashier->agency->name : 'Hors-Guichet / Siège',
                    'max_limit'       => $maxLimit,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formattedTills
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
                    'uuid'            => $tx->uuid,
                    'reference'       => $tx->reference,
                    'type'            => $tx->type, // Ex: 'cash_in', 'transfer', 'merchant_payment', etc.
                    'amount'          => (float) $tx->amount,
                    'fees'            => (float) $tx->fees,
                    'taxes'           => (float) $tx->taxes,
                    'currency'        => $tx->currency, // Devise configurée (Default: XAF)
                    'status'          => $tx->status,   // Ex: 'initiated', 'completed', 'reversed', etc.
                    'sender_name'     => $tx->sender_name ?? 'Déposit / Système',
                    'sender_phone'    => $tx->sender_phone,
                    'receiver_name'   => $tx->recipient_name ?? '—', // Aligné sur votre colonne recipient_name
                    'receiver_phone'  => $tx->recipient_phone,
                    'agency_name'     => $tx->sourceAgency ? $tx->sourceAgency->name : 'Hors-Réseau / Distant',
                    'created_at'      => $tx->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
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
                $role = $staff->user?->roles->first();

                return [
                    'id'            => $staff->id,
                    'uuid'          => $staff->uuid,
                    'name'          => $staff->user ? ($staff->user->first_name . ' ' . $staff->user->last_name) : 'Collaborateur Inconnu',
                    'email'         => $staff->user?->email ?? '—',
                    'phone'         => $staff->user?->phone ?? $staff->phone ?? null,
                    'employee_code' => $staff->employee_code,
                    'role_name'     => $role?->name ?? 'no_role',
                    'role_label'    => $role?->title ?? $role?->name ?? 'Non assigné', // Utilisez 'title' ou 'display_name' selon votre package de rôles
                    'agency_name'   => $staff->agency?->name ?? 'Siège / Hors-Structure',
                    'is_active'     => (bool) $staff->is_active,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'extraction de l'annuaire des équipes : " . $e->getMessage()
            ], 500);
        }
    }
}
