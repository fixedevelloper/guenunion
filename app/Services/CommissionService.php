<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Commission;
use App\Models\TransactionEntry;
use App\Models\Agency;
use Illuminate\Support\Str;

class CommissionService
{
    /**
     * Ventile et distribue les commissions entre la plateforme et l'agence émettrice.
     * @param int $transactionId
     * @param float $totalFees
     * @param int $sourceAgencyId
     */
    public function distributeTransferCommission(int $transactionId, float $totalFees, int $sourceAgencyId): void
    {
        if ($totalFees <= 0) return;

        // Définition des clés de répartition (Règles d'affaires modifiables)
        $agencySharePercent = 40.00; // 40% pour l'agence
        $companySharePercent = 60.00; // 60% pour le siège social

        $agencyAmount = ($totalFees * $agencySharePercent) / 100;
        $companyAmount = ($totalFees * $companySharePercent) / 100;

        // 1. Distribution à l'Agence Émettrice (Cash-in Agency)
        $agencyWallet = Wallet::where('owner_id', $sourceAgencyId)
            ->where('owner_type', Agency::class)
            ->where('type', 'commission') // Votre schéma possède un enum type 'commission'
            ->lockForUpdate()
            ->first();

        if ($agencyWallet) {
            $balanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->increment('balance', $agencyAmount);

            // Enregistrement dans la table analytique des commissions
            Commission::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $agencyWallet->id,
                'amount'         => $agencyAmount,
                'percentage'     => $agencySharePercent,
                'description'    => "Part de commission Agence Émettrice ID: {$sourceAgencyId}"
            ]);

            // Doublure dans le Grand Livre (Ledger Entry) pour la traçabilité des comptes
            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $agencyAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $agencyWallet->fresh()->balance
            ]);
        }

        // 2. Distribution au Système Central (Compte Commission Entreprise Général)
        $companyWallet = Wallet::where('type', 'commission')
            ->whereNull('owner_id') // Le compte central n'appartient à aucune agence spécifique
            ->lockForUpdate()
            ->first();

        if ($companyWallet) {
            $balanceBefore = (float) $companyWallet->balance;
            $companyWallet->increment('balance', $companyAmount);

            Commission::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $companyWallet->id,
                'amount'         => $companyAmount,
                'percentage'     => $companySharePercent,
                'description'    => "Part de commission Système Central (Réseau)"
            ]);

            TransactionEntry::create([
                'uuid'           => Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $companyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $companyAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $companyWallet->fresh()->balance
            ]);
        }
    }

    /**
     * Ventile et distribue les commissions lors d'un retrait (Payout) pour l'agence distributrice.
     * *
     * @param int $transactionId
     * @param float $totalFees
     * @param int $destinationAgencyId
     */
    public function distributePayoutCommission(int $transactionId, float $totalFees, int $destinationAgencyId): void
    {
        if ($totalFees <= 0) return;

        // Définition des clés de répartition pour le Payout (Ajustable selon vos règles d'affaires)
        $agencyPayoutSharePercent = 30.00;  // 30% des frais globaux vont à l'agence qui donne le cash
        $companyPayoutSharePercent = 70.00; // 70% pour le siège social / réseau

        $agencyAmount = ($totalFees * $agencyPayoutSharePercent) / 100;
        $companyAmount = ($totalFees * $companyPayoutSharePercent) / 100;

        // 1. Distribution à l'Agence Distributrice / Payeuse (Cash-out Agency)
        $agencyWallet = Wallet::where('owner_id', $destinationAgencyId)
            ->where('owner_type', Agency::class)
            ->where('type', 'commission')
            ->lockForUpdate()
            ->first();

        if ($agencyWallet) {
            $balanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->increment('balance', $agencyAmount);

            // Enregistrement dans la table analytique des commissions
            Commission::create([
                'uuid'           => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $agencyWallet->id,
                'amount'         => $agencyAmount,
                'percentage'     => $agencyPayoutSharePercent,
                'description'    => "Part de commission Agence Distributrice (Payout) ID: {$destinationAgencyId}"
            ]);

            // Doublure dans le Grand Livre (Ledger Entry) pour l'audit et la balance comptable
            TransactionEntry::create([
                'uuid'           => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $agencyAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $agencyWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($agencyWallet->id, $agencyAmount, 'credit', $balanceBefore, $agencyWallet->fresh()->balance) // Si requis
            ]);
        }

        // 2. Distribution du reliquat au Système Central (Compte Commission Entreprise Général)
        $companyWallet = Wallet::where('type', 'commission')
            ->whereNull('owner_id')
            ->lockForUpdate()
            ->first();

        if ($companyWallet) {
            $balanceBefore = (float) $companyWallet->balance;
            $companyWallet->increment('balance', $companyAmount);

            // Enregistrement de la part entreprise
            Commission::create([
                'uuid'           => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $companyWallet->id,
                'amount'         => $companyAmount,
                'percentage'     => $companyPayoutSharePercent,
                'description'    => "Part de commission Système Central (Payout Réseau)"
            ]);

            // Écriture de crédit au Grand Livre de la compagnie
            TransactionEntry::create([
                'uuid'           => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'wallet_id'      => $companyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $companyAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $companyWallet->fresh()->balance,
                'row_signature'  => $this->generateLedgerSignature($companyWallet->id, $companyAmount, 'credit', $balanceBefore, $companyWallet->fresh()->balance) // Si requis
            ]);
        }
    }
    /**
     * Générer une signature de sécurité HMAC pour l'intégrité du Grand Livre comptable.
     */
    public function generateLedgerSignature(int $walletId, float $amount, string $type, float $balanceBefore, float $balanceAfter): string
    {
        return app(LedgerService::class)->generateSignature($walletId, $amount, $type, $balanceBefore, $balanceAfter);
    }
}
