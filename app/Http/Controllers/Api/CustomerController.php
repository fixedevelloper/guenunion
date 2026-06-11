<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\User;
use App\Models\Staff;
use App\Models\Wallet;
use App\Services\LedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    protected LedgerService $ledgerService;

    /**
     * Injection du LedgerService pour sécuriser cryptographiquement les mouvements de coffre.
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }
    /**
     * RECHERCHE PRÉDICTIVE / FILTRE DES CLIENTS (Pour le guichet de caisse).
     * Accessible par : merchant, cashier, manager, country_admin, super_admin.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Jointure obligatoire avec 'user' pour interroger l'état civil réel du client
            $query = Customer::with('user');

            // Barrière géographique : Un Admin Pays ne peut rechercher que les clients enregistrés dans son pays
            if ($user->hasRole('country_admin') && $staff) {
                $query->where('country_id', $staff->country_id);
            }

            // Recherche globale croisée (Nom, Prénom, Téléphone) sur la table 'users' liée
            if ($request->has('search')) {
                $search = $request->input('search');

                if (is_numeric(preg_replace('/[^0-9]/', '', $search))) {
                    $cleanPhone = clean_phone($search);
                    $query->whereHas('user', function ($q) use ($cleanPhone) {
                        $q->where('phone_number', 'LIKE', "%{$cleanPhone}%");
                    });
                } else {
                    $query->whereHas('user', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%");
                    });
                }
            }

            // Filtrer par état de compte (Par défaut : comptes actifs pour les opérations de guichet)
            $status = $request->input('status', 'active');
            $query->where('status', $status);

            // Limitation pour optimiser la performance de l'autocomplétion sur l'UI Next.js
            $customers = $query->whereHas('user')
              //  ->handleSort($query) // Utilisation de votre scope ou tri standard
                ->limit(10)
                ->get();

            // Normalisation à plat pour simplifier l'intégration avec React Query / Select Components
            $formatted = $customers->map(function ($c) {
                return [
                    'id'             => $c->id,
                    'uuid'           => $c->uuid,
                    'reference'      => $c->reference,
                    'first_name'     => $c->user->first_name,
                    'last_name'      => $c->user->last_name,
                    'display_name'   => $c->user->first_name . ' ' . $c->user->last_name,
                    'phone_number'   => $c->user->phone_number,
                    'email'          => $c->user->email,
                    'id_type'        => $c->id_type,
                    'id_number'      => $c->id_number,
                    'id_expiry_date' => $c->id_expiry_date,
                    'kyc_level'      => $c->kyc_level,
                    'status'         => $c->status,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la recherche client : " . $e->getMessage()
            ], 500);
        }
    }
    public function customers(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Eager loading de 'user' et du 'wallet' principal pour optimiser les performances
            $query = Customer::with(['user', 'wallets' => function ($query) {
                $query->where('type', 'main');
            }]);

            // Barrière géographique : Un Admin Pays ne voit que les clients de son pays
            if ($user->hasRole('country_admin') && $staff) {
                $query->where('country_id', $staff->country_id);
            }

            // Recherche globale croisée (Nom, Prénom, Téléphone)
            if ($request->has('search') && !empty($request->input('search'))) {
                $search = $request->input('search');

                if (is_numeric(preg_replace('/[^0-9]/', '', $search))) {
                    $cleanPhone = clean_phone($search);
                    $query->whereHas('user', function ($q) use ($cleanPhone) {
                        $q->where('phone_number', 'LIKE', "%{$cleanPhone}%");
                    });
                } else {
                    $query->whereHas('user', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%");
                    });
                }
            }

            // Filtrer par état de compte (Par défaut : comptes actifs)
            $status = $request->input('status', 'active');
            $query->where('status', $status);


                $query = $query->orderBy('id', 'desc'); // RE-ASSIGNATION ICI

            // Limitation pour l'autocomplétion UI
            $customers = $query->whereHas('user')
                ->limit(10)
                ->get();

            // Normalisation à plat (Correction des variables $customer vers $c)
            $formatted = $customers->map(function ($c) {
                return [
                    'id'             => $c->id,
                    'reference'      => $c->reference,
                    'name'           => $c->user ? "{$c->user->first_name} {$c->user->last_name}" : 'N/A',
                    'phone'          => $c->user ? $c->user->phone_number : 'N/A',
                    'id_type'        => $c->id_type,
                    'id_number'      => $c->id_number,
                    'id_expiry_date' => $c->id_expiry_date,
                    'email'          => $c->user ? $c->user->email : null,
                    'kyc_status'     => $c->kyc_status,
                    'status'         => $c->status,
                    'wallet'         => $c->mainWallet ? [
                        'wallet_number' => $c->mainWallet->wallet_number,
                        'balance'       => (float) $c->mainWallet->balance,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche client : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la recherche client : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ENREGISTRER UN NOUVEAU CLIENT AVEC VÉRIFICATION KYC (Au guichet).
     * Accessible par : merchant, cashier, manager.
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();

        // Récupération sécurisée du Till actif via la relation
        $till = $staff?->currentTill;

    // 1. Prétraitement des données
    if ($request->has('phone')) {
        $request->merge(['phone' => clean_phone($request->input('phone'))]);
    }

    // 2. Validation des données
    $validated = $request->validate([
        'first_name'      => 'required|string|max:100',
        'last_name'       => 'required|string|max:100',
        'phone'           => 'required|string|unique:users,phone_number',
        'email'           => 'nullable|email|unique:users,email',
        'id_type'         => 'required|string|in:CNI,PASSPORT,DRIVING_LICENSE,REFUGEE_CARD',
        'id_number'       => 'required|string|max:50|unique:customers,id_number',
        'id_expiry'       => 'required|date|after:today',
        'dob'             => 'required|date|before:-18 years',
        'address'         => 'nullable|string',
        'country_id'      => 'required|exists:countries,id',
        'city_id'         => 'required|exists:cities,id',
        'initial_balance' => 'nullable|numeric|min:0',
    ]);

    // 3. Protection de juridiction territoriale stricte pour l'agent
    if ($user->hasAnyRole(['country_admin', 'manager', 'cashier'])) {
        if (!$staff) {
            return response()->json([
                'success' => false,
                'message' => "Action refusée : Aucun profil d'opérateur valide associé à votre compte."
            ], 403);
        }

        if ((int)$validated['country_id'] !== (int)$staff->country_id) {
            return response()->json([
                'success' => false,
                'message' => "Action interdite : Vous ne pouvez pas immatriculer un client en dehors de votre pays d'affectation."
            ], 403);
        }
    }

    // Garde-fou de sécurité : Si dépôt initial demandé, la présence d'une caisse est obligatoire
    $initialBalance = (float) ($validated['initial_balance'] ?? 0);
    if ($initialBalance > 0 && !$till) {
        return response()->json([
            'success' => false,
            'message' => "Opération impossible : Vous devez être assigné à une caisse/guichet active pour percevoir un dépôt initial."
        ], 422);
    }

    try {
        // Isolation de la transaction au niveau REPEATABLE READ ou SERIALIZABLE si nécessaire pour la compta
        return DB::transaction(function () use ($till, $validated, $user, $staff, $initialBalance) {

            $cashierWallet = null;

            if ($initialBalance > 0) {
                // Verrouillage pessimiste immédiat de la caisse pour éviter la double dépense concourante
                $cashierWallet = Wallet::where('owner_type', 'App\Models\Till')
                    ->where('owner_id', $till->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$cashierWallet || !$cashierWallet->is_active) {
                    throw new \Exception("Votre coffre/guichet physique est introuvable, suspendu ou non initialisé.", 422);
                }

                // Barrière de Sécurité Financière : Vérification de la provision de la caisse
                if ((float)$cashierWallet->balance < $initialBalance) {
                    throw new \Exception("Fonds insuffisants dans votre encaisse de guichet pour valider cette dotation.", 422);
                }
            }

            // Déplacement de la génération des identifiants uniques DANS la transaction (Réduction des Race Conditions)
            $usernameBase = strtolower($validated['first_name'][0] . Str::slug($validated['last_name']));
            $username = $usernameBase . rand(100, 999);
            while (User::where('username', $username)->exists()) {
                $username = $usernameBase . rand(100, 999);
            }

            // 1. Création de l'utilisateur sécurisé
            $customerUser = User::create([
                'uuid'         => (string) Str::uuid(),
                'username'     => $username,
                'first_name'   => strtoupper($validated['first_name']),
                'last_name'    => ucwords(strtolower($validated['last_name'])),
                'phone_number' => $validated['phone'],
                'email'        => $validated['email'] ?? null,
                'password'     => Hash::make('Default@2026'), // Exiger un reset au premier login
                'is_active'    => true,
            ]);

            if (method_exists($customerUser, 'assignRole')) {
                $customerUser->assignRole('customer');
            }

            // Génération d'une référence client unique
            do {
                $reference = 'CLI-' . strtoupper(Str::random(8));
            } while (Customer::where('reference', $reference)->exists());

            // 2. Création du profil KYC approuvé au guichet
            $customer = Customer::create([
                'uuid'           => (string) Str::uuid(),
                'user_id'        => $customerUser->id,
                'reference'      => $reference,
                'birth_date'     => $validated['dob'],
                'id_type'        => $validated['id_type'],
                'id_number'      => strtoupper($validated['id_number']),
                'id_expiry_date' => $validated['id_expiry'],
                'country_id'     => $validated['country_id'],
                'city_id'        => $validated['city_id'],
                'address'        => $validated['address'],
                'kyc_level'      => 'full',
                'kyc_status'     => 'approved',
                'status'         => 'active',
            ]);

            // Génération d'un numéro de portefeuille unique
            do {
                $walletNumber = 'WLT-CLN-' . strtoupper(Str::random(10));
            } while (Wallet::where('wallet_number', $walletNumber)->exists());

            // 3. Création du portefeuille du client
            $customerWallet = Wallet::create([
                'uuid'          => (string) Str::uuid(),
                'owner_id'      => $customer->id,
                'owner_type'    => Customer::class,
                'wallet_number' => $walletNumber,
                'type'          => 'main',
                'currency'      => $cashierWallet ? $cashierWallet->currency : 'XAF',
                'balance'       => 0.00,
                'is_active'     => true,
            ]);

            // 4. Traçabilité financière hermétique (Double-entrée comptable)
            if ($initialBalance > 0 && $cashierWallet) {

                $txReference = 'TX-INIT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                // A. Création de la transaction parente scellée
                $transaction = Transaction::create([
                    'uuid'         => (string) Str::uuid(),
                    'reference'    => $txReference,
                    'type'         => 'cash_in',
                    'status'       => 'initiated',
                    'amount'       => $initialBalance,
                    'fees'         => 0,
                    'taxes'        => 0,
                    'currency'     => $customerWallet->currency,
                    'initiator_id' => $user->id,
                        'source_till_id'        => $till->id,
                    'description'  => "Dotation initiale de fonds à la création du compte via guichet #{$till->id}.",
                    'metadata'     => [
                        'channel'            => 'guichet',
                        'till_uuid'          => $till?->uuid ?? null,
                        'customer_reference' => $customer->reference
                    ]
                ]);

                $cashierBalanceBefore = (float) $cashierWallet->balance;
                $customerBalanceBefore = (float) $customerWallet->balance;

                // Application stricte des écritures
                $cashierWallet->decrement('balance', $initialBalance);
                $customerWallet->increment('balance', $initialBalance);

                // B. Grand Livre : Écriture Débit Caisse (Sortie de provision virtuelle contre Cash Physique reçu)
                // Éviter ->fresh() inutile car decrement/increment hydratent déjà l'objet en mémoire
                $cashierSignature = $this->ledgerService->generateSignature(
                    $cashierWallet->id, $initialBalance, 'debit', $cashierBalanceBefore, (float) $cashierWallet->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $cashierWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $initialBalance,
                    'balance_before' => $cashierBalanceBefore,
                    'balance_after'  => $cashierWallet->balance,
                    'row_signature'  => $cashierSignature
                ]);

                // Grand Livre : Écriture Crédit Compte Client
                $customerSignature = $this->ledgerService->generateSignature(
                    $customerWallet->id, $initialBalance, 'credit', $customerBalanceBefore, (float) $customerWallet->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $customerWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $initialBalance,
                    'balance_before' => $customerBalanceBefore,
                    'balance_after'  => $customerWallet->balance,
                    'row_signature'  => $customerSignature
                ]);

                // C. Mutation définitive du statut de la transaction
                $transaction->update([
                    'status'       => 'completed',
                    'completed_at' => now()
                ]);
            }

         //   Log::info("KYC_SECURE_ARCH: Client [{$customer->reference}] enregistré. Opérateur [ID: {$user->id}] au guichet [#{$till?->id ?? 'N/A'}]. Trésorerie synchronisée.");

            return response()->json([
                'success' => true,
                'message' => 'Profil client créé et validé avec succès au guichet.',
                'data'    => [
                    'id'            => $customer->id,
                    'reference'     => $customer->reference,
                    'name'          => "{$customerUser->first_name} {$customerUser->last_name}",
                    'phone'         => $customerUser->phone_number,
                    'wallet_number' => $customerWallet->wallet_number,
                    'balance'       => (float) $customerWallet->balance
                ]
            ], 201);
        });

    } catch (\Exception $e) {
        Log::critical("ÉCHEC CRITIQUE IMMATRICULATION GUICHET : " . $e->getMessage(), [
            'operator_id' => Auth::id(),
            'till_id'     => $till?->id ?? 'Aucun',
            'payload'     => $request->except(['password']),
            'trace'       => $e->getTraceAsString()
        ]);

        // Transtypage explicite (int) pour blinder la comparaison du code d'erreur
        return response()->json([
            'success' => false,
            'message' => (int)$e->getCode() === 422 ? $e->getMessage() : 'Une erreur de sécurité ou de conformité système bloque l\'immatriculation.'
        ], 422);
    }
}
    public function update(Request $request, string $uuid): JsonResponse
    {
             // 1. Récupération du client et de son utilisateur associé via l'UUID du profil Customer
        $customer = Customer::where('uuid', $uuid)->first();
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable.'
            ], 404);
        }
        $customerUser = $customer->user;

        // 2. Prétraitement des données (nettoyage du téléphone)
        if ($request->has('phone')) {
            $request->merge(['phone' => clean_phone($request->input('phone'))]);
        }

        // 3. Validation des données (avec exclusion des IDs actuels pour les règles uniques)
        $validated = $request->validate([
            // Données d'identité (Table Users)
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'required|string|unique:users,phone_number,' . $customerUser->id,
            'email'      => 'nullable|email|unique:users,email,' . $customerUser->id,
            // Données KYC / Profil (Table Customers)
            'dob'        => 'required|date|before:-18 years',
            'address'    => 'nullable|string',
            'city_id'    => 'required|exists:cities,id',
        ]);

        try {
            return DB::transaction(function () use ($validated, $customer, $customerUser) {

                // 1. Mise à jour de l'utilisateur (Identité)
                $customerUser->update([
                    'first_name'   => strtoupper($validated['first_name']),
                    'last_name'    => ucwords(strtolower($validated['last_name'])),
                    'phone_number' => $validated['phone'],
                    'email'        => $validated['email'] ?? null,
                ]);

                // 2. Mise à jour du profil KYC
                $customer->update([
                    'birth_date'     => $validated['dob'],
                    'id_type'        => $validated['id_type'],
                    'id_number'      => strtoupper($validated['id_number']),
                    'id_expiry_date' => $validated['id_expiry'],
                    'country_id'     => $validated['country_id'],
                    'city_id'        => $validated['city_id'],
                    'address'        => $validated['address'],
                ]);

                // Récupération du portefeuille principal pour la réponse de confirmation
                $wallet = $customer->wallets()->where('type', 'main')->first();

                Log::info("KYC_ARCH: Profil du client [{$customer->reference}] mis à jour avec succès par l'agent [ID: " . Auth::id() . "].");

                return response()->json([
                    'success' => true,
                    'message' => 'Profil client mis à jour avec succès.',
                    'data'    => [
                        'id'            => $customer->id,
                        'reference'     => $customer->reference,
                        'name'          => "{$customerUser->first_name} {$customerUser->last_name}",
                        'phone'         => $customerUser->phone_number,
                        'wallet_number' => $wallet ? $wallet->wallet_number : 'N/A',
                        'balance'       => $wallet ? (float) $wallet->balance : 0.0
                    ]
                ], 200);
            });

        } catch (\Exception $e) {
            Log::error("Erreur modification profil guichet : " . $e->getMessage(), [
                'exception'   => $e,
                'customer_id' => $customer->id,
                'agent_id'    => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement des modifications. Veuillez réessayer.'
            ], 500);
        }
    }

    /**
     * CONSULTER LA FICHE CLIENT ET SON HISTORIQUE DE TRANSACTIONS.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Récupération de la fiche avec la relation User d'identité
            $customer = Customer::with('user')->where('uuid', $uuid)->firstOrFail();

            // Isolation géographique des fiches clients
            if ($user->hasRole('country_admin') && $staff) {
                if ($customer->country_id !== $staff->country_id) {
                    return response()->json(['success' => false, 'message' => "Accès non autorisé : ce client dépend d'une autre juridiction régionale."], 403);
                }
            }

            // Récupération des transactions du grand livre liées à ce client
            $transactions = Transaction::where('sender_customer_id', $customer->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data'    => [
                    'customer'     => [
                        'id'             => $customer->id,
                        'uuid'           => $customer->uuid,
                        'reference'      => $customer->reference,
                        'first_name'     => $customer->user?->first_name,
                        'last_name'      => $customer->user?->last_name,
                        'phone_number'   => $customer->user?->phone_number,
                        'email'          => $customer->user?->email,
                        'id_type'        => $customer->id_type,
                        'id_number'      => $customer->id_number,
                        'id_expiry_date' => $customer->id_expiry_date,
                        'kyc_level'      => $customer->kyc_level,
                        'kyc_status'     => $customer->kyc_status,
                        'status'         => $customer->status,
                        'created_at'     => $customer->created_at->toIso8601String()
                    ],
                    'transactions' => $transactions->items(),
                    'pagination'   => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ]
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Profil client introuvable ou inaccessible."
            ], 404);
        }
    }
    public function searchByReference(string $reference): JsonResponse
    {
        $customer = Customer::query()
            // Chargement du user ET du wallet principal actif
            ->with([
                'user',
                'mainWallet' => function ($query) {
                    $query->where('is_active', true);
                }
            ])
            ->where('reference', $reference)
            ->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
        }

        // Vérification de sécurité métier
        if ($customer->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => "Ce compte est {$customer->status} et ne peut effectuer d'opérations."
            ], 403);
        }

        if ($customer->kyc_status !== 'approved') {
      /*      return response()->json([
                'success' => false,
                'message' => "Le compte nécessite une validation KYC complète."
            ], 403);*/
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $customer->id,
                'uuid'           => $customer->uuid,
                'reference'      => $customer->reference,
                'first_name'     => $customer->user?->first_name,
            'last_name'      => $customer->user?->last_name,
            'full_name'      => trim(($customer->user?->first_name ?? '') . ' ' . ($customer->user?->last_name ?? '')),
            'phone_number'   => $customer->user?->phone_number,
            'email'          => $customer->user?->email,
            'id_type'        => $customer->id_type,
            'id_number'      => $customer->id_number,
            'id_expiry_date' => $customer->id_expiry_date,
            'kyc_level'      => $customer->kyc_level,
            'kyc_status'     => $customer->kyc_status,
            'status'         => $customer->status,
            'created_at'     => $customer->created_at?->toIso8601String(),

            // AJOUT : Bloc d'informations sur le Wallet
            'wallet' => $customer->mainWallet ? [
        'uuid'          => $customer->mainWallet->uuid,
        'wallet_number' => $customer->mainWallet->wallet_number,
        'type'          => $customer->mainWallet->type,
        'currency'      => $customer->mainWallet->currency,
        // Le solde est formaté en float pour éviter les chaînes de caractères brutes en JS
        'balance'       => (float) $customer->mainWallet->balance,
        'is_active'     => (bool) $customer->mainWallet->is_active,
    ] : null // Sécurité si le client n'a pas encore de wallet généré
        ]
    ]);
}

    /**
     * HISTORIQUE DES DEMANDES DE VÉRIFICATION KYC (Back-office Conformité).
     * @param Request $request
     * @return JsonResponse
     */
    public function kycSubmissions(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();
            $status = $request->input('status', 'pending');

            // 1. On utilise le pluriel 'kycDocuments' et on trie par ID décroissant
            // pour avoir le document le plus récent en premier
            $query = Customer::with(['user', 'kycDocuments' => function($q) {
                $q->orderByDesc('id')->with('verifiedByUser:id,first_name,last_name');
            }])->where('kyc_status', $status);

            if ($user->hasRole('country_admin') && $staff) {
                $query->where('country_id', $staff->country_id);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function($uQ) use ($search) {
                            $uQ->where('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%")
                                ->orWhere('phone_number', 'LIKE', "%{$search}%");
                        });
                });
            }

            $perPage = (int) $request->input('per_page', 15);
            $customers = $query->orderByDesc('updated_at')->paginate($perPage);

            $formattedData = collect($customers->items())->map(function ($customer) {
                // 2. On récupère le document actif (le plus récent soumis)
                $lastDoc = $customer->kycDocuments->first();

                return [
                    'id'            => $customer->id,
                    'reference'     => $customer->reference,
                    'kyc_status'    => $customer->kyc_status,
                    'updated_at'    => $customer->updated_at->toIso8601String(),
                    'customer_info' => $customer->user ? [
                        'full_name'    => $customer->user->first_name . ' ' . $customer->user->last_name,
                        'phone_number' => $customer->user->phone_number,
                        'email'        => $customer->user->email,
                    ] : null,
                    // 3. On extrait les données du dernier document s'il existe
                    'document' => $lastDoc ? [
                        'uuid'            => $lastDoc->uuid,
                        'type'            => $lastDoc->type,
                        'document_number' => $lastDoc->document_number,
                        'front_image_url' => $lastDoc->front_image ? asset('storage/' . $lastDoc->front_image) : null,
                        'back_image_url'  => $lastDoc->back_image ? asset('storage/' . $lastDoc->back_image) : null,
                        'verified_at'     => $lastDoc->verified_at ? $lastDoc->verified_at->toIso8601String() : null,
                        'verifier'        => $lastDoc->verifiedByUser ? [
                            'full_name' => $lastDoc->verifiedByUser->first_name . ' ' . $lastDoc->verifiedByUser->last_name
                        ] : null,
                    ] : null,
                    // Optionnel : On renvoie le compte total de documents soumis dans l'historique du client
                    'total_documents_submitted' => $customer->kycDocuments->count()
                ];
            });

            $countQuery = Customer::where('kyc_status', 'pending');
            if ($user->hasRole('country_admin') && $staff) {
                $countQuery->where('country_id', $staff->country_id);
            }
            $pendingCount = $countQuery->count();

            return response()->json([
                'success' => true,
                'data'    => $formattedData,
                'meta'    => [
                    'pending_count' => $pendingCount,
                    'pagination' => [
                        'current_page' => $customers->currentPage(),
                        'last_page'    => $customers->lastPage(),
                        'total'        => $customers->total(),
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * EVALUER UN DOSSIER DE CONFORMITÉ KYC (Approuver / Rejeter).
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function evaluateKyc(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:255'
        ]);

        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $customer = Customer::where('uuid', $uuid)->firstOrFail();
        $kycDocument = $customer->kycDocument;

        // Sécurité géographique : Empêcher l'évaluation d'un dossier hors juridiction pays
        if ($user->hasRole('country_admin') && $staff) {
            if ($customer->country_id !== $staff->country_id) {
                return response()->json(['success' => false, 'message' => "Action non autorisée sur ce dossier géographique."], 403);
            }
        }

        if ($request->action === 'approve') {
            // 1. Passage du niveau de confiance au palier maximal (Full) pour libérer les verrous et plafonds
            $customer->update([
                'kyc_status'      => 'approved',
                'kyc_verified_at' => now(),
                'kyc_level'       => 'full',
                'status'          => 'active'
            ]);

            // 2. Signature de traçabilité de l'auditeur sur le document probant
            if ($kycDocument) {
                $kycDocument->update([
                    'verified_at' => now(),
                    'verified_by' => $user->id
                ]);
            }
        } else {
            // Logique de Rejet de conformité
            $customer->update([
                'kyc_status' => 'rejected',
                'kyc_level'  => 'none',
                // Consolidation historique dans le payload d'erreur destiné à l'application mobile client
                'metadata'   => array_merge(($customer->metadata ?? []), [
                    'rejection_reason' => $request->reason,
                    'rejected_at'      => now()->toIso8601String(),
                    'rejected_by'      => $user->id
                ])
            ]);

            if ($kycDocument) {
                $kycDocument->delete(); // Soft delete sécurisé de la pièce non conforme
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Le dossier de conformité KYC a été traité avec succès.'
        ], 200);
    }

    /**
     * Récupérer le profil du client connecté.
     * @param Request $request
     * @return JsonResponse
     */
    public function showMe(Request $request): JsonResponse
    {
        try {
            $customer = Auth::user()->customer;

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }

            // Charger les détails de l'utilisateur lié
            $customer->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Profil client récupéré avec succès.',
                'data' => $customer
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer le profil du client connecté.
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyCustomer(Request $request, $phone): JsonResponse
    {
        try {
            // 🛠️ Correction de la typo: 'fisrt()' -> 'first()'
            // Remplacement de 'phone_number' par votre colonne réelle (souvent 'phone' ou 'phone_number')
            $user = User::query()->where('phone_number', $phone)->first();
            $customer=$user->customer;
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }

            // Charger la relation 'user' pour récupérer le nom/prénom si nécessaire
            $customer->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Profil client récupéré avec succès.',
                // 🔥 Si votre ApiResponse Android attend un format { success, message, data: { data: { ... } } }
                // décommentez la ligne suivante pour l'aligner avec votre submitTransfer :
                // 'data' => ['data' => $customer]
                'data' => $customer
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour le profil du client connecté.
     */
    public function update2(Request $request): JsonResponse
    {
        $user = Auth::user();
        $customer = $user->customer;

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Profil client introuvable.'
            ], 404);
        }

        // Validation des données reçues de l'application Flutter
        $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'email'      => 'sometimes|nullable|email|unique:users,email,' . $user->id,
        ]);

        try {
            // 1. Mettre à jour la table 'users' (champs partagés)
            $userFields = $request->only(['first_name', 'last_name', 'email']);
            if (!empty($userFields)) {
                $user->update($userFields);
            }

            // 2. Mettre à jour la table 'customers' si tu as des champs spécifiques
            // (ex: adresse, metadata, etc.) transmis depuis Flutter
            // $customer->update($request->only(['champs_customer']));

            // 3. Recharger la relation fraîchement mise à jour
            $customer->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès.',
                'data' => $customer
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du profil.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            // 1. Récupérer l'utilisateur connecté et son profil client
            $user = $request->user();
            $customer = $user->customer; // Supposons la relation HasOne ou BelongsTo

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "Profil client introuvable."
                ], 404);
            }

            // 2. Lancer une transaction pour sécuriser la double mise à jour
            DB::transaction(function () use ($request, $user, $customer) {

                // Mise à jour de la table 'users'
                $user->update([
                    'first_name' => $request->input('first_name'),
                    'last_name'  => $request->input('last_name'),
                    'email'      => $request->input('email'),
                    // Ne pas toucher au numéro de téléphone ici car il est bloqué/lecture seule côté Android
                ]);

                // Mise à jour de la table 'customers'
                $customer->update([
                    'birth_date' => $request->input('birth_date'),
                    'city_id'    => $request->input('city_id'),
                ]);
            });

            // 3. Recharger les relations pour renvoyer un objet tout neuf à Android
            $customer->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès.',
                'data'    => $customer // Correspond au CustomerModel attendu par votre Android Resource.Success
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur UpdateProfile : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du profil.'
            ], 500);
        }
    }
}
