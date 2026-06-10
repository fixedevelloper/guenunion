<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\CommissionService;
use App\Services\FraudCheckService;
use App\Models\Customer;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\FeesTable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    protected FraudCheckService $fraudService;
    protected CommissionService $commissionService;

    public function __construct(FraudCheckService $fraudService, CommissionService $commissionService)
    {
        $this->fraudService = $fraudService;
        $this->commissionService = $commissionService;
    }

    /**
     * Extraction de la logique pure de calcul pour la rendre réutilisable en interne sans Request.
     */
    private function getFeesStructure(float $amount): array
    {
        $rule = FeesTable::where('transaction_type', 'transfer')
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            return ['fee' => 0.0, 'tax' => 0.0, 'total' => $amount];
        }

        $fee = (float) $rule->fixed_fee + ($amount * ((float) $rule->percentage_fee / 100));
        $tax = ($amount + $fee) * ((float) $rule->tax_percentage / 100);

        return [
            'fee'   => $fee,
            'tax'   => $tax,
            'total' => $amount + $fee + $tax
        ];
    }

    /**
     * Calcule les frais et taxes applicables pour un montant donné (Endpoint API).
     */
    public function calculateFees(Request $request): JsonResponse
    {
        // Supporte à la fois les paramètres d'URL (query) ou de corps (POST/JSON)
        $amount = (float) $request->input('amount', 0);

        $fees = $this->getFeesStructure($amount);

        return response()->json($fees);
    }

    /**
     * Exécute le transfert de compte à compte avec contrôles Ledger, Fraude et Ventilation.
     * @param Request $request
     * @return JsonResponse
     */
    public function execute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sender_id'    => 'required|integer|exists:customers,id',
            'recipient_id' => 'required|integer|exists:customers,id|different:sender_id',
            'amount'       => 'required|numeric|gt:0',
        ]);

        $reference = 'TRX-' . strtoupper(Str::random(12));
        $initiatorId = auth()->id();
        $user = auth()->user(); // ⚙️ CORRECTION : Instanciation de $user pour éviter le bug d'indisponibilité

        // 1. Extraction du profil staff et de son GUICHET ACTIF (currentTill)
        $staff = Staff::with(['agency', 'currentTill'])->where('user_id', $user->id)->first();
        $till = $staff?->currentTill;
    $agencyId = $staff?->agency_id; // Évite l'utilisation d'une méthode externe magique getAgencyId()

    Log::info("[LEDGER-TRANSFER] [INIT] Initialisation du transfert régulé {$reference}", [
        'initiator_id' => $initiatorId,
        'amount'       => $data['amount']
    ]);

    if (!$agencyId) {
        return response()->json(['success' => false, 'message' => 'Opération non autorisée. Votre compte staff n\'est rattaché à aucune agence.'], 403);
    }

    // Sécurité : Vérification que le guichet de l'opérateur est actif et ouvert
    if (!$till || !$till->is_active || $till->status !== 'open') {
        return response()->json(['success' => false, 'message' => 'Opération impossible : Votre tiroir-caisse doit être ouvert pour initier ce transfert.'], 403);
    }

    // 2. CONTRÔLE ANTI-FRAUDE EXTERNE
    $fraudAnalysis = $this->fraudService->analyze('transfer', $data['sender_id'], $data['amount']);

    if ($fraudAnalysis['risk_score'] >= 80) {
        Log::warning("[FRAUDE] [BLOCAGE] Transfert stoppé automatiquement par le service de sécurité {$reference}", $fraudAnalysis);
        return response()->json([
            'success' => false,
            'message' => 'Opération refusée par le protocole de conformité : ' . $fraudAnalysis['reason']
        ], 403);
    }

    $isFlagged = $fraudAnalysis['is_flagged'] ?? false;
    $transactionStatus = $isFlagged ? 'processing' : 'completed';

    try {
        // Exécution de l'isolation ACID globale
        return DB::transaction(function () use ($till, $agencyId, $data, $reference, $initiatorId, $transactionStatus, $isFlagged, $fraudAnalysis) {

            // 3. Calcul de la tarification et des taxes d'envoi
            $feesData = $this->getFeesStructure((float) $data['amount']);
            $fee = $feesData['fee'];
            $tax = $feesData['tax'];
            $totalFees = $fee + $tax;
            $totalDeduction = (float) $data['amount'] + $totalFees;

            // 4. Verrouillage strict des portefeuilles clients (Pessimistic Locking 'FOR UPDATE')
            $sWallet = Wallet::where('owner_id', $data['sender_id'])->where('owner_type', Customer::class)->where('type', 'main')->lockForUpdate()->firstOrFail();
            $rWallet = Wallet::where('owner_id', $data['recipient_id'])->where('owner_type', Customer::class)->where('type', 'main')->lockForUpdate()->firstOrFail();

            // ⚙️ CORRECTION : On lève une Exception pour déclencher le ROLLBACK au lieu d'un return direct inefficace dans le closure
            if ((float) $sWallet->balance < $totalDeduction) {
                throw new Exception("Provision insuffisante sur le portefeuille émetteur.", 422);
            }

            $sender = Customer::with('user')->findOrFail($data['sender_id']);
            $recipient = Customer::with('user')->findOrFail($data['recipient_id']);

            // 5. CRÉATION DU MANDAT EN BASE DE DONNÉES
            $transaction = Transaction::create([
                    'uuid'                  => Str::uuid(),
                    'reference'             => $reference,
                    'type'                  => 'transfer',
                    'status'                => $transactionStatus,
                    'amount'                => $data['amount'],
                    'fees'                  => $fee,
                    'taxes'                 => $tax,
                    'currency'              => 'XAF',
                    'sender_customer_id'    => $sender->id,
                    'secure_code'           => strtoupper(Str::random(8)),
                    'sender_name'           => $sender->user?->full_name ?? 'Client Expéditeur',
                'sender_phone'          => $sender->user?->phone_number,
                'recipient_name'        => $recipient->user?->full_name ?? 'Client Destinataire',
                'recipient_phone'       => $recipient->user?->phone_number,
                'source_agency_id'      => $agencyId,
                'source_till_id'        => $till->id, // Tracé au guichet physique d'émission
                'initiator_id'          => $initiatorId,
                'completed_at'          => $isFlagged ? null : now()
            ]);

            $this->fraudService->logCheck($transaction->id, $fraudAnalysis);

            // Si la transaction n'est pas suspecte (flagged), on exécute la double écriture immédiatement
            if (!$isFlagged) {

                // --- Écriture A : Débit du compte Expéditeur (Principal + Frais) ---
                $sBalanceBefore = (float) $sWallet->balance;
                $sWallet->decrement('balance', $totalDeduction);

                TransactionEntry::create([
                    'uuid'           => Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $sWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $totalDeduction,
                    'balance_before' => $sBalanceBefore,
                    'balance_after'  => $sWallet->fresh()->balance,
                    'row_signature'  => $this->generateRowSignature($sWallet->id, $totalDeduction, 'debit')
                ]);

                // --- Écriture B : Crédit du compte Destinataire (Principal net) ---
                $rBalanceBefore = (float) $rWallet->balance;
                $rWallet->increment('balance', $data['amount']);

                TransactionEntry::create([
                    'uuid'           => Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $rWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $data['amount'],
                    'balance_before' => $rBalanceBefore,
                    'balance_after'  => $rWallet->fresh()->balance,
                    'row_signature'  => $this->generateRowSignature($rWallet->id, $data['amount'], 'credit')
                ]);

                // --- Écriture C : Ventilation analytique immédiate des commissions de l'agence ---
                if ($fee > 0) {
                    $this->commissionService->distributeTransferCommission($transaction->id, $fee, $agencyId);
                }
            }

            Log::info("[LEDGER-TRANSFER] [SUCCESS] Opération comptabilisée sous la référence {$reference}. Statut final : {$transactionStatus}");

            return response()->json([
                'success'   => true,
                'reference' => $reference,
                'status'    => $transactionStatus,
                'message'   => $isFlagged
                    ? 'Le transfert a été suspendu par mesure de sécurité et mis en attente de validation.'
                    : 'Transfert enregistré et liquidé avec succès dans le grand livre.'
            ], 200);
        });

    } catch (Exception $e) {
        Log::critical("[LEDGER-TRANSFER] [FATAL] Rollback opéré. Échec d'écriture double-partie pour {$reference}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Si l'erreur provient d'un manque de provision (code 422), on transmet le message propre
        $code = $e->getCode() === 422 ? 422 : 500;
        $message = $code === 422 ? $e->getMessage() : 'Une anomalie technique interne a forcé l\'annulation comptable du virement.';

        return response()->json([
            'success' => false,
            'message' => $message
        ], $code);
    }
}

    private function generateRowSignature(int $walletId, float $amount, string $type): string
    {
        return hash_hmac('sha256', "{$walletId}-{$amount}-{$type}-" . config('app.key'), 'secret_ledger_key');
    }

    private function getAgencyId(): ?int
    {
        $staff = Staff::where('user_id', auth()->id())->first();
        return $staff ? $staff->agency_id : null;
    }
}
