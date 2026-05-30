<?php

namespace App\Services;

use App\Models\TransactionEntry;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerService
{
    /**
     * Clé secrète de l'application servant au salage des signatures.
     */
    protected string $secretKey;

    public function __construct()
    {
        // Utilise la clé d'application ou une clé dédiée définie dans votre fichier .env
        $this->secretKey = config('app.key', 'FintechSecretSovereignKeyCMR2026');
    }

    /**
     * Générer la signature HMAC immuable pour une ligne du Grand Livre.
     * Cette signature lie mathématiquement le portefeuille, le montant, le type et les soldes.
     *
     * @param int $walletId
     * @param float $amount
     * @param string $entryType ('debit' ou 'credit')
     * @param float $balanceBefore
     * @param float $balanceAfter
     * @return string
     */
    public function generateSignature(
        int $walletId,
        float $amount,
        string $entryType,
        float $balanceBefore,
        float $balanceAfter
    ): string {
        // Construction d'une chaîne normalisée stricte (Payload)
        $payload = sprintf(
            "wallet:%d|amount:%.2f|type:%s|before:%.2f|after:%.2f",
            $walletId,
            $amount,
            $entryType,
            $balanceBefore,
            $balanceAfter
        );

        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    /**
     * Vérifier l'intégrité d'une entrée spécifique du Grand Livre.
     *
     * @param TransactionEntry $entry
     * @return bool
     */
    public function verifyEntryIntegrity(TransactionEntry $entry): bool
    {
        $calculatedSignature = $this->generateSignature(
            $entry->wallet_id,
            (float) $entry->amount,
            $entry->entry_type,
            (float) $entry->balance_before,
            (float) $entry->balance_after
        );

        return hash_equals($entry->row_signature, $calculatedSignature);
    }

    /**
     * Audit complet du Grand Livre (Ledger) pour une période donnée ou un portefeuille précis.
     * Ce script réconcilie tous les mouvements et lève une alerte en cas de rupture d'intégrité.
     *
     * @param int|null $walletId Optionnel: Filtrer l'audit sur un seul portefeuille (ex: Coffre d'une agence)
     * @return array Résultat détaillé de l'audit de conformité
     */
    public function auditGlobalLedger(?int $walletId = null): array
    {
        $query = TransactionEntry::query();

        if ($walletId) {
            $query->where('wallet_id', $walletId);
        }

        // Chargement par paquets (Chunk) pour éviter l'explosion de la mémoire RAM sur des millions de lignes
        $totalChecked = 0;
        $corruptedEntries = [];

        $query->orderBy('id', 'asc')->chunk(1000, function ($entries) use (&$totalChecked, &$corruptedEntries) {
            foreach ($entries as $entry) {
                $totalChecked++;

                if (!$this->verifyEntryIntegrity($entry)) {
                    $corruptedEntries[] = [
                        'entry_id' => $entry->id,
                        'uuid' => $entry->uuid,
                        'transaction_id' => $entry->transaction_id,
                        'wallet_id' => $entry->wallet_id,
                        'recorded_signature' => $entry->row_signature
                    ];

                    // Alerte immédiate dans les fichiers de logs système sécurisés (pour Slack/Grafana/SIEM)
                    Log::critical("CRITICAL: Altération détectée sur le Grand Livre Comptable !", [
                        'entry_id' => $entry->id,
                        'uuid' => $entry->uuid,
                        'wallet_id' => $entry->wallet_id
                    ]);
                }
            }
        });

        $isClean = count($corruptedEntries) === 0;

        return [
            'status' => $isClean ? 'SUCCESS' : 'CORRUPTED',
            'is_integrated' => $isClean,
            'total_records_checked' => $totalChecked,
            'total_corrupted_count' => count($corruptedEntries),
            'corrupted_records' => $corruptedEntries,
            'audited_at' => now()->toIso8601String()
        ];
    }

    /**
     * Réconcilier mathématiquement le solde actuel d'un portefeuille (Wallet)
     * avec l'intégralité de son historique comptable dans la table transaction_entries.
     * Principe : Solde Théorique = Somme(Crédits) - Somme(Débits)
     *
     * @param string $walletUuid
     * @return array
     * @throws Exception
     */
    public function reconcileWalletHistory(string $walletUuid): array
    {
        $wallet = Wallet::where('uuid', $walletUuid)->first();

        if (!$wallet) {
            throw new Exception("Portefeuille introuvable pour la réconciliation.");
        }

        // Calcul de la somme des crédits et débits de manière atomique dans la base de données
        $totals = TransactionEntry::where('wallet_id', $wallet->id)
            ->select(
                DB::raw("SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as total_credit"),
                DB::raw("SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as total_debit")
            )
            ->first();

        $totalCredit = (float) $totals->total_credit;
        $totalDebit = (float) $totals->total_debit;

        // Calcul du solde logique reconstruit depuis l'origine des temps
        $reconstructedBalance = $totalCredit - $totalDebit;
        $actualWalletBalance = (float) $wallet->balance;

        // Calcul de l'écart (Doit être strictement égal à 0.00)
        $discrepancy = round($actualWalletBalance - $reconstructedBalance, 2);

        return [
            'wallet_number' => $wallet->wallet_number,
            'owner_type' => $wallet->owner_type,
            'owner_id' => $wallet->owner_id,
            'currency' => $wallet->currency,
            'actual_balance' => $actualWalletBalance,
            'reconstructed_balance' => $reconstructedBalance,
            'total_credits_received' => $totalCredit,
            'total_debits_emitted' => $totalDebit,
            'discrepancy' => $discrepancy,
            'is_reconciled' => ($discrepancy === 0.00)
        ];
    }
}
