<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\FeesTable;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemittanceService
{
    protected CommissionService $commissionService;
    protected $fraudService;
    /**
     * Injection du service de commission pour traiter les revenus d'émission.
     */
    public function __construct(CommissionService $commissionService,FraudCheckService $fraudCheckService)
    {
        $this->commissionService = $commissionService;
        $this->fraudService=$fraudCheckService;
    }

    /**
     * Calculer les frais et taxes pour un montant et un type de corridor donnés.
     */
    public function calculateFees(float $amount, string $type, int $sourceCountryId, int $destinationCountryId, string $currencyCode = 'XAF'): array
    {
        $currenciesWithoutDecimals = ['XAF', 'XOF', 'GNF', 'BIF', 'DJF', 'JPY'];
        $precision = in_array(strtoupper($currencyCode), $currenciesWithoutDecimals) ? 0 : 2;

        $feeRule = FeesTable::where('transaction_type', $type)
            ->where('source_country_id', $sourceCountryId)
            ->where('destination_country_id', $destinationCountryId)
            ->where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->first();

        if (!$feeRule) {
            $highestRule = FeesTable::where('transaction_type', $type)
                ->where('source_country_id', $sourceCountryId)
                ->where('destination_country_id', $destinationCountryId)
                ->where('is_active', true)
                ->orderBy('max_amount', 'desc')
                ->first();

            if ($highestRule && $amount > $highestRule->max_amount) {
                throw new Exception("Le montant maximum autorisé pour ce type de transfert est de " . number_format($highestRule->max_amount, $precision) . " " . $currencyCode);
            }

            throw new Exception("Aucun tarif configuré pour ce montant ou ce corridor international.");
        }

        $fixedFee = round((float) $feeRule->fixed_fee, $precision);
        $percentageRate = (float) $feeRule->percentage_fee / 100;
        $percentageFee = round($amount * $percentageRate, $precision);
        $totalFees = round($fixedFee + $percentageFee, $precision);

        $taxRate = (float) $feeRule->tax_percentage / 100;
        $taxes = round($totalFees * $taxRate, $precision);

        $totalAmountRequired = round($amount + $totalFees + $taxes, $precision);

        return [
            'base_amount'           => $amount,
            'fixed_fee'             => $fixedFee,
            'percentage_fee'        => $percentageFee,
            'total_fees'            => $totalFees, // Conservé pour la rétrocompatibilité ou l'affichage
            'taxes'                 => $taxes,
            'total_amount_required' => $totalAmountRequired,
            'currency'              => strtoupper($currencyCode),
            'precision_applied'     => $precision
        ];
    }

    /**
     * Initie un transfert d'argent (Remittance) au guichet émetteur.
     */
    public function initiateRemittance(User $initiator, Agency $sourceAgency, array $data): Transaction
    {
        if ($sourceAgency->status !== 'active' || !$sourceAgency->is_active) {
            throw new Exception("L'agence émettrice n'est pas autorisée à effectuer des opérations.");
        }

        $amount = (float) $data['amount'];

        // 💡 Récupération préalable du portefeuille pour connaître la devise exacte avant le calcul
        $agencyWallet = Wallet::where('owner_type', Agency::class)
            ->where('owner_id', $sourceAgency->id)
            ->where('type', 'main')
            ->where('is_active', true)
            ->first();

        if (!$agencyWallet) {
            throw new Exception("Le portefeuille principal de l'agence est introuvable ou inactif.");
        }

        // 💡 Passage de la devise de l'agence pour appliquer la précision d'arrondi appropriée
        $feesDetail = $this->calculateFees(
            $amount,
            'remittance',
            $sourceAgency->country_id,
            $data['destination_country_id'],
            $agencyWallet->currency
        );

        // ✅ Alignement des clés avec le tableau retourné par calculateFees
        $fees = (float) $feesDetail['total_fees'];
        $taxes = (float) $feesDetail['taxes'];
        $totalDebit = (float) $feesDetail['total_amount_required'];

        return DB::transaction(function () use ($initiator, $sourceAgency, $data, $amount, $fees, $taxes, $totalDebit, $feesDetail, $agencyWallet) {

            // Re-verrouillage pour exécution sécurisée (Pessimistic Lock)
            $agencyWallet = Wallet::where('id', $agencyWallet->id)->lockForUpdate()->first();

            if ((float) $agencyWallet->balance < $totalDebit) {
                throw new Exception("Le solde du portefeuille de l'agence est insuffisant pour couvrir le transfert ({$totalDebit} {$agencyWallet->currency} requis).");
            }

            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $agencyWallet->currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet) {
                throw new Exception("Le compte système de transit (Escrow) dans la devise cible ({$agencyWallet->currency}) est indisponible.");
            }

            $reference = 'TX-' . strtoupper(Str::random(4)) . '-' . date('ymdHis');
            $secureCode = $data['secure_code'] ?? $this->generateSecureCode();

            $transaction = Transaction::create([
                'uuid'                   => (string) Str::uuid(),
                'reference'              => $reference,
                'type'                   => 'remittance',
                'status'                 => 'completed',
                'amount'                 => $amount,
                'fees'                   => $fees,
                'taxes'                  => $taxes,
                'currency'               => $agencyWallet->currency,
                'sender_customer_id'     => $data['sender_customer_id'] ?? null,
                'sender_name'            => $data['sender_name'],
                'sender_phone'           => clean_phone($data['sender_phone']),
                'recipient_name'         => $data['recipient_name'],
                'recipient_phone'        => clean_phone($data['recipient_phone']),
                'recipient_email'        => $data['recipient_email'] ?? null,
                'secure_code'            => $secureCode,
                'source_agency_id'       => $sourceAgency->id,
                'sender_country_id'      => $sourceAgency->country_id,
                'sender_city_id'         => $data['sender_city_id'] ?? $sourceAgency->city_id,
                'recipient_country_id'   => $data['destination_country_id'],
                'recipient_city_id'      => $data['recipient_city_id'] ?? null,
                'initiator_id'           => $initiator->id,
                'completed_at'           => now(),
                'description'            => "Émission de mandat cash au guichet par: {$initiator->username}"
            ]);

            // B. COMPTABILITÉ : Débit global du Portefeuille Virtuel de l'Agence
            $agencyBalanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->decrement('balance', $totalDebit);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'debit',
                'amount'         => $totalDebit,
                'balance_before' => $agencyBalanceBefore,
                'balance_after'  => $agencyWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($agencyWallet->id, $totalDebit, 'debit', $agencyBalanceBefore, $agencyWallet->fresh()->balance)
            ]);

            // C. COMPTABILITÉ : Crédit du montant Principal net dans l'Escrow de transit
            $escrowBalanceBefore = (float) $escrowWallet->balance;
            $escrowWallet->increment('balance', $amount);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $escrowWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $amount,
                'balance_before' => $escrowBalanceBefore,
                'balance_after'  => $escrowWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($escrowWallet->id, $amount, 'credit', $escrowBalanceBefore, $escrowWallet->fresh()->balance)
            ]);

            // D. COMPTABILITÉ ANALYTIQUE : Distribution exclusive des commissions de service ($fees uniquement)
            if ($fees > 0) {
                $this->commissionService->distributeTransferCommission($transaction->id, $fees, $sourceAgency->id);
            }

            // E. FLUX TRÉSORERIE PHYSIQUE : Encaisse physique du guichet augmentée
            $sourceAgency->increment('current_balance', $totalDebit);

            $transaction->update([
                'metadata' => [
                    'fees_breakdown' => $feesDetail,
                    'device_context' => ['ip' => request()->ip(), 'user_agent' => request()->userAgent()]
                ]
            ]);

            return $transaction;
        });
    }

    /**
     * Valide et décaisse un retrait de mandat cash au guichet d'une agence réceptrice (Compensation).
     */
    public function payoutRemittance(User $initiator, Agency $destinationAgency, array $data): Transaction
    {
        if ($destinationAgency->status !== 'active' || !$destinationAgency->is_active) {
            throw new Exception("L'agence distributrice n'est pas autorisée à effectuer des paiements.");
        }

        return DB::transaction(function () use ($initiator, $destinationAgency, $data) {

            // 1. Verrouillage strict du mandat pour parer aux attaques de rejeu (Race Conditions)
            $transaction = Transaction::where('reference', trim($data['reference']))
                ->where('secure_code', trim($data['secure_code']))
                ->where('type', 'remittance')
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new Exception("Mandat introuvable. La référence ou le code secret de retrait est incorrect.");
            }

            if ($transaction->status !== 'completed') {
                throw new Exception($transaction->status === 'paid'
                    ? "Ce mandat a déjà été payé au bénéficiaire."
                    : "Ce mandat n'est pas disponible pour retrait (Statut: {$transaction->status})."
                );
            }

            $amountToPay = (float) $transaction->amount;

            // 2. CONTRÔLE DE L'ENCAISSE PHYSIQUE : L'agence dispose-t-elle des espèces au guichet ?
            if ((float) $destinationAgency->current_balance < $amountToPay) {
                throw new Exception("Opération impossible : L'encaisse physique dans le coffre du guichet est insuffisante pour effectuer ce décaissement.");
            }

            // 3. Chargement et verrouillage du compte de transit d'origine (on libère le principal bloqué)
            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $transaction->currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            // 4. Chargement et verrouillage du compte virtuel de l'agence distributrice pour compensation centrale
            $agencyWallet = Wallet::where('owner_type', Agency::class)
                ->where('owner_id', $destinationAgency->id)
                ->where('type', 'main')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet || !$agencyWallet) {
                throw new Exception("Erreur critique d'accès aux livres comptables ou comptes inactifs.");
            }

            // A. COMPTABILITÉ : Débit du Compte Transit Système (Libération des fonds)
            $escrowBalanceBefore = (float) $escrowWallet->balance;
            $escrowWallet->decrement('balance', $amountToPay);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $escrowWallet->id,
                'entry_type'     => 'debit',
                'amount'         => $amountToPay,
                'balance_before' => $escrowBalanceBefore,
                'balance_after'  => $escrowWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($escrowWallet->id, $amountToPay, 'debit', $escrowBalanceBefore, $escrowWallet->fresh()->balance)
            ]);

            // B. COMPTABILITÉ : Crédit du portefeuille virtuel de l'agence distributrice (Compensation centrale)
            $agencyBalanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->increment('balance', $amountToPay);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $amountToPay,
                'balance_before' => $agencyBalanceBefore,
                'balance_after'  => $agencyWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($agencyWallet->id, $amountToPay, 'credit', $agencyBalanceBefore, $agencyWallet->fresh()->balance)
            ]);

            // C. MISE À JOUR DU MANDAT PARENT AVEC COMPLIANCE KYC
            $transaction->update([
                'status'                 => 'paid',
                'destination_agency_id'  => $destinationAgency->id,
                'recipient_country_id'   => $destinationAgency->country_id,
                'recipient_city_id'      => $destinationAgency->city_id,
                'completed_at'           => now(),
                'description'            => $transaction->description . " | Payé à l'agence [" . $destinationAgency->name . "] par le caissier ID: " . $initiator->id,
                'metadata'               => array_merge(($transaction->metadata ?? []), [
                    'payout_compliance' => [
                        'recipient_id_type'   => $data['recipient_id_type'] ?? 'NON_SPECIFIE',
                        'recipient_id_number' => strtoupper(trim($data['recipient_id_number'] ?? 'NON_SPECIFIE')),
                        'recipient_id_expiry' => $data['recipient_id_expiry'] ?? null,
                        'payout_user_id'      => $initiator->id,
                        'paid_at'             => now()->toIso8601String()
                    ]
                ])
            ]);

            // D. FLUX TRÉSORERIE PHYSIQUE : Sortie physique du cash du tiroir-caisse
            $destinationAgency->decrement('current_balance', $amountToPay);

            // E. 💡 COMPTABILITÉ ANALYTIQUE : Rétribution immédiate de la commission de décaissement (Payout)
            // Le service extrait la quote-part due à l'agence payeuse depuis le pool de frais originaux stockés sur la transaction parent.
            if ((float) $transaction->fees > 0) {
                $this->commissionService->distributePayoutCommission($transaction->id, (float) $transaction->fees, $destinationAgency->id);
            }

            return $transaction;
        });
    }
    /**
     * Générer un code secret de retrait unique immuable à 12 chiffres.
     */
    private function generateSecureCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 3; $i++) {
                $code .= str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
            }
            $exists = Transaction::where('secure_code', $code)->exists();
        } while ($exists);

        return $code;
    }

    /**
     * Générer une signature de sécurité HMAC pour l'intégrité du Grand Livre comptable.
     */
    private function generateLedgerSignature(int $walletId, float $amount, string $type, float $balanceBefore, float $balanceAfter): string
    {
        return app(LedgerService::class)->generateSignature($walletId, $amount, $type, $balanceBefore, $balanceAfter);
    }
}

if (!function_exists('clean_phone')) {
    function clean_phone(?string $phone): string {
        return $phone ? preg_replace('/[^0-9]/', '', $phone) : '';
    }
}
