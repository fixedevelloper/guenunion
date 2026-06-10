<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteCashInRequest;
use App\Http\Requests\ExecuteCashOutRequest; // Import du nouveau Request
use App\Models\Customer;
use App\Models\Staff;
use App\Models\SystemAuditLog;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Wallet;
use App\Services\LedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CashInController extends Controller
{
    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Exécuter un rechargement de compte client au guichet (Cash-In)
     */
    public function execute(ExecuteCashInRequest $request): JsonResponse
    {
        // ... Votre code de Cash-In actuel reste inchangé
    }

    /**
     * Exécuter un retrait d'argent client au guichet (Cash-Out)
     */
    public function executeCashOut(ExecuteCashOutRequest $request): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $till = $staff?->currentTill;

        // Capture des données du Payload JSON (Structure identique demandée)
        $recipientId = $request->input('recipient_id'); // Le client initiant le retrait
        $amount      = (float) $request->input('amount');
        $note        = $request->input('reference_note') ?? "Retrait de fonds au guichet.";

        // 1. Contrôle d'accès de l'opérateur
        if (!$user->hasAnyRole(['cashier', 'manager', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => "Action refusée. Niveau d'accréditation insuffisant."
            ], 403);
        }

        // 2. Vérification de la caisse physique de destination
        if (!$till) {
            return response()->json([
                'success' => false,
                'message' => "Opération impossible : Aucun guichet physique actif ou ouvert n'est rattaché à votre session."
            ], 422);
        }

        try {
            // Recherche du client qui souhaite faire le retrait
            $customer = Customer::where('id', $recipientId)->firstOrFail();

            // 3. Protection territoriale
            if ($user->hasRole('cashier') && (int)$customer->country_id !== (int)$staff->country_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Action interdite : Ce client dépend d'une autre juridiction nationale."
                ], 403);
            }

            // Exécution de la transaction financière isolée
            $result = DB::transaction(function () use ($till, $customer, $amount, $user, $note) {

                // 4. Verrouillage du portefeuille du client (Source du Débit)
                $customerWallet = Wallet::where('owner_type', Customer::class)
                    ->where('owner_id', $customer->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$customerWallet || !$customerWallet->is_active) {
                    throw new Exception("Le portefeuille principal du client est introuvable ou suspendu.", 422);
                }

                // Barrière de sécurité : Le client a-t-il assez d'argent sur son compte ?
                if ((float)$customerWallet->balance < $amount) {
                    throw new Exception("Solde insuffisant sur le compte du client pour effectuer ce retrait.", 422);
                }

                // 5. Verrouillage de la caisse (Cible du Crédit virtuel)
                $cashierWallet = Wallet::where('owner_type', 'App\Models\Till')
                    ->where('owner_id', $till->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$cashierWallet || !$cashierWallet->is_active) {
                    throw new Exception("Votre coffre de guichet est introuvable, suspendu ou non initialisé.", 422);
                }

                // 6. INITIALISATION DE LA TRANSACTION DE RETRAIT
                $reference = 'TX-COT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $transaction = Transaction::create([
                    'uuid'         => (string) Str::uuid(),
                    'reference'    => $reference,
                    'type'         => 'cash_out', // Type Cash-Out
                    'status'       => 'initiated',
                    'amount'       => $amount,
                    'fees'         => 0,
                    'taxes'        => 0,
                    'currency'     => $customerWallet->currency,
                    'initiator_id' => $user->id,
                    'source_till_id' => $till->id,
                    'description'  => $note,
                    'metadata'     => [
                        'channel'            => 'guichet',
                        'till_id'            => $till->id,
                        'customer_id'        => $customer->id,
                        'customer_reference' => $customer->reference
                    ]
                ]);

                $customerBalanceBefore = (float) $customerWallet->balance;
                $cashierBalanceBefore  = (float) $cashierWallet->balance;

                // 7. MOUVEMENT DES BALANCES (Inversion par rapport au Cash-In)
                $customerWallet->decrement('balance', $amount); // Le client perd l'argent numérique
                $cashierWallet->increment('balance', $amount); // La caisse encaisse l'argent numérique (et donne le cash physique)

                // 8. DOUBLE ÉCRITURE COMPTABLE DANS LE GRAND LIVRE (LEDGER)

                // Débit Client (Retrait de ses fonds)
                $customerSignature = $this->ledgerService->generateSignature(
                    $customerWallet->id, $amount, 'debit', $customerBalanceBefore, (float) $customerWallet->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $customerWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $amount,
                    'balance_before' => $customerBalanceBefore,
                    'balance_after'  => $customerWallet->balance,
                    'row_signature'  => $customerSignature
                ]);

                // Crédit Caisse (Entrée de valeur numérique dans le guichet)
                $cashierSignature = $this->ledgerService->generateSignature(
                    $cashierWallet->id, $amount, 'credit', $cashierBalanceBefore, (float) $cashierWallet->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $cashierWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $amount,
                    'balance_before' => $cashierBalanceBefore,
                    'balance_after'  => $cashierWallet->balance,
                    'row_signature'  => $cashierSignature
                ]);

                // 9. CLÔTURE DE LA TRANSACTION
                $transaction->update([
                    'status'       => 'completed',
                    'completed_at' => now()
                ]);

                // 10. SYSTEM AUDIT LOG
                SystemAuditLog::create([
                    'uuid'       => (string) Str::uuid(),
                    'user_id'    => $user->id,
                    'event_type' => 'CASH.OUT_EXECUTE',
                    'severity'   => 'info',
                    'message'    => "Cash-Out manuel effectué avec succès pour le client ID #{$customer->id}. Espèces remises.",
                    'payload'    => [
                        'transaction_reference' => $reference,
                        'customer_id'           => $customer->id,
                        'amount'                => $amount
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return [
                    'transaction' => $transaction,
                    'new_balance' => (float) $customerWallet->balance
                ];
            });

            return response()->json([
                'success' => true,
                'message' => "Le retrait de {$amount} a été validé. Vous pouvez remettre les espèces au client.",
                'data'    => [
                    'reference'    => $result['transaction']->reference,
                    'amount'       => $amount,
                    'new_balance'  => $result['new_balance']
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Échec du Cash-Out au guichet", [
                'recipient_id' => $recipientId,
                'amount'       => $amount,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => (int)$e->getCode() === 422 ? $e->getMessage() : "Une erreur comptable interne bloque l'exécution du retrait."
            ], 422);
        }
    }
}
