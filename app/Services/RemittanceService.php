<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\FeesTable;
use App\Models\Till;
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
     * @param User $initiator
     * @param Till $sourceTill
     * @param array $data
     * @return Transaction
     * @throws Exception
     */
    public function initiateRemittance(User $initiator, Till $sourceTill, array $data): Transaction
    {
        // 1. Contrôles de sécurité stricts sur le guichet (Till)
        if ($sourceTill->status !== 'open' || !$sourceTill->is_active) {
            throw new Exception("Le guichet émetteur n'est pas ouvert ou est suspendu.",422);
        }

        // Récupération sécurisée de l'agence parente pour les configurations pays/frais
        $sourceAgency = $sourceTill->agency;
        if (!$sourceAgency || $sourceAgency->status !== 'active' || !$sourceAgency->is_active) {
            throw new Exception("L'agence de rattachement du guichet est inactive ou indisponible.",422);
        }

        $amount = (float) $data['amount'];

        // 2. Récupération préalable du portefeuille de la caisse (Till) pour connaître la devise
        $tillWallet = Wallet::where('owner_type', Till::class)
            ->where('owner_id', $sourceTill->id)
            ->where('type', 'main')
            ->where('is_active', true)
            ->first();

        if (!$tillWallet) {
            throw new Exception("Le portefeuille principal du guichet est introuvable ou inactif.",422);
        }

        // 3. Calcul des frais basé sur le pays de l'agence parente
        $feesDetail = $this->calculateFees(
            $amount,
            'remittance',
            $sourceAgency->country_id,
            $data['destination_country_id'],
            $tillWallet->currency
        );

        $fees = (float) $feesDetail['total_fees'];
        $taxes = (float) $feesDetail['taxes'];
        $totalDebit = (float) $feesDetail['total_amount_required'];

        // 4. EXÉCUTION DE LA TRANSACTION COMPTABLE ACID
        return DB::transaction(function () use ($initiator, $sourceAgency, $sourceTill, $data, $amount, $fees, $taxes, $totalDebit, $feesDetail, $tillWallet) {

            // Pessimistic Lock sur le Wallet du Guichet (Anti Race-Condition)
            $tillWallet = Wallet::where('id', $tillWallet->id)->lockForUpdate()->first();

            if ((float) $tillWallet->balance < $totalDebit) {
                throw new Exception("Le solde du guichet est insuffisant pour couvrir le transfert ({$totalDebit} {$tillWallet->currency} requis).",422);
            }

            // Lock sur le compte de transit système (Escrow)
            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $tillWallet->currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet) {
                throw new Exception("Le compte système de transit (Escrow) dans la devise cible ({$tillWallet->currency}) est indisponible.",422);
            }

            $reference = 'TX-' . strtoupper(Str::random(4)) . '-' . date('ymdHis');
            $secureCode = $data['secure_code'] ?? $this->generateSecureCode();

            // Création du registre de la transaction globale
            $transaction = Transaction::create([
                'uuid'                   => (string) Str::uuid(),
                'reference'              => $reference,
                'type'                   => 'remittance',
                'status'                 => 'completed',
                'amount'                 => $amount,
                'fees'                   => $fees,
                'taxes'                  => $taxes,
                'currency'               => $tillWallet->currency,
                'sender_customer_id'     => $data['sender_customer_id'] ?? null,
                'sender_name'            => $data['sender_name'],
                'sender_phone'           => clean_phone($data['sender_phone']),
                'recipient_name'         => $data['recipient_name'],
                'recipient_phone'        => clean_phone($data['recipient_phone']),
                'recipient_email'        => $data['recipient_email'] ?? null,
                'secure_code'            => $secureCode,
                'source_till_id'         => $sourceTill->id, // Nouvelle colonne optionnelle si vous tracez le Till directement
                'source_agency_id'       => $sourceAgency->id, // Conservé pour la consolidation par agence
                'sender_country_id'      => $sourceAgency->country_id,
                'sender_city_id'         => $data['sender_city_id'] ?? $sourceAgency->city_id,
                'recipient_country_id'   => $data['destination_country_id'],
                'recipient_city_id'      => $data['recipient_city_id'] ?? null,
                'initiator_id'           => $initiator->id,
                'completed_at'           => now(),
                'description'            => "Émission de mandat cash au guichet [{$sourceTill->code}] par: {$initiator->username}"
            ]);

            // B. COMPTABILITÉ : Débit global du Portefeuille Virtuel du Guichet (Till)
            $tillBalanceBefore = (float) $tillWallet->balance;
            $tillWallet->decrement('balance', $totalDebit);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $tillWallet->id,
                'entry_type'     => 'debit',
                'amount'         => $totalDebit,
                'balance_before' => $tillBalanceBefore,
                'balance_after'  => $tillWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($tillWallet->id, $totalDebit, 'debit', $tillBalanceBefore, $tillWallet->fresh()->balance)
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

            // D. COMPTABILITÉ ANALYTIQUE : Distribution des commissions au niveau de l'agence parente
            if ($fees > 0) {
                $this->commissionService->distributeTransferCommission($transaction->id, $fees, $sourceAgency->id);
            }

            // E. FLUX TRÉSORERIE PHYSIQUE : L'encours de la caisse physique (Till) augmente (le client a donné les espèces au guichetier)
            $sourceTill->increment('current_balance', $totalDebit);

            // Optionnel : Si vous tenez aussi à jour l'encours global de l'agence en temps réel :
             $sourceAgency->increment('current_balance', $totalDebit);

            $transaction->update([
                'metadata' => [
                    'fees_breakdown' => $feesDetail,
                    'till_context'   => ['code' => $sourceTill->code, 'name' => $sourceTill->name],
                    'device_context' => ['ip' => request()->ip(), 'user_agent' => request()->userAgent()]
                ]
            ]);

            return $transaction;
        });
    }

    /**
     * Valide et décaisse un retrait de mandat cash au guichet d'une agence réceptrice (Compensation).
     * @param User $initiator
     * @param Agency $destinationAgency
     * @param array $data
     * @return Transaction
     * @throws Exception
     */
    public function payoutRemittance(User $initiator, Till $destinationTill, array $data): Transaction
    {
        // 1. Contrôles de sécurité stricts sur le guichet (Till) de destination
        if ($destinationTill->status !== 'open' || !$destinationTill->is_active) {
            throw new Exception("Le guichet distributeur n'est pas ouvert ou est suspendu.",422);
        }

        // Récupération et validation de l'agence parente pour la compliance et les commissions
        $destinationAgency = $destinationTill->agency;
        if (!$destinationAgency || $destinationAgency->status !== 'active' || !$destinationAgency->is_active) {
            throw new Exception("L'agence de rattachement de ce guichet n'est pas autorisée à opérer.",422);
        }

        return DB::transaction(function () use ($initiator, $destinationAgency, $destinationTill, $data) {

            // 2. Verrouillage strict du mandat pour parer aux attaques de rejeu (Race Conditions)
            $transaction = Transaction::where('reference', trim($data['reference']))
                ->where('secure_code', trim($data['secure_code']))
                ->where('type', 'remittance')
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new Exception("Mandat introuvable. La référence ou le code secret de retrait est incorrect.",422);
            }

            // Vérification du statut (Inversion logique : si 'completed' = prêt, si 'paid' = déjà décaissé)
            if ($transaction->status !== 'completed') {
                throw new Exception($transaction->status === 'paid'
                    ? "Ce mandat a déjà été payé au bénéficiaire."
                    : "Ce mandat n'est pas disponible pour retrait (Statut actuel: {$transaction->status})."
                );
            }

            $amountToPay = (float) $transaction->amount;

            // 3. CONTRÔLE DE L'ENCAISSE PHYSIQUE : Le tiroir-caisse dispose-t-elle des espèces physiques ?
            if ((float) $destinationTill->current_balance < $amountToPay) {
                throw new Exception("Opération impossible : L'encaisse physique dans le tiroir-caisse de ce guichet est insuffisante pour effectuer ce décaissement.",422);
            }

            // 4. Chargement et verrouillage du compte de transit d'origine (Escrow)
            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $transaction->currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            // 5. ⚠️ MODIFICATION : Chargement et verrouillage du compte virtuel du GUICHET (Till)
            $tillWallet = Wallet::where('owner_type', Till::class)
                ->where('owner_id', $destinationTill->id)
                ->where('type', 'main')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet || !$tillWallet) {
                throw new Exception("Erreur critique d'accès aux livres comptables ou comptes de caisse inactifs.");
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

            // B. ⚠️ COMPTABILITÉ : Crédit du portefeuille virtuel du GUICHET (Compensation centrale)
            $tillBalanceBefore = (float) $tillWallet->balance;
            $tillWallet->increment('balance', $amountToPay);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transaction->id,
                'wallet_id'      => $tillWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $amountToPay,
                'balance_before' => $tillBalanceBefore,
                'balance_after'  => $tillWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($tillWallet->id, $amountToPay, 'credit', $tillBalanceBefore, $tillWallet->fresh()->balance)
            ]);

            // C. MISE À JOUR DU MANDAT PARENT AVEC COMPLIANCE KYC & TRACEABILITÉ GUICHET
            $transaction->update([
                'status'                 => 'paid',
                'destination_till_id'    => $destinationTill->id,   // Optionnel: si colonne ajoutée en base
                'destination_agency_id'  => $destinationAgency->id, // Conservé pour la consolidation par agence
                'recipient_country_id'   => $destinationAgency->country_id,
                'recipient_city_id'      => $destinationAgency->city_id,
                'completed_at'           => now(),
                'description'            => $transaction->description . " | Payé au guichet [" . $destinationTill->code . "] de l'agence [" . $destinationAgency->name . "] par le caissier ID: " . $initiator->id,
                'metadata'               => array_merge(($transaction->metadata ?? []), [
                    'payout_compliance' => [
                        'recipient_id_type'   => $data['recipient_id_type'] ?? 'NON_SPECIFIE',
                        'recipient_id_number' => strtoupper(trim($data['recipient_id_number'] ?? 'NON_SPECIFIE')),
                        'recipient_id_expiry' => $data['recipient_id_expiry'] ?? null,
                        'payout_till_code'    => $destinationTill->code,
                        'payout_user_id'      => $initiator->id,
                        'paid_at'             => now()->toIso8601String()
                    ]
                ])
            ]);

            // D. ⚠️ FLUX TRÉSORERIE PHYSIQUE : Sortie physique du cash du tiroir-caisse (Till)
            $destinationTill->decrement('current_balance', $amountToPay);

            // E. COMPTABILITÉ ANALYTIQUE : Rétribution immédiate de la commission de décaissement à l'agence parente
            if ((float) $transaction->fees > 0) {
                $this->commissionService->distributePayoutCommission($transaction->id, (float) $transaction->fees, $destinationAgency->id);
            }

            return $transaction;
        });
    }
    /**
     * Générer un code secret de retrait unique immuable à 12 chiffres.
     */
    public function generateSecureCode(): string
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
    public function generateLedgerSignature(int $walletId, float $amount, string $type, float $balanceBefore, float $balanceAfter): string
    {
        return app(LedgerService::class)->generateSignature($walletId, $amount, $type, $balanceBefore, $balanceAfter);
    }
}
