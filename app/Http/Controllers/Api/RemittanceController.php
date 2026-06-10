<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helpers;
use App\Http\Controllers\Controller;
use App\Models\SystemAuditLog;
use App\Models\Till;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Staff;
use App\Services\FraudCheckService;
use App\Services\RemittanceService;
use App\Models\Customer;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RemittanceController extends Controller
{
    protected RemittanceService $remittanceService;
    protected $fraudService;

    /**
     * Injection du service de gestion des transferts.
     * @param RemittanceService $remittanceService
     */
    public function __construct(RemittanceService $remittanceService, FraudCheckService $fraudCheckService)
    {
        $this->remittanceService = $remittanceService;
        $this->fraudService = $fraudCheckService;
    }

    /**
     * Récupère l'historique financier absolu (Flux, Décaissements et Commissions pures) du guichet
     * @param Request $request
     * @return JsonResponse
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Extraction du Staff et de son GUICHET ACTIF
            $staff = Staff::with(['agency', 'currentTill'])->where('user_id', $user->id)->first();
            $agency = $staff ?->agency;
        $sourceTill = $staff ?->currentTill;

        if (!$sourceTill || !$sourceTill->is_active || $sourceTill->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => "Impossible de récupérer l'historique : aucun guichet actif ou ouvert."
            ], 403);
        }

        if (!$agency) {
            return response()->json([
                'success' => false,
                'message' => "Aucune agence associée à votre profil."
            ], 403);
        }

        // Portefeuille Principal du Guichet (pour le Cash/Virtuel standard)
        $tillWallet = Wallet::where('owner_type', Till::class)
            ->where('owner_id', $sourceTill->id)
            ->where('type', 'main')
            ->first();

        // Portefeuille Commission de l'Agence (pour attraper les gains de commission isolés)
        $agencyCommissionWallet = Wallet::where('owner_type', Agency::class)
            ->where('owner_id', $agency->id)
            ->where('type', 'commission')
            ->first();

        /**
         * 2. Construction de la requête sur la table TRANSACTIONS
         * On cherche :
         * - Soit là où le guichet gère le cash (source_till_id ou destination_till_id)
         * - Soit là où l'agence a touché une commission financière (via la table commissions)
         */
        $query = Transaction::where(function ($q) use ($sourceTill, $agencyCommissionWallet) {
            $q->where('source_till_id', $sourceTill->id)
                ->orWhere('destination_till_id', $sourceTill->id);

            if ($agencyCommissionWallet) {
                $q->orWhereHas('entries', function ($subQ) use ($agencyCommissionWallet) {
                    $subQ->where('wallet_id', $agencyCommissionWallet->id);
                });
            }
        })
            ->with([
                // On charge à la fois les écritures du portefeuille principal du guichet ET du portefeuille commission de l'agence
                'entries' => function ($q) use ($tillWallet, $agencyCommissionWallet) {
                    $walletIds = array_filter([$tillWallet ?->id, $agencyCommissionWallet ?->id]);
                $q->whereIn('wallet_id', $walletIds);
            }
            ]);

        /**
         * 3. Gestion des filtres et de la recherche
         */
        if ($request->filled('entry_type') && in_array($request->entry_type, ['credit', 'debit'])) {
            $type = $request->entry_type;
            $query->whereHas('entries', function ($q) use ($tillWallet, $agencyCommissionWallet, $type) {
                $walletIds = array_filter([$tillWallet ?->id, $agencyCommissionWallet ?->id]);
                $q->whereIn('wallet_id', $walletIds)->where('entry_type', $type);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('sender_name', 'LIKE', "%{$search}%")
                    ->orWhere('recipient_name', 'LIKE', "%{$search}%");
            });
        }

        $transactions = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        /**
         * 4. Formattage et normalisation intelligente pour le Front-End
         */
        $formattedData = collect($transactions->items())->map(function ($transaction) use ($sourceTill, $tillWallet, $agencyCommissionWallet) {

            // On cherche en priorité s'il y a une écriture sur le portefeuille principal (le cash direct)
            $localEntry = $transaction->entries->where('wallet_id', $tillWallet ?->id)->first();

            // Sinon, on cherche l'écriture sur le portefeuille de commission de l'agence
            $commissionEntry = $transaction->entries->where('wallet_id', $agencyCommissionWallet ?->id)->first();

            // Détermination du sens et du contexte
            $operationType = 'credit'; // Par défaut une commission est un gain (crédit)
            $contextType = $transaction->type;
            $role = 'network';

            if ($localEntry) {
                $operationType = $localEntry->entry_type;
                $role = $transaction->source_till_id === $sourceTill->id ? 'initiator' : 'distributor';
            } elseif ($commissionEntry) {
                $operationType = 'credit';
                $contextType = 'commission_gain';
                $role = 'beneficiary';
            }

            // Sélection de la balance après opération à afficher à l'écran
            $entryToDisplay = $localEntry ?? $commissionEntry;

            return [
                'id' => $transaction->id,
                'reference_interne' => $transaction->reference,
                'operation_type' => $operationType,
                'amount' => $localEntry ? (float)$transaction->amount : ($commissionEntry ? (float)$commissionEntry->amount : (float)$transaction->amount),
                'balance_before' => $entryToDisplay ? (float)$entryToDisplay->balance_before : null,
                'balance_after' => $entryToDisplay ? (float)$entryToDisplay->balance_after : null,
                'transaction_status' => $transaction->status,
                'context' => [
                    'type' => $contextType,
                    'sender' => $transaction->sender_name ?? 'Système',
                    'recipient' => $transaction->recipient_name ?? 'Votre Agence',
                    'role' => $role
                ],
                'date' => $transaction->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'till' => [
                'id' => $sourceTill->id,
                'name' => $sourceTill->name,
                'code' => $sourceTill->code,
                'agency_name' => $agency->name,
                'current_virtual_balance' => $tillWallet ? (float)$tillWallet->balance : 0,
                'current_physical_balance' => (float)$sourceTill->current_balance,
                'currency' => $tillWallet->currency ?? 'XAF'
            ],
            'data' => $formattedData,
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
            ]
        ], 200);

    } catch (Exception $e) {
            Log::error("Erreur historique exhaustif guichet : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur de chargement du grand livre."
            ], 500);
        }
    }

    /**
     * ÉTAPE 1 : Estimer les frais en temps réel selon le corridor de pays.
     * @param Request $request
     * @return JsonResponse
     */
    public function estimateFees(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'type' => 'nullable|string|in:remittance,cash_in,cash_out',
            'destination_country_id' => 'required|exists:countries,id',
        ]);

        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();
            $sourceAgency = $staff ?->agency;

            if (!$sourceAgency) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur de contexte : Impossible de déterminer le pays d'origine sans agence rattachée."
                ], 403);
            }

            $amount = (float)$request->input('amount');
            $type = $request->input('type', 'remittance');
            $destinationCountryId = (int)$request->input('destination_country_id');

            $feesCalculation = $this->remittanceService->calculateFees(
                $amount,
                $type,
                $sourceAgency->country_id,
                $destinationCountryId
            );

            return response()->json([
                'success' => true,
                'message' => 'Calcul des frais du corridor effectué avec succès.',
                'data' => $feesCalculation
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * ÉTAPE 2 : Initialiser, analyser contre la fraude et valider une émission de fonds au guichet.
     * * @param Request $request
     * @return JsonResponse
     */
    public function initiate(Request $request): JsonResponse
    {
        // 1. Définition des règles
        $rules = [
            'amount' => 'required|numeric|min:100',
            'sender_first_name' => 'required|string|max:150|min:3',
            'sender_last_name' => 'required|string|max:150|min:3',
            'sender_phone' => 'required|string|max:150|min:3',
            'sender_id_type' => 'required|string|in:cni,passport,recepisse,carte_sejour',
            'sender_id_number' => 'required|string|max:150|min:3',
            'sender_id_expiry' => 'required|date|after:today',
            'sender_email' => 'nullable|email|max:150',
            'sender_city_id' => 'required|exists:cities,id',
            'sender_address' => 'required|string|max:255',
            'recipient_name' => 'required|string|max:150|min:3',
            'recipient_phone' => 'required|string|max:20',
            'recipient_email' => 'nullable|email|max:150',
            'destination_country_id' => 'required|exists:countries,id',
        ];

// 2. Traduction et contextualisation des erreurs
        $messages = [
            'amount.required' => 'Le montant du transfert est obligatoire.',
            'amount.min' => 'Le montant minimal pour un transfert est de 100 XAF.',
            'sender_first_name.required' => 'Le prénom de l\'expéditeur est requis.',
            'sender_last_name.required' => 'Le nom de l\'expéditeur est requis.',
            'sender_id_type.in' => 'Le type de pièce d\'identité sélectionné est invalide.',
            'sender_id_expiry.after' => 'La pièce d\'identité présentée est expirée.',
            'sender_city_id.exists' => 'La ville d\'origine sélectionnée n\'existe pas.',
            'destination_country_id.exists' => 'Le pays de destination est introuvable ou non desservi.',
            'email' => 'L\'adresse email saisie est incorrecte.',
        ];

// 3. Exécution de la validation
        $validatedData = $request->validate($rules, $messages);

        try {
            $user = Auth::user();

// Eager loading de l'agence, du pays de l'agence, et du guichet actif de l'agent
            $staff = Staff::with(['agency.country', 'currentTill'])
                ->where('user_id', $user->id)
                ->first();

            $sourceAgency = $staff ?->agency;
            $sourceTill = $staff ?->currentTill; // Sera une instance de Till (ou null si aucune caisse ouverte)

// Validation de sécurité renforcée
        if (!$sourceTill) {
            return response()->json([
                'success' => false,
                'message' => "Accès refusé : Aucun tiroir-caisse ouvert n'est associé à votre session d'agent."
            ], 403);
        }

        // Sécurité : Vérification de l'agence et du pays
        if (!$sourceAgency || !$sourceAgency->country) {
            return response()->json([
                'success' => false,
                'message' => "Erreur de sécurité : Votre compte utilisateur n'est rattaché à aucune agence ou pays valide."
            ], 403);
        }

        // Sécurité critique : Vérification que le guichet est assigné, ouvert et actif
        if (!$sourceTill || !$sourceTill->is_active || $sourceTill->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => "Opération impossible : Vous devez être connecté à un guichet/tiroir-caisse ouvert pour effectuer cette opération."
            ], 403);
        }

        // 2. Normalisation des données d'entrée
        $cleanSenderPhone = clean_phone($validatedData['sender_phone']);
        $idNumberUpper = strtoupper($validatedData['sender_id_number']);
        $amount = (float)$validatedData['amount'];

        // 3. Résolution préliminaire du client pour l'anti-fraude
        $existingCustomer = Customer::where('id_number', $idNumberUpper)->first();
        $customerId = $existingCustomer ?->id;

        // 4. CONTRÔLE ANTI-FRAUDE ET AML
        $fraudAnalysis = $this->fraudService->analyze(
            'remittance',
            $customerId,
            $amount,
            [
                'sender_phone' => $cleanSenderPhone,
                'is_anonymous' => is_null($customerId)
            ]
        );

        // Interception si le pare-feu anti-fraude lève un drapeau rouge
        if ($fraudAnalysis['is_blocked'] || $fraudAnalysis['is_flagged']) {
            SystemAuditLog::create([
                'user_id' => $user->id,
                'agency_id' => $sourceAgency->id,
                'event_type' => 'REMITTANCE.FRAUD_BLOCK',
                'severity' => 'critical',
                'message' => "Émission bloquée par la sécurité (Conformité). Motif : " . $fraudAnalysis['reason'],
                'payload' => [
                    'sender_phone' => mask_data($cleanSenderPhone, 'phone'),
                    'sender_id_number' => mask_data($idNumberUpper, 'id'),
                    'amount' => $amount,
                    'risk_score' => $fraudAnalysis['risk_score'],
                    'till_code' => $sourceTill->code
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Opération refusée pour non-conformité réglementaire (AML) : " . $fraudAnalysis['reason']
            ], 422);
        }

        // 5. EXÉCUTION DE LA TRANSACTION SOUS ISOLATION STRICTE ACID
        $transactionResult = DB::transaction(function () use ($validatedData, $user, $staff, $sourceAgency, $sourceTill, $cleanSenderPhone, $idNumberUpper) {

            // Sécurisation anti-concurrence sur le client
            $senderCustomer = Customer::where('id_number', $idNumberUpper)->lockForUpdate()->first();

            // Si le client n'existe pas, création complète de son profil et de son Wallet
            if (!$senderCustomer) {
                $usernameSeed = Str::slug($validatedData['sender_first_name'] . ' ' . $validatedData['sender_last_name']);

                $customerUser = User::create([
                    'uuid' => (string)Str::uuid(),
                    'username' => strtolower(substr($usernameSeed, 0, 10) . rand(100, 999)),
                    'first_name' => strtoupper($validatedData['sender_first_name']),
                    'last_name' => ucwords(strtolower($validatedData['sender_last_name'])),
                    'phone_number' => $cleanSenderPhone,
                    'email' => $validatedData['sender_email'] ?? null,
                    'password' => Hash::make(Str::random(16)),
                    'is_active' => true,
                ]);

                $customerUser->assignRole('customer');
                $clientReference = 'CLI-' . strtoupper(Str::random(8));

                $senderCustomer = Customer::create([
                    'user_id' => $customerUser->id,
                    'reference' => $clientReference,
                    'id_type' => $validatedData['sender_id_type'],
                    'id_number' => $idNumberUpper,
                    'id_expiry_date' => $validatedData['sender_id_expiry'],
                    'country_id' => $sourceAgency->country_id,
                    'city_id' => $validatedData['sender_city_id'],
                    'address' => $validatedData['sender_address'],
                    'status' => 'active',
                    'kyc_status' => 'approved',
                ]);

                Wallet::create([
                    'uuid' => (string)Str::uuid(),
                    'wallet_number' => 'W-' . $clientReference,
                    'type' => 'main',
                    'currency' => $sourceAgency->country->currency_code ?? 'XAF',
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_id' => $senderCustomer->id,
                    'owner_type' => Customer::class
                ]);
            }

            // Normalisation du téléphone bénéficiaire
            $cleanRecipientPhone = format_to_international($validatedData['recipient_phone'], $sourceAgency->country->id);

            $transactionData = [
                'amount' => (float)$validatedData['amount'],
                'sender_customer_id' => $senderCustomer->id,
                'sender_name' => $senderCustomer->user->first_name . ' ' . $senderCustomer->user->last_name,
                'sender_phone' => $senderCustomer->user->phone_number,
                'recipient_name' => $validatedData['recipient_name'],
                'recipient_phone' => $cleanRecipientPhone,
                'recipient_email' => $validatedData['recipient_email'] ?? null,
                'destination_country_id' => (int)$validatedData['destination_country_id'],
            ];

            // ⚠️ MODIFICATION MAJEURE COMPTABLE :
            // On passe désormais l'objet $sourceTill (Guichet) au lieu de l'agence.
            // Votre RemittanceService doit être configuré pour déduire/mouvementer le Wallet polymorphique rattaché à ce Till.
            return $this->remittanceService->initiateRemittance($staff->user, $sourceTill, $transactionData);
        });

        // 6. ARCHIVAGE DU CONTRÔLE DE FRAUDE
        $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

        // 7. JOURNALISATION D'AUDIT SYSTÈME (Mise à jour avec ciblage du guichet)
        SystemAuditLog::create([
            'user_id' => $user->id,
            'agency_id' => $sourceAgency->id,
            'event_type' => 'REMITTANCE.INITIATED',
            'severity' => 'info',
            'message' => "Mandat cash émis avec succès au guichet [{$sourceTill->code}] par l'opérateur [{$staff->employee_code}]. Référence : {$transactionResult->reference}",
            'payload' => [
                'reference' => $transactionResult->reference,
                'amount' => (float)$transactionResult->amount,
                'recipient' => $validatedData['recipient_name'],
                'staff_id' => $staff->id,
                'till_id' => $sourceTill->id,
                'risk_score' => $fraudAnalysis['risk_score']
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // 8. RÉPONSE RETOURNÉE
        return response()->json([
            'success' => true,
            'message' => 'Le mandat cash a été émis et imputé avec succès sur le guichet.',
            'data' => [
                'reference' => $transactionResult->reference,
                'secure_code' => $transactionResult->secure_code,
                'amount' => (float)$transactionResult->amount,
                'fees' => (float)$transactionResult->fees,
                'total_paid' => (float)($transactionResult->amount + $transactionResult->fees + $transactionResult->taxes),
                'currency' => $transactionResult->currency,
                'sender_name' => $transactionResult->sender_name,
                'recipient_name' => $transactionResult->recipient_name,
                'recipient_email' => $transactionResult->recipient_email,
                'created_at' => $transactionResult->created_at->toIso8601String()
            ]
        ], 201);

} catch (Exception $e) {
            // 1. On log l'erreur complète AVEC la trace pour les développeurs (dans storage/logs/laravel.log)
            Log::error("Échec lors de l'émission du mandat au guichet : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            if ($e->getCode() === 422 || str_contains($e->getMessage(), 'Opération impossible')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() // Renvoie : "Opération impossible : L'encaisse physique..."
                ], 422);
            }
            // 2. Détection d'une violation de contrainte d'intégrité SQL (ex: Doublon)
            // SQLSTATE[23000] et le code d'erreur MySQL 1062 correspondent à un doublon (Duplicate Entry)
            if (str_contains($e->getMessage(), 'SQLSTATE[23000]') || str_contains($e->getMessage(), '1062')) {

                // On affine le message selon la colonne qui a bloqué
                if (str_contains($e->getMessage(), 'users_phone_number_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de finaliser le transfert : Ce numéro de téléphone est déjà associé à un compte utilisateur existant."
                    ], 422);
                }

                if (str_contains($e->getMessage(), 'users_email_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => "Impossible de finaliser le transfert : Cette adresse email est déjà utilisée."
                    ], 422);
                }

                // Message de doublon générique si ce n'est ni le téléphone ni l'email
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de finaliser le transfert : Les informations saisies entrent en conflit avec un profil déjà enregistré."
                ], 422);
            }

            // 3. Pour TOUTES les autres exceptions (Rupture de connexion, bug de code, etc.)
            // On masque totalement le message technique à l'utilisateur
            return response()->json([
                'success' => false,
                'message' => "Une anomalie technique interne est survenue. Impossible de finaliser le transfert pour le moment. Veuillez contacter votre administrateur."
            ], 500); // 500 car c'est une erreur serveur/technique imprévue
        }
    }

    /**
     * ÉTAPE 3 : Valider, analyser contre la fraude et payer un mandat (Décaissement) au bénéficiaire.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function payout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => 'required|string|max:100',
            'secure_code' => 'required|string|max:50',
            'recipient_id_type' => 'required|in:cni,passport,recepisse,carte_sejour',
            'recipient_id_number' => 'required|string|max:150',
            'recipient_id_expiry' => 'required|date|after:today',
        ], [
            'recipient_id_expiry.after' => 'La pièce d’identité présentée a expiré.',
        ]);

        try {
            $user = Auth::user();

// 1. Extraction et validation du profil de l'opérateur payeur et de son GUICHET ACTIF
// On ajoute le chargement du pays si nécessaire pour la suite du processus
            $staff = Staff::with(['agency.country', 'currentTill'])->where('user_id', $user->id)->first();

            $destinationAgency = $staff ?->agency;
$destinationTill = $staff ?->currentTill; // Récupération via la relation hasOneThrough (Dernier 'opening')

// Sécurité 1 : L'agent doit être rattaché à une agence active
if (!$destinationAgency || !$destinationAgency->is_active || $destinationAgency->status !== 'active') {
    return response()->json([
        'success' => false,
        'message' => "Erreur de configuration : Votre session n'est liée à aucune agence distributrice active."
    ], 403);
}

// Sécurité 2 : Le guichet doit être trouvé, actif et physiquement ouvert
if (!$destinationTill || !$destinationTill->is_active || $destinationTill->status !== 'open') {
    return response()->json([
        'success' => false,
        'message' => "Opération impossible : Vous devez être connecté à un guichet/tiroir-caisse ouvert pour effectuer ce décaissement."
    ], 403);
}

// Sécurité 3 (Garde-fou critique) : On valide que le guichet appartient bien à l'agence de l'agent
if ((int)$destinationTill->agency_id !== (int)$destinationAgency->id) {
    return response()->json([
        'success' => false,
        'message' => "Erreur d'intégrité : Le guichet détecté n'appartient pas à votre agence de rattachement."
    ], 403);
}

        $fraudAnalysis = null;

        // 2 & 4. ISOLATION ACID ET VERROUILLAGE CONTRE LE DOUBLE DÉCAISSEMENT
        $transactionResult = DB::transaction(function () use ($validated, $user, $destinationAgency, $destinationTill, $staff, &$fraudAnalysis) {

            // Récupération avec VERROU STRICT (lockForUpdate) du mandat
            $transaction = Transaction::where('reference', trim($validated['reference']))
                ->where('secure_code', trim($validated['secure_code']))
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new Exception("Mandat introuvable ou paramètres de sécurité incorrects.", 404);
            }

            // Sécurité métier : Le mandat doit impérativement être en attente de paiement
            if ($transaction->status !== 'pending') {
                throw new Exception("Ce mandat ne peut pas être payé. Statut actuel : [" . strtoupper($transaction->status) . "].", 422);
            }

            // 3. CONTRÔLE ANTI-FRAUDE & COMPLICITÉ INTERNE (Sous le verrou)
            $fraudAnalysis = $this->fraudService->analyze(
                'remittance_payout',
                null,
                (float)$transaction->amount,
                [
                    'transaction' => $transaction,
                    'payout_staff_id' => $staff->id,
                    'payout_till_id' => $destinationTill->id
                ]
            );

            // Si le pare-feu anti-fraude lève un blocage
            if ($fraudAnalysis['is_blocked'] || $fraudAnalysis['is_flagged']) {
                throw new Exception("BLOQUÉ_FRAUDE: " . $fraudAnalysis['reason'], 403);
            }

            // 4. EXÉCUTION DU DÉCAISSEMENT COMPTABLE
            // ⚠️ MODIFICATION : On passe désormais $destinationTill au lieu de l'agence
            return $this->remittanceService->payoutRemittance(
                $staff->user,
                $destinationTill,
                array_merge($validated, ['transaction_id' => $transaction->id])
            );
        });

        // 5. ARCHIVAGE DU RAPPORT DE RISQUE
        $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

        // Masquage RGPD du numéro de pièce d'identité
        $maskedIdNumber = substr(trim($validated['recipient_id_number']), 0, 3) . '****' . substr(trim($validated['recipient_id_number']), -3);

        // 6. JOURNALISATION D'AUDIT TECHNIQUE
        SystemAuditLog::create([
            'user_id' => $user->id,
            'agency_id' => $destinationAgency->id,
            'event_type' => 'REMITTANCE.PAID',
            'severity' => 'info',
            'message' => "Mandat cash décaissé avec succès au guichet [{$destinationTill->code}] de l'agence [{$destinationAgency->name}]. Opérateur : {$staff->employee_code}",
            'payload' => [
                'reference' => $transactionResult->reference,
                'amount' => (float)$transactionResult->amount,
                'recipient' => $transactionResult->recipient_name,
                'staff_id' => $staff->id,
                'till_id' => $destinationTill->id,
                'id_verified' => strtoupper($validated['recipient_id_type']) . " - " . $maskedIdNumber
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // 7. RÉPONSE RETOURNÉE À L'UI NEXT.JS
        return response()->json([
            'success' => true,
            'message' => 'Le paiement a été approuvé. Le mandat passe au statut [PAID]. Vous pouvez décaisser les fonds du tiroir-caisse.',
            'data' => [
                'reference' => $transactionResult->reference,
                'paid_amount' => (float)$transactionResult->amount,
                'currency' => $transactionResult->currency,
                'paid_at' => $transactionResult->completed_at ? $transactionResult->completed_at->toIso8601String() : now()->toIso8601String()
            ]
        ], 200);

    } catch (Exception $e) {
            // Interception spécifique pour la fraude afin de logguer l'alerte de sécurité
            if (str_starts_with($e->getMessage(), 'BLOQUÉ_FRAUDE:')) {
                SystemAuditLog::create([
                    'user_id' => Auth::id(),
                    'agency_id' => $destinationAgency ?->id,
                'event_type' => 'PAYOUT.FRAUD_BLOCK',
                'severity'   => 'critical',
                'message'    => "Tentative de retrait bloquée. " . $e->getMessage(),
                'payload'    => [
                    'reference' => $request->input('reference'),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

            Log::error("Échec lors du décaissement du mandat au guichet : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'reference' => $request->input('reference')
            ]);

            $code = in_array($e->getCode(), [403, 404, 422]) ? $e->getCode() : 422;
            $cleanMessage = str_replace('BLOQUÉ_FRAUDE: ', '', $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Paiement refusé : " . $cleanMessage
            ], $code);
        }
    }

    /**
     * Recherche un mandat de versement pour vérification avant paiement (Payout)
     * @param Request $request
     * @return JsonResponse
     */
    public function searchPayout(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => 'required_without:secure_code|nullable|string|max:100',
            'secure_code' => 'required_without:reference|nullable|string|max:50',
        ], [
            'required_without' => 'Veuillez fournir la référence de la transaction ou le code secret du mandat.',
        ]);

        try {
            $query = Transaction::query();

            if ($request->filled('reference')) {
                $query->where('reference', trim($request->input('reference')));
            }

            if ($request->filled('secure_code')) {
                $query->where('secure_code', trim($request->input('secure_code')));
            }

            $transaction = $query->with(['senderCountry', 'senderCity'])->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => "Aucun mandat trouvé avec les identifiants saisis. Veuillez réviser la saisie."
                ], 404);
            }

            // États de cycle de vie bloquants pour le guichetier
            $statusMapping = [
                'paid' => [
                    'allowed' => false,
                    'message' => "Sécurité : Ce mandat a déjà été payé le " . ($transaction->completed_at ? $transaction->completed_at->format('d/m/Y à H:i') : '') . "."
                ],
                'cancelled' => [
                    'allowed' => false,
                    'message' => "Opération impossible : Ce transfert a été annulé par l'émetteur."
                ],
                'reversed' => [
                    'allowed' => false,
                    'message' => "Opération impossible : Les fonds de ce transfert ont été retournés/extournés."
                ],
                'failed' => [
                    'allowed' => false,
                    'message' => "Échec : Cette transaction est marquée en erreur système."
                ],
                'processing' => [
                    'allowed' => false,
                    'message' => "Traitement en cours : Ce mandat n'est pas encore totalement validé par le réseau."
                ],
            ];

            if (isset($statusMapping[$transaction->status])) {
                return response()->json([
                    'success' => false,
                    'is_payable' => $statusMapping[$transaction->status]['allowed'],
                    'message' => $statusMapping[$transaction->status]['message'],
                    'data' => [
                        'reference' => $transaction->reference,
                        'status' => $transaction->status
                    ]
                ], 422);
            }

            return response()->json([
                'success' => true,
                'is_payable' => true,
                'message' => "Mandat trouvé et disponible pour décaissement.",
                'data' => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'secure_code' => $transaction->secure_code,
                    'amount' => (float)$transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status,
                    'sender_name' => $transaction->sender_name,
                    'sender_phone' => $transaction->sender_phone,
                    'recipient_name' => $transaction->recipient_name,
                    'recipient_phone' => $transaction->recipient_phone,
                    'recipient_email' => $transaction->recipient_email,
                    'source_country' => $transaction->senderCountry ?->name,
                    'source_city'      => $transaction->senderCity ?->name,
                    'created_at'       => $transaction->created_at->toIso8601String(),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur lors de la recherche du payout : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Une erreur technique interne est survenue lors de la recherche."
            ], 500);
        }
    }
}
