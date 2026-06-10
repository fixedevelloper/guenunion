<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class AdjustEscrowBalance extends Command
{
    /**
     * La signature de la commande avec la devise et l'action.
     */
    protected $signature = 'wallet:adjust-escrow
                            {currency : La devise du compte escrow à ajuster (ex: XAF, XOF, EUR, USD)}
                            {action : L\'action à mener ("credit" pour ajouter, "debit" pour retirer)}
                            {amount : Le montant absolu de l\'ajustement}
                            {--reason= : Le motif de cet ajustement (optionnel)}';

    protected $description = 'Augmente (credit) ou diminue (debit) le solde d\'un compte Séquestre Système (Escrow) pour une devise spécifique';

    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        parent::__construct();
        $this->ledgerService = $ledgerService;
    }

    public function handle()
    {
        $currency = strtoupper($this->argument('currency'));
        $action   = strtolower($this->argument('action'));
        $amount   = (float) $this->argument('amount');
        $reason   = $this->option('reason') ?? "Ajustement comptable manuel de la caisse centrale Escrow.";

        // 1. Validations de sécurité de base
        if (!in_array($action, ['credit', 'debit'])) {
            $this->error("❌ L'action doit être 'credit' (augmenter) ou 'debit' (diminuer).");
            return Command::FAILURE;
        }

        if ($amount <= 0) {
            $this->error("❌ Le montant doit être strictement supérieur à 0.");
            return Command::FAILURE;
        }

        try {
            DB::transaction(function () use ($currency, $action, $amount, $reason) {

                // 2. Verrouillage du portefeuille Escrow Système par sa devise et son type
                $wallet = Wallet::where('type', 'escrow')
                    ->where('currency', $currency)
                    ->whereNull('owner_type') // Sécurité : confirme que c'est le compte système
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    throw new Exception("Le compte Escrow Système pour la devise [{$currency}] est introuvable. Avez-vous exécuté le Seeder ?");
                }

                if (!$wallet->is_active) {
                    throw new Exception("Le compte Escrow [{$currency}] est actuellement désactivé.");
                }

                // Sécurité contre le découvert du compte séquestre central
                if ($action === 'debit' && (float)$wallet->balance < $amount) {
                    throw new Exception("Solde insuffisant sur le compte Escrow {$currency} (Solde actuel : {$wallet->balance} {$currency}).");
                }

                // 3. Création de la transaction d'ajustement système
                $txReference = 'TX-SYS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
                $transaction = Transaction::create([
                    'uuid'         => (string) Str::uuid(),
                    'reference'    => $txReference,
                    'type'         => 'system_adjustment',
                    'status'       => 'initiated',
                    'amount'       => $amount,
                    'fees'         => 0,
                    'taxes'        => 0,
                    'currency'     => $currency,
                    'initiator_id' => null,
                    'description'  => $reason,
                    'metadata'     => [
                        'channel'       => 'console_artisan',
                        'command'       => 'wallet:adjust-escrow',
                        'wallet_number' => $wallet->wallet_number,
                        'action'        => $action
                    ]
                ]);

                $balanceBefore = (float) $wallet->balance;

                // 4. Mouvement du solde
                if ($action === 'credit') {
                    $wallet->increment('balance', $amount);
                } else {
                    $wallet->decrement('balance', $amount);
                }

                // 5. Signature cryptographique du Grand Livre (Ledger)
                $balanceAfter = (float) $wallet->fresh()->balance;
                $signature = $this->ledgerService->generateSignature(
                    $wallet->id, $amount, $action, $balanceBefore, $balanceAfter
                );

                // 6. Écriture de l'entrée comptable scellée
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $wallet->id,
                    'entry_type'     => $action,
                    'amount'         => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'row_signature'  => $signature
                ]);

                // 7. Validation finale de la transaction
                $transaction->update([
                    'status'       => 'completed',
                    'completed_at' => now()
                ]);

                $this->info("✨ Ajustement du compte Escrow Système effectué !");
                $this->line("Compte ciblé : <info>{$wallet->wallet_number}</info>");
                $this->line("Ancien solde : <comment>{$balanceBefore} {$currency}</comment>");
                $this->line("Nouveau solde : <info>{$balanceAfter} {$currency}</info>");
            });

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error("❌ Échec de l'ajustement : " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
