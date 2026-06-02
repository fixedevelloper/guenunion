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
}
