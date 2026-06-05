<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helpers;
use App\Http\Controllers\Controller;
use App\Models\SystemAuditLog;
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
    public function __construct(RemittanceService $remittanceService,FraudCheckService $fraudCheckService)
    {
        $this->remittanceService = $remittanceService;
        $this->fraudService=$fraudCheckService;
    }

    /**
     * Récupère l'historique des flux financiers (Ledger Entries) de l'agence
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Pivot Staff obligatoire pour extraire l'agence
            $staff = Staff::where('user_id', $user->id)->first();
            $agency = $staff?->agency;

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de récupérer l'historique : aucune agence associée à votre profil opérateur."
                ], 403);
            }

            /**
             * 2. Récupération du wallet de transit principal de l'agence
             */
            $agencyWallet = Wallet::where('owner_type', \App\Models\Agency::class)
                ->where('owner_id', $agency->id)
                ->where('type', 'main')
                ->first();

            if (!$agencyWallet) {
                return response()->json([
                    'success' => false,
                    'message' => "Le portefeuille de transit principal de l'agence est introuvable."
                ], 404);
            }

            /**
             * 3. Construction de la requête du Grand Livre de l'agence
             */
            $query = TransactionEntry::where('wallet_id', $agencyWallet->id)
                ->with([
                    'transaction:id,reference,type,status,sender_name,recipient_name'
                ]);

            // Filtre par type d'écriture (credit/debit)
            if ($request->filled('entry_type') && in_array($request->entry_type, ['credit', 'debit'])) {
                $query->where('entry_type', $request->entry_type);
            }

            // Recherche multicritère textuelle
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('transaction', function ($q) use ($search) {
                    $q->where('reference', 'LIKE', "%{$search}%")
                        ->orWhere('sender_name', 'LIKE', "%{$search}%")
                        ->orWhere('recipient_name', 'LIKE', "%{$search}%");
                });
            }

            $entries = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            /**
             * 4. Normalisation et formatage pour l'UI Next.js
             */
            $formattedData = collect($entries->items())->map(function ($entry) {
                return [
                    'id'                 => $entry->id,
                    'reference_interne'  => $entry->transaction?->reference ?? 'N/A',
                    'operation_type'     => $entry->entry_type,
                    'amount'             => (float) $entry->amount,
                    'balance_before'     => (float) $entry->balance_before,
                    'balance_after'      => (float) $entry->balance_after,
                    'transaction_status' => $entry->transaction?->status,
                    'context'            => $entry->transaction ? [
                    'type'      => $entry->transaction->type,
                    'sender'    => $entry->transaction->sender_name,
                    'recipient' => $entry->transaction->recipient_name,
                ] : null,
                    'date'               => $entry->created_at->toIso8601String(),
                ];
            });

            return Helpers::success([
                'success' => true,
                'agency'  => [
                    'id'                       => $agency->id,
                    'name'                     => $agency->name,
                    'current_virtual_balance'  => (float) $agencyWallet->balance,
                    'current_physical_balance' => (float) $agency->current_balance, // Solde coffre consolidé
                    'currency'                 => $agencyWallet->currency ?? 'XAF'
                ],
                'data'       => $formattedData,
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'last_page'    => $entries->lastPage(),
                    'total'        => $entries->total(),
                    'per_page'     => $entries->perPage(),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur historique agence : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Une erreur interne est survenue lors de la récupération de l'historique."
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
            'amount'                 => 'required|numeric|min:1',
            'type'                   => 'nullable|string|in:remittance,cash_in,cash_out',
            'destination_country_id' => 'required|exists:countries,id',
        ]);

        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();
            $sourceAgency = $staff?->agency;

            if (!$sourceAgency) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur de contexte : Impossible de déterminer le pays d'origine sans agence rattachée."
                ], 403);
            }

            $amount = (float) $request->input('amount');
            $type = $request->input('type', 'remittance');
            $destinationCountryId = (int) $request->input('destination_country_id');

            $feesCalculation = $this->remittanceService->calculateFees(
                $amount,
                $type,
                $sourceAgency->country_id,
                $destinationCountryId
            );

            return response()->json([
                'success' => true,
                'message' => 'Calcul des frais du corridor effectué avec succès.',
                'data'    => $feesCalculation
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
        $validatedData = $request->validate([
            'amount'                 => 'required|numeric|min:100',
            // Infos Expéditeur
            'sender_first_name'      => 'required|string|max:150|min:3',
            'sender_last_name'       => 'required|string|max:150|min:3',
            'sender_phone'           => 'required|string|max:150|min:3',
            'sender_id_type'         => 'required|string|in:cni,passport,recepisse,carte_sejour',
            'sender_id_number'       => 'required|string|max:150|min:3',
            'sender_id_expiry'       => 'required|date|after:today',
            'sender_email'           => 'nullable|email|max:150',
            'sender_city_id'         => 'required|exists:cities,id',
            'sender_address'         => 'required|string|max:255',
            // Infos Bénéficiaire
            'recipient_name'         => 'required|string|max:150|min:3',
            'recipient_phone'        => 'required|string|max:20',
            'recipient_email'        => 'nullable|email|max:150',
            'destination_country_id' => 'required|exists:countries,id',
        ]);

        try {
            $user = Auth::user();

            // 1. Extraction et validation du profil de l'opérateur (Guichetier / Caissier)
            $staff = Staff::with(['agency.country'])->where('user_id', $user->id)->first();
            $sourceAgency = $staff?->agency;

        if (!$sourceAgency || !$sourceAgency->country) {
            return response()->json([
                'success' => false,
                'message' => "Erreur de sécurité : Votre compte utilisateur n'est rattaché à aucune agence ou pays valide."
            ], 403);
        }

        // 2. Normalisation des données d'entrée
        $cleanSenderPhone = clean_phone($validatedData['sender_phone']);
        $idNumberUpper = strtoupper($validatedData['sender_id_number']);
        $amount = (float) $validatedData['amount'];

        // 3. Résolution préliminaire du client pour l'anti-fraude (sans lock à ce stade)
        $existingCustomer = Customer::where('id_number', $idNumberUpper)->first();
        $customerId = $existingCustomer?->id;

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
                'user_id'    => $user->id,
                'agency_id'  => $sourceAgency->id,
                'event_type' => 'REMITTANCE.FRAUD_BLOCK',
                'severity'   => 'critical',
                'message'    => "Émission bloquée par la sécurité. Motif : " . $fraudAnalysis['reason'],
                'payload'    => [
                    'sender_phone'     => mask_data($cleanSenderPhone, 'phone'), // Fonction fictive de masquage conseillée
                    'sender_id_number' => mask_data($idNumberUpper, 'id'),
                    'amount'           => $amount,
                    'risk_score'       => $fraudAnalysis['risk_score']
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
        // Note: Injection de $idNumberUpper pour refaire le check de sécurité interne contre les Race Conditions
        $transactionResult = DB::transaction(function () use ($validatedData, $user, $staff, $sourceAgency, $cleanSenderPhone, $idNumberUpper) {

            // Sécurisation anti-concurrence : On recherche et verrouille la ligne si elle existe déjà
            $senderCustomer = Customer::where('id_number', $idNumberUpper)->lockForUpdate()->first();

            // Si le client n'existe toujours pas, on le crée en toute sécurité
            if (!$senderCustomer) {
                $usernameSeed = Str::slug($validatedData['sender_first_name'] . ' ' . $validatedData['sender_last_name']);

                // Création du User de base pour le client
                $customerUser = User::create([
                    'uuid'         => (string) Str::uuid(),
                    'username'     => strtolower(substr($usernameSeed, 0, 10) . rand(100, 999)),
                    'first_name'   => strtoupper($validatedData['sender_first_name']),
                    'last_name'    => ucwords(strtolower($validatedData['sender_last_name'])),
                    'phone_number' => $cleanSenderPhone,
                    'email'        => $validatedData['sender_email'] ?? null,
                    'password'     => Hash::make(Str::random(16)), // 'Default@2026' est une faille de sécurité, préférez un random ou nul si pas d'accès direct
                    'is_active'    => true,
                ]);

                $customerUser->assignRole('customer');
                $clientReference = 'CLI-' . strtoupper(Str::random(8));

                // Création du profil Customer associé
                $senderCustomer = Customer::create([
                    'user_id'        => $customerUser->id,
                    'reference'      => $clientReference,
                    'id_type'        => $validatedData['sender_id_type'],
                    'id_number'      => $idNumberUpper,
                    'id_expiry_date' => $validatedData['sender_id_expiry'],
                    'country_id'     => $sourceAgency->country_id,
                    'city_id'        => $validatedData['sender_city_id'],
                    'address'        => $validatedData['sender_address'],
                    'status'         => 'active',
                    'kyc_status'     => 'approved',
                ]);

                // Création du portefeuille principal
                Wallet::create([
                    'uuid'          => (string) Str::uuid(),
                    'wallet_number' => 'W-' . $clientReference,
                    'type'          => 'main',
                    'currency'      => $sourceAgency->country->currency_code ?? 'XAF',
                    'balance'       => 0.00,
                    'is_active'     => true,
                    'owner_id'      => $senderCustomer->id,
                    'owner_type'    => Customer::class
                ]);
            }

            // Normalisation internationale du numéro du bénéficiaire sécurisée (on est sûr que country existe)
            $cleanRecipientPhone = format_to_international($validatedData['recipient_phone'], $sourceAgency->country->id);

            $transactionData = [
                'amount'                 => (float) $validatedData['amount'],
                'sender_customer_id'     => $senderCustomer->id,
                'sender_name'            => $senderCustomer->user->first_name . ' ' . $senderCustomer->user->last_name,
                'sender_phone'           => $senderCustomer->user->phone_number,
                'recipient_name'         => $validatedData['recipient_name'],
                'recipient_phone'        => $cleanRecipientPhone,
                'recipient_email'        => $validatedData['recipient_email'] ?? null,
                'destination_country_id' => (int) $validatedData['destination_country_id'],
            ];

            // Appel de la logique comptable d'émission
            return $this->remittanceService->initiateRemittance($staff->user, $sourceAgency, $transactionData);
        });

        // 6. ARCHIVAGE IMMUABLE DU CONTRÔLE DE FRAUDE
        $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

        // 7. JOURNALISATION D'AUDIT SYSTÈME
        SystemAuditLog::create([
            'user_id'    => $user->id,
            'agency_id'  => $sourceAgency->id,
            'event_type' => 'REMITTANCE.INITIATED',
            'severity'   => 'info',
            'message'    => "Mandat cash émis avec succès par l'opérateur [{$staff->employee_code}]. Référence : {$transactionResult->reference}",
            'payload'    => [
                'reference'  => $transactionResult->reference,
                'amount'     => (float) $transactionResult->amount,
                'recipient'  => $validatedData['recipient_name'],
                'staff_id'   => $staff->id,
                'risk_score' => $fraudAnalysis['risk_score']
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // 8. RÉPONSE ALIGNÉE AVEC L'UI NEXT.JS
        return response()->json([
            'success' => true,
            'message' => 'Le mandat cash a été émis et validé avec succès au guichet.',
            'data'    => [
                'reference'       => $transactionResult->reference,
                'secure_code'     => $transactionResult->secure_code,
                'amount'          => (float) $transactionResult->amount,
                'fees'            => (float) $transactionResult->fees,
                'total_paid'      => (float) ($transactionResult->amount + $transactionResult->fees + $transactionResult->taxes),
                'currency'        => $transactionResult->currency,
                'sender_name'     => $transactionResult->sender_name,
                'recipient_name'  => $transactionResult->recipient_name,
                'recipient_email' => $transactionResult->recipient_email,
                'created_at'      => $transactionResult->created_at->toIso8601String()
            ]
        ], 201);

    } catch (Exception $e) {
            // Nettoyage des logs pour ne pas sauvegarder de données KYC sensibles en clair
            Log::error("Échec lors de l'émission du mandat au guichet : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace'   => $e->getTraceAsString() // Préférable au request->all() brut
            ]);

            return response()->json([
                'success' => false,
                'message' => "Impossible de finaliser le transfert : " . $e->getMessage()
            ], 422);
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
            'reference'           => 'required|string|max:100',
            'secure_code'         => 'required|string|max:50',
            'recipient_id_type'   => 'required|in:cni,passport,recepisse,carte_sejour',
            'recipient_id_number' => 'required|string|max:150',
            'recipient_id_expiry' => 'required|date|after:today',
        ], [
            'recipient_id_expiry.after' => 'La pièce d’identité présentée a expiré.',
        ]);

        try {
            $user = Auth::user();

            // 1. Extraction et validation du profil de l'opérateur payeur
            $staff = Staff::with('agency')->where('user_id', $user->id)->first();
            $destinationAgency = $staff?->agency;

        if (!$destinationAgency) {
            return response()->json([
                'success' => false,
                'message' => "Erreur de configuration : Votre session n'est liée à aucune agence distributrice active."
            ], 403);
        }

        // Initialisation de la variable pour la portée globale hors de la transaction DB
        $fraudAnalysis = null;

        // 2 & 4. ISOLATION ACID ET VERROUILLAGE CONTRE LE DOUBLE DÉCAISSEMENT
        $transactionResult = DB::transaction(function () use ($validated, $user, $destinationAgency, $staff, &$fraudAnalysis) {

            // Récupération avec VERROU STRICT (lockForUpdate) et validation du statut initial requis
            $transaction = Transaction::where('reference', trim($validated['reference']))
                ->where('secure_code', trim($validated['secure_code']))
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new Exception("Mandat introuvable ou paramètres de sécurité incorrects.", 404);
            }

            // Sécurité métier : Le mandat doit impérativement être en attente de paiement
            // Adaptez 'pending' selon la valeur exacte de votre ENUM / Statut en DB (ex: 'active', 'payable')
            if ($transaction->status !== 'pending') {
                throw new Exception("Ce mandat ne peut pas être payé. Statut actuel : [" . strtoupper($transaction->status) . "].", 422);
            }

            // 3. CONTRÔLE ANTI-FRAUDE & COMPLICITÉ INTERNE (Exécuté au chaud sous le verrou)
            $fraudAnalysis = $this->fraudService->analyze(
                'remittance_payout',
                null,
                (float) $transaction->amount,
                [
                    'transaction' => $transaction,
                    'payout_staff_id' => $staff->id // Utile pour détecter si l'émetteur == le payeur
                ]
            );

            // Si le pare-feu anti-fraude lève un blocage
            if ($fraudAnalysis['is_blocked'] || $fraudAnalysis['is_flagged']) {
                throw new Exception("BLOQUÉ_FRAUDE: " . $fraudAnalysis['reason'], 403);
            }

            // 4. EXÉCUTION DU DÉCAISSEMENT COMPTABLE (Le service doit passer le statut à 'paid')
            return $this->remittanceService->payoutRemittance(
                $staff->user,
                $destinationAgency,
                array_merge($validated, ['transaction_id' => $transaction->id])
            );
        });

        // 5. ARCHIVAGE DU RAPPORT DE RISQUE LIÉ À LA TRANSACTION
        $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

        // Masquage du numéro de pièce d'identité pour la conformité d'audit (RGPD)
        $maskedIdNumber = substr(trim($validated['recipient_id_number']), 0, 3) . '****' . substr(trim($validated['recipient_id_number']), -3);

        // 6. JOURNALISATION D'AUDIT TECHNIQUE
        SystemAuditLog::create([
            'user_id'    => $user->id,
            'agency_id'  => $destinationAgency->id,
            'event_type' => 'REMITTANCE.PAID',
            'severity'   => 'info',
            'message'    => "Mandat cash payé avec succès à l'agence [{$destinationAgency->name}]. Opérateur : {$staff->employee_code}",
            'payload'    => [
                'reference'  => $transactionResult->reference,
                'amount'     => (float) $transactionResult->amount,
                'recipient'  => $transactionResult->recipient_name,
                'staff_id'   => $staff->id,
                'id_verified'=> strtoupper($validated['recipient_id_type']) . " - " . $maskedIdNumber
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // 7. RÉPONSE RETOURNÉE À L'UI NEXT.JS
        return response()->json([
            'success' => true,
            'message' => 'Le paiement a été approuvé. Le mandat passe au statut [PAID]. Vous pouvez décaisser les fonds du guichet.',
            'data'    => [
                'reference'   => $transactionResult->reference,
                'paid_amount' => (float) $transactionResult->amount,
                'currency'    => $transactionResult->currency,
                'paid_at'     => $transactionResult->completed_at ? $transactionResult->completed_at->toIso8601String() : now()->toIso8601String()
            ]
        ], 200);

    } catch (Exception $e) {
            // Interception spécifique pour la fraude afin de logguer l'alerte de sécurité
            if (str_starts_with($e->getMessage(), 'BLOQUÉ_FRAUDE:')) {
                SystemAuditLog::create([
                    'user_id'    => Auth::id(),
                    'agency_id'  => $destinationAgency?->id,
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
                'user_id'   => Auth::id(),
                'reference' => $request->input('reference')
            ]);

            // Gestion propre du code HTTP de retour selon l'exception levée
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
            'reference'   => 'required_without:secure_code|nullable|string|max:100',
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
                        'status'    => $transaction->status
                    ]
                ], 422);
            }

            return response()->json([
                'success' => true,
                'is_payable' => true,
                'message' => "Mandat trouvé et disponible pour décaissement.",
                'data' => [
                    'id'               => $transaction->id,
                    'reference'        => $transaction->reference,
                    'secure_code'      => $transaction->secure_code,
                    'amount'           => (float) $transaction->amount,
                    'currency'         => $transaction->currency,
                    'status'           => $transaction->status,
                    'sender_name'      => $transaction->sender_name,
                    'sender_phone'     => $transaction->sender_phone,
                    'recipient_name'   => $transaction->recipient_name,
                    'recipient_phone'  => $transaction->recipient_phone,
                    'recipient_email'  => $transaction->recipient_email,
                    'source_country'   => $transaction->senderCountry?->name,
                    'source_city'      => $transaction->senderCity?->name,
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
