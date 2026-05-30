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

    /**
     * Injection du service de gestion des transferts.
     */
    public function __construct(RemittanceService $remittanceService)
    {
        $this->remittanceService = $remittanceService;
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
     * ÉTAPE 2 : Initialiser et valider une émission de fonds au guichet (KYC Expéditeur & Destinataire).
     * @param Request $request
     * @return JsonResponse
     */
    public function initiate(Request $request): JsonResponse
    {
        logger($request->all());
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

            // Extraction du profil guichetier/caissier
            $staff = Staff::with('agency.country')->where('user_id', $user->id)->first();
            $sourceAgency = $staff?->agency;

            if (!$sourceAgency) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur de sécurité : Votre compte utilisateur n'est rattaché à aucune agence ou guichet physique valide."
                ], 403);
            }

            // Normalisation via helpers locaux
            $cleanSenderPhone = clean_phone($validatedData['sender_phone']);
            $idNumberUpper = strtoupper($validatedData['sender_id_number']);

            // Exécution sous isolation transactionnelle ACID
            $transactionResult = DB::transaction(function () use ($validatedData, $user, $staff, $sourceAgency, $cleanSenderPhone, $idNumberUpper) {

                // A. Résolution du client par sa pièce unique
                $senderCustomer = Customer::where('id_number', $idNumberUpper)->first();

                if (!$senderCustomer) {
                    $usernameSeed = Str::slug($validatedData['sender_first_name'] . ' ' . $validatedData['sender_last_name']);

                    $customerUser = User::create([
                        'uuid'         => (string) Str::uuid(),
                        'username'     => strtolower(substr($usernameSeed, 0, 10) . rand(100, 999)),
                        'first_name'   => strtoupper($validatedData['sender_first_name']),
                        'last_name'    => ucwords(strtolower($validatedData['sender_last_name'])),
                        'phone_number' => $cleanSenderPhone,
                        'email'        => $validatedData['sender_email'] ?? null,
                        'password'     => Hash::make('Default@2026'),
                        'is_active'    => true,
                    ]);

                    $customerUser->assignRole('auditor');

                    $senderCustomer = Customer::create([
                        'user_id'        => $customerUser->id,
                        'reference'      => 'CLI-' . strtoupper(Str::random(8)),
                        'id_type'        => $validatedData['sender_id_type'],
                        'id_number'      => $idNumberUpper,
                        'id_expiry_date' => $validatedData['sender_id_expiry'],
                        'country_id'     => $sourceAgency->country_id,
                        'city_id'        => $validatedData['sender_city_id'],
                        'address'        => $validatedData['sender_address'],
                        'status'         => 'active',
                    ]);
                }

                // Normalisation du numéro destinataire indexé sur l'identifiant pays d'agence
                $cleanRecipientPhone = format_to_international($validatedData['recipient_phone'], $sourceAgency->country->id ?? '237');

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
                logger($sourceAgency);
                // Injection du profil Staff pour l'imputation financière du guichet
                return $this->remittanceService->initiateRemittance($staff->user, $sourceAgency, $transactionData);
            });

            // Journalisation technique de l'audit système via l'ID Staff
            SystemAuditLog::create([
                'user_id'    => $user->id,
                'agency_id'  => $sourceAgency->id,
                'event_type' => 'REMITTANCE.INITIATED',
                'severity'   => 'info',
                'message'    => "Mandat cash émis avec succès par l'opérateur [{$staff->employee_code}]. Référence : {$transactionResult->reference}",
                'payload'    => [
                    'reference'    => $transactionResult->reference,
                    'amount'       => (float) $transactionResult->amount,
                    'recipient'    => $validatedData['recipient_name'],
                    'staff_id'     => $staff->id
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Le mandat cash a été émis et validé avec succès au guichet.',
                'data'    => [
                    'reference'       => $transactionResult->reference,
                    'secure_code'     => $transactionResult->secure_code, // Clé de sécurisation d'encaissement
                    'amount'          => (float) $transactionResult->amount,
                    'fees'            => (float) $transactionResult->fees,
                    'total_paid'      => (float) ($transactionResult->amount + $transactionResult->fees),
                    'currency'        => $transactionResult->currency,
                    'sender_name'     => $transactionResult->sender_name,
                    'recipient_name'  => $transactionResult->recipient_name,
                    'recipient_email' => $transactionResult->recipient_email,
                    'created_at'      => $transactionResult->created_at->toIso8601String()
                ]
            ], 201);

        } catch (Exception $e) {
            Log::error("Échec lors de l'émission du mandat au guichet : " . $e->getMessage(), [
                'user_id' => Auth::id(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => "Impossible de finaliser le transfert : " . $e->getMessage()
            ], 422);
        }
    }

    /**
     * ÉTAPE 3 : Payer un mandat (Décaissement/Retrait) au bénéficiaire.
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
            $staff = Staff::where('user_id', $user->id)->first();
            $destinationAgency = $staff?->agency;

            if (!$destinationAgency) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur de configuration : Votre session n'est liée à aucune agence distributrice active."
                ], 403);
            }

            // Traitement financier via le service dédié (imputation sur la caisse physique du staff)
            $transaction = $this->remittanceService->payoutRemittance(
                $staff->user,
                $destinationAgency,
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Le paiement a été approuvé. Le mandat passe au statut [PAID]. Vous pouvez décaisser les fonds du guichet.',
                'data'    => [
                    'reference'   => $transaction->reference,
                    'paid_amount' => (float) $transaction->amount,
                    'currency'    => $transaction->currency,
                    'paid_at'     => $transaction->completed_at ? $transaction->completed_at->toIso8601String() : now()->toIso8601String()
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Échec lors du décaissement du mandat au guichet : " . $e->getMessage(), [
                'user_id'     => Auth::id(),
                'reference'   => $request->input('reference'),
                'secure_code' => $request->input('secure_code')
            ]);

            return response()->json([
                'success' => false,
                'message' => "Paiement refusé : " . $e->getMessage()
            ], 422);
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
