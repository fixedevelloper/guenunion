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
use App\Services\RemittanceService;
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
}
