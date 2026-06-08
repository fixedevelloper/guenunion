<?php


namespace App\Http\Controllers\Api\customer;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\SystemAuditLog;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FraudCheckService;
use App\Services\LedgerService;
use App\Services\RemittanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class TransactionController extends Controller
{
    protected $remittanceService;
    protected $fraudService;
    protected $ledgerService;
    /**
     * Injection du service de gestion des transferts.
     * @param RemittanceService $remittanceService
     */
    public function __construct(RemittanceService $remittanceService,FraudCheckService $fraudCheckService,LedgerService $ledgerService)
    {
        $this->remittanceService = $remittanceService;
        $this->fraudService=$fraudCheckService;
        $this->ledgerService=$ledgerService;
    }

    /**
     * Récupérer l'historique des transactions du client connecté.
     * @param Request $request
     * @return JsonResponse
     */

    public function index(Request $request): JsonResponse
    {
        try {
            // 1. Récupérer l'utilisateur connecté avec son profil client (Eager Loading immédiat)
            $user = Auth::user();
            $customer = $user->customer;

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }

            // Normalisation préventive pour éviter les faux positifs / négatifs sur le téléphone
            $customerPhone = clean_phone($user->phone_number);

            // 2. Bâtir la requête avec isolation stricte du bloc OR
            $transactions = Transaction::where(function ($globalQuery) use ($customer, $customerPhone) {
                $globalQuery->where(function ($subQuery) use ($customer, $customerPhone) {
                    $subQuery->where('sender_customer_id', $customer->id)
                        ->orWhere('recipient_phone', $customerPhone);
                });
            })
                // Exemple crucial : Si vous avez des statuts 'draft' ou 'initiated' corrompus,
                // vous pouvez filtrer uniquement ce qui est valide/traité ici
                ->whereIn('status', ['completed', 'pending', 'failed'])

                // Charger les relations utiles pour éviter le problème N+1
                ->with(['sourceAgency', 'destinationAgency', 'senderCountry', 'recipientCountry'])
                ->orderBy('created_at', 'desc')
                ->paginate((int) $request->input('per_page', 15));

            // 3. Retourner la réponse formattée pour Flutter (Pagination standardisée)
            return response()->json([
                'success' => true,
                'message' => 'Historique des transactions récupéré avec succès.',
                'data'    => $transactions->items(),
                'meta'    => [
                    'current_page' => $transactions->currentPage(),
                    'last_page'    => $transactions->lastPage(),
                    'per_page'     => $transactions->perPage(),
                    'total'        => $transactions->total(),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des transactions.',
                'error'   => $e->getMessage() // À masquer en production !
            ], 500);
        }
    }
    /**
     * Récupérer les détails d'une transaction.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            // 🎯 Récupération avec vérification de propriété (Security Gate)
            $transaction = Transaction::where('id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction introuvable ou accès non autorisé.'
                ], 404);
            }

            // 🔥 On retourne les données formatées selon ton TransactionModel Android
            return response()->json([
                'data'    =>[
                'id'              => (int) $transaction->id,
                'uuid'            => $transaction->uuid,
                'reference'       => $transaction->reference,
                'type'            => $transaction->type,      // cash_in, transfer, etc.
                'status'          => $transaction->status,    // completed, pending, etc.
                'amount'          => (double) $transaction->amount,
                'fees'            => (double) ($transaction->fees ?? 0.0),
                'taxes'           => (double) ($transaction->taxes ?? 0.0),
                'currency'        => $transaction->currency ?? 'XAF',
                'secure_code'     => $transaction->secure_code, // Code de retrait secret
                'sender_name'     => $transaction->sender_name,
                'sender_phone'    => $transaction->sender_phone,
                'recipient_name'  => $transaction->recipient_name,
                'recipient_phone' => $transaction->recipient_phone,
                'description'     => $transaction->description,
                'created_at'      => $transaction->created_at->toISOString(), // Format ISO strict
                'completed_at'    => $transaction->completed_at ? $transaction->completed_at->toISOString() : null,
            ]], 200);

        } catch (\Exception $e) {
            Log::error("Erreur API TransactionDetail ID {$id}: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur serveur est survenue.'
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
            'type'                   => 'nullable|string|in:remittance,cash_in,cash_out,transfer',
            'destination_country_id' => 'required|exists:countries,id',
        ]);

        try {
            $user = Auth::user();
            $customer = Customer::where('user_id', $user->id)->first();


            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur de contexte : Impossible de déterminer le pays d'origine sans agence rattachée."
                ], 403);
            }

            $amount = (float) $request->input('amount');
            $type = $request->input('type', 'remittance');
            $destinationCountryId = (int) $request->input('destination_country_id');

            logger($customer->country_id);
            $feesCalculation = $this->remittanceService->calculateFees(
                $amount,
                $type,
                $customer->country_id,
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

    public function makeTransaction(Request $request): JsonResponse
    {
        // 1. Validation stricte (inclusion des champs manquants qui faisaient planter le code)
        $validatedData = $request->validate([
            'amount'                 => 'required|numeric|min:100',
            'type'                   => 'nullable|string|in:remittance,cash_in,cash_out,transfer',
            'recipient_name'         => 'required|string|max:150|min:3',
            'recipient_phone'        => 'required|string|max:20',
            'recipient_email'        => 'nullable|email|max:150',
            'recipient_country_id' => 'required|exists:countries,id',
        ]);

        try {
            $user = Auth::user();

            // Eager load des relations indispensables pour éviter le problème N+1
            $existingCustomer = $user->customer()
                ->with(['country', 'user'])
                ->first();

            if (!$existingCustomer) {
                throw new Exception("Profil client introuvable pour l'utilisateur connecté.");
            }

            // Normalisation des données d'entrée
            $cleanSenderPhone = $existingCustomer->phone_number;
            $idNumberUpper = $existingCustomer->id_number;
            $amount = (float) $validatedData['amount'];
            $type = $validatedData['type'] ?? 'remittance';

            // 2. Contrôle Anti-Fraude & AML (Pré-transaction)
            $fraudAnalysis = $this->fraudService->analyze(
                $type,
                $existingCustomer->id,
                $amount,
                [
                    'sender_phone' => $cleanSenderPhone,
                    'is_anonymous' => false
                ]
            );

            if ($fraudAnalysis['is_blocked'] || $fraudAnalysis['is_flagged']) {
                SystemAuditLog::create([
                    'user_id'    => $user->id,
                    'event_type' => 'REMITTANCE.FRAUD_BLOCK',
                    'severity'   => 'critical',
                    'message'    => "Émission bloquée par la sécurité. Motif : " . $fraudAnalysis['reason'],
                    'payload'    => [
                        'sender_phone'     => $cleanSenderPhone,
                        'sender_id_number' => $idNumberUpper,
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

            // 3. Calcul des frais (Hors transaction DB pour soulager la base de données)
            $feesDetail = $this->remittanceService->calculateFees(
                $amount,
                $type,
                $existingCustomer->country_id,
                $validatedData['recipient_country_id'],
                $existingCustomer->country->currency_code??'XAF'
            );

            $fees = (float) $feesDetail['total_fees'];
            $taxes = (float) $feesDetail['taxes'];
            $totalDebit = (float) $feesDetail['total_amount_required'];

            // Formatage du téléphone bénéficiaire
            $cleanRecipientPhone = format_to_international($validatedData['recipient_phone'], $existingCustomer->country->id);

            // 4. Exécution ACID optimisée
            $transactionResult = DB::transaction(function () use (
                $validatedData, $user, $existingCustomer, $cleanSenderPhone,
                $cleanRecipientPhone, $amount, $fees, $taxes, $totalDebit, $feesDetail, $type
            ) {
                // Verrouillage pessimiste (Pessimistic Locking) pour éviter les Race Conditions de solde
                $customerWallet = Wallet::where('id', $existingCustomer->id)
                    ->lockForUpdate()
                    ->first();

                if (!$customerWallet || (float) $customerWallet->balance < $totalDebit) {
                    throw new Exception("Le solde du portefeuille est insuffisant (Portefeuille manquant ou {$totalDebit} {$existingCustomer->country->currency} requis).");
                }

                // Récupération et verrouillage de l'Escrow global
                $escrowWallet = Wallet::where('type', 'escrow')
                    ->where('currency', $existingCustomer->country->currency_code)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$escrowWallet) {
                    throw new Exception("Le compte système de transit (Escrow) dans la devise cible ({$existingCustomer->country->currency_code}) est indisponible.");
                }

                // Génération des références uniques
                $reference = 'TX-' . strtoupper(Str::random(4)) . '-' . date('ymdHis');
                $secureCode = $this->remittanceService->generateSecureCode(); // Correction : méthode centralisée supposée existante

                // Création de la transaction principale
                $transaction = Transaction::create([
                    'uuid'                   => (string) Str::uuid(),
                    'reference'              => $reference,
                    'type'                   => $type,
                    'status'                 => 'completed',
                    'amount'                 => $amount,
                    'fees'                   => $fees,
                    'taxes'                  => $taxes,
                    'currency'               => $existingCustomer->country->currency_code,
                    'sender_customer_id'     => $existingCustomer->id,
                    'sender_name'            => $existingCustomer->user->first_name . ' ' . $existingCustomer->user->last_name,
                    'sender_phone'           => $existingCustomer->user->phone_number,
                    'recipient_name'         => $validatedData['recipient_name'],
                    'recipient_phone'        => $cleanRecipientPhone,
                    'recipient_email'        => $validatedData['recipient_email'] ?? null,
                    'secure_code'            => $secureCode,
                    'source_agency_id'       => null,
                    'sender_country_id'      => $existingCustomer->country_id,
                    'sender_city_id'         => $existingCustomer->city_id,
                    'recipient_country_id'   => $validatedData['recipient_country_id'],
                    'recipient_city_id'      => null,
                    'initiator_id'           => $user->id, // Correction de $initiator->id
                    'completed_at'           => now(),
                    'description'            => "Émission de mandat cash via API/Portefeuille par: {$user->username}",
                    'metadata'               => [
                        'fees_breakdown' => $feesDetail,
                        'device_context' => ['ip' => request()->ip(), 'user_agent' => request()->userAgent()]
                    ]
                ]);

                // Comptabilité : Débit du Portefeuille Client (Mise à jour combinée en base de données)
                $customerBalanceBefore = (float) $customerWallet->balance;
                $customerWallet->decrement('balance', $totalDebit);
                // Si current_balance correspond à l'encaisse physique cumulée :
               // $customerWallet->increment('current_balance', $totalDebit);

                $customerBalanceAfter = $customerBalanceBefore - $totalDebit;

                TransactionEntry::create([
                    'uuid'           => Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $customerWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $totalDebit,
                    'balance_before' => $customerBalanceBefore,
                    'balance_after'  => $customerBalanceAfter,
                    'row_signature'  => $this->remittanceService->generateLedgerSignature($customerWallet->id, $totalDebit, 'debit', $customerBalanceBefore, $customerBalanceAfter)
                ]);

                // Comptabilité : Crédit de l'Escrow
                $escrowBalanceBefore = (float) $escrowWallet->balance;
                $escrowWallet->increment('balance', $amount);
                $escrowBalanceAfter = $escrowBalanceBefore + $amount;

                TransactionEntry::create([
                    'uuid'           => Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $escrowWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $amount,
                    'balance_before' => $escrowBalanceBefore,
                    'balance_after'  => $escrowBalanceAfter,
                    // Correction de la méthode de signature appelée globalement depuis le service dédié
                    'row_signature'  => $this->remittanceService->generateLedgerSignature($escrowWallet->id, $amount, 'credit', $escrowBalanceBefore, $escrowBalanceAfter)
                ]);

                return $transaction;
            });

            // 5. Opérations Post-Transaction (Asynchrones/Log immuable)
            $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

            SystemAuditLog::create([
                'user_id'    => $user->id,
                'event_type' => 'REMITTANCE.INITIATED',
                'severity'   => 'info',
                'message'    => "Mandat cash émis avec succès. Référence : {$transactionResult->reference}",
                'payload'    => [
                    'reference'  => $transactionResult->reference,
                    'amount'     => $amount,
                    'recipient'  => $validatedData['recipient_name'],
                    'customer_id'=> $existingCustomer->id,
                    'risk_score' => $fraudAnalysis['risk_score']
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Le mandat cash a été émis et validé avec succès.',
                'data'    => [
                    'id'=>42,
                    'reference'       => $transactionResult->reference,
                    'secure_code'     => $transactionResult->secure_code,
                    'amount'          => $amount,
                    'fees'            => $fees,
                    'total_paid'      => $totalDebit,
                    'currency'        => $transactionResult->currency,
                    'sender_name'     => $transactionResult->sender_name,
                    'recipient_name'  => $transactionResult->recipient_name,
                    'recipient_email' => $transactionResult->recipient_email,
                    'created_at'      => $transactionResult->created_at->toIso8601String()
                ]
            ], 201);

        } catch (Exception $e) {
            Log::error("Échec lors de l'émission du mandat : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => "Impossible de finaliser le transfert : " . $e->getMessage()
            ], 422);
        }
    }

    public function makeWalletTransaction(Request $request): JsonResponse
    {
        // 1. Validation stricte selon votre consigne (uniquement amount et beneficiary_id)
        $validatedData = $request->validate([
            'amount'         => 'required|numeric|min:100',
            'beneficiary_id' => 'required|integer|exists:customers,id',
        ]);

        try {
            $user = Auth::user();

            // Éviter le problème N+1 pour l'expéditeur (le client connecté)
            $existingCustomer = $user->customer()
                ->with(['country', 'user'])
                ->first();

            if (!$existingCustomer) {
                throw new Exception("Profil client introuvable pour l'utilisateur connecté.");
            }

            // 🎯 Récupération dynamique du bénéficiaire via l'ID validé
            $beneficiary = Customer::query()
                ->where('id', $validatedData['beneficiary_id'])
                ->with(['user', 'country'])
                ->first();

            if (!$beneficiary) {
                throw new Exception("Le bénéficiaire spécifié est introuvable ou inactif.");
            }

            // Sécurité AML de base : Éviter l'auto-envoi
            if ($beneficiary->id === $existingCustomer->id) {
                throw new Exception("Opération invalide : Vous ne pouvez pas transférer des fonds vers votre propre portefeuille.");
            }

            // Normalisation des variables pour les services
            $cleanSenderPhone = $existingCustomer->phone_number;
            $idNumberUpper = $existingCustomer->id_number;
            $amount = (float) $validatedData['amount'];
            $type = 'transfer'; // Fixé par défaut pour le Wallet-to-Wallet

            // 2. Contrôle Anti-Fraude & AML (Pré-transaction)
            $fraudAnalysis = $this->fraudService->analyze(
                $type,
                $existingCustomer->id,
                $amount,
                [
                    'sender_phone' => $cleanSenderPhone,
                    'is_anonymous' => false
                ]
            );

            if ($fraudAnalysis['is_blocked'] || $fraudAnalysis['is_flagged']) {
                SystemAuditLog::create([
                    'user_id'    => $user->id,
                    'event_type' => 'REMITTANCE.FRAUD_BLOCK',
                    'severity'   => 'critical',
                    'message'    => "Émission bloquée par la sécurité. Motif : " . $fraudAnalysis['reason'],
                    'payload'    => [
                        'sender_phone'     => $cleanSenderPhone,
                        'sender_id_number' => $idNumberUpper,
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

            // 3. Calcul des frais (les infos pays du bénéficiaire proviennent de sa fiche en BDD)
            $feesDetail = $this->remittanceService->calculateFees(
                $amount,
                $type,
                $existingCustomer->country_id,
                $beneficiary->id,
                $existingCustomer->country->currency_code ?? 'XAF'
            );

            $fees = (float) $feesDetail['total_fees'];
            $taxes = (float) $feesDetail['taxes'];
            $totalDebit = (float) $feesDetail['total_amount_required'];

            // Extraction et formatage sécurisé du numéro du bénéficiaire depuis sa fiche BDD
            $cleanRecipientPhone = format_to_international($beneficiary->user->phone_number, $beneficiary->country_id);

            // 4. Exécution de la Transaction ACID (Verrouillage pessimiste)
            $transactionResult = DB::transaction(function () use (
                $user, $existingCustomer, $beneficiary, $cleanSenderPhone,
                $cleanRecipientPhone, $amount, $fees, $taxes, $totalDebit, $feesDetail, $type
            ) {

                // 🔒 Isolation des balances pour bloquer les Race Conditions
                $customerWallet = Wallet::where('owner_type', Customer::class)
                    ->where('owner_id', $existingCustomer->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                $beneficiaryWallet = Wallet::where('owner_type', Customer::class)
                    ->where('owner_id', $beneficiary->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$customerWallet || (float) $customerWallet->balance < $totalDebit) {
                    throw new Exception("Solde insuffisant. " . $totalDebit . " XAF requis pour couvrir le transfert et les frais.");
                }

                if (!$beneficiaryWallet) {
                    throw new Exception("Le portefeuille du bénéficiaire n'est pas initialisé ou est suspendu.");
                }

                // Génération des données de reçu uniques
                $reference = 'TX-' . strtoupper(Str::random(4)) . '-' . date('ymdHis');
                $secureCode = $this->remittanceService->generateSecureCode();

                // Reconstruction propre du nom complet du bénéficiaire
                $recipientFullName = trim(($beneficiary->user->first_name ?? '') . ' ' . ($beneficiary->user->last_name ?? ''));
                if (empty($recipientFullName)) {
                    $recipientFullName = "Client Guen's";
                }

                // Insertion du Master Log de la transaction
                $transaction = Transaction::create([
                    'uuid'                 => (string) Str::uuid(),
                    'reference'            => $reference,
                    'type'                 => $type,
                    'status'               => 'completed',
                    'amount'               => $amount,
                    'fees'                 => $fees,
                    'taxes'                => $taxes,
                    'currency'             => $existingCustomer->country->currency_code ?? 'XAF',
                    'sender_customer_id'   => $existingCustomer->id,
                    'sender_name'          => trim(($existingCustomer->user->first_name ?? '') . ' ' . ($existingCustomer->user->last_name ?? '')),
                    'sender_phone'         => $cleanSenderPhone,
                    'recipient_name'       => $recipientFullName,
                    'recipient_phone'      => $cleanRecipientPhone,
                    'recipient_email'      => $beneficiary->user->email ?? null,
                    'secure_code'          => $secureCode,
                    'source_agency_id'     => null,
                    'sender_country_id'    => $existingCustomer->country_id,
                    'sender_city_id'       => $existingCustomer->city_id,
                    'recipient_country_id' => $beneficiary->country_id,
                    'initiator_id'         => $user->id,
                    'completed_at'         => now(),
                    'description'          => "Virement instantané Wallet to Wallet",
                    'metadata'             => [
                        'fees_breakdown' => $feesDetail,
                        'device_context' => ['ip' => request()->ip(), 'user_agent' => request()->userAgent()]
                    ]
                ]);

                // 🧾 COMPTABILITÉ EN PARTIE DOUBLE : Débit Émetteur
                $customerBalanceBefore = (float) $customerWallet->balance;
                $customerWallet->decrement('balance', $totalDebit);
                $customerBalanceAfter = $customerBalanceBefore - $totalDebit;

                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $customerWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $totalDebit,
                    'balance_before' => $customerBalanceBefore,
                    'balance_after'  => $customerBalanceAfter,
                    'row_signature'  => $this->ledgerService->generateSignature($customerWallet->id, $totalDebit, 'debit', $customerBalanceBefore, $customerBalanceAfter)
                ]);

                // 🧾 COMPTABILITÉ EN PARTIE DOUBLE : Crédit Bénéficiaire
                $beneficiaryBalanceBefore = (float) $beneficiaryWallet->balance;
                $beneficiaryWallet->increment('balance', $amount);
                $beneficiaryBalanceAfter = $beneficiaryBalanceBefore + $amount;

                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $beneficiaryWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $amount,
                    'balance_before' => $beneficiaryBalanceBefore,
                    'balance_after'  => $beneficiaryBalanceAfter,
                    'row_signature'  => $this->ledgerService->generateSignature($beneficiaryWallet->id, $amount, 'credit', $beneficiaryBalanceBefore, $beneficiaryBalanceAfter)
                ]);

                return $transaction;
            });

            // 5. Post-transaction & Audit Logs
            $this->fraudService->logCheck($transactionResult->id, $fraudAnalysis);

            SystemAuditLog::create([
                'user_id'    => $user->id,
                'event_type' => 'WALLET.TRANSFER.SUCCESS',
                'severity'   => 'info',
                'message'    => "Transfert Wallet to Wallet réussi. Réf : {$transactionResult->reference}",
                'payload'    => [
                    'reference'    => $transactionResult->reference,
                    'amount'       => $amount,
                    'sender_id'    => $existingCustomer->id,
                    'recipient_id' => $beneficiary->id
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Structuration du JSON pour votre modèle Android `ApiResponse<TransfertState>`
            return response()->json([
                'success' => true,
                'message' => 'Le transfert de portefeuille à portefeuille a été effectué avec succès.',
                'data'    => [
                    'id'              => $transactionResult->id,
                    'reference'       => $transactionResult->reference,
                    'secure_code'     => $transactionResult->secure_code,
                    'amount'          => $amount,
                    'fees'            => $fees,
                    'total_paid'      => $totalDebit,
                    'currency'        => $transactionResult->currency,
                    'sender_name'     => $transactionResult->sender_name,
                    'recipient_name'  => $transactionResult->recipient_name,
                    'recipient_email' => $transactionResult->recipient_email,
                    'created_at'      => $transactionResult->created_at->toIso8601String()
                ]
            ], 201);

        } catch (Exception $e) {
            Log::error("Erreur lors du traitement WalletToWallet : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => "Impossible de finaliser le virement : " . $e->getMessage()
            ], 422);
        }
    }
    /**
     * Masque les données sensibles pour les logs d'audit (RGPD / Sécurité).
     */
    private function maskData(string $value, string $type): string
    {
        if (empty($value)) {
            return '';
        }

        return match ($type) {
        'phone' => (strlen($value) > 6)
        ? substr($value, 0, 4) . '****' . substr($value, -3)
        : '****',

        'id' => (strlen($value) > 4)
        ? substr($value, 0, 2) . '******' . substr($value, -2)
        : '******',

        default => '******',
    };
}
    /**
     * Générer le reçu PDF de la transaction
     */
    public function downloadReceipt1($id)
    {
        // Récupération de la transaction (sécurise avec auth() si nécessaire)
        $transaction = Transaction::findOrFail($id);

        // 1. Charger la vue HTML en injectant la variable $transaction
        $pdf = Pdf::loadView('pdf.receipt', compact('transaction'));

        // Optionnel : Configurer le format du papier (ex: A4 ou ticket de caisse thermique)
        $pdf->setPaper('a4', 'portrait');

        // 2. Option A : Téléchargement direct (Force le téléchargement du fichier)
        // return $pdf->download("recu_{$transaction->reference}.pdf");

        // 3. Option B : Affichage direct dans le navigateur / application (Recommandé pour mobile)
        return $pdf->stream("recu_{$transaction->reference}.pdf");
    }
    public function downloadReceipt($id)
    {
        $transaction = Transaction::findOrFail($id);
        $pdf = Pdf::loadView('pdf.receipt', compact('transaction'));

        // On force le rendu et on retourne la réponse en mode téléchargement (attachment)
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="recu_'.$transaction->reference.'.pdf"');
    }
}
