<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WalletEscrowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Liste des devises supportées par ton réseau de transfert d'argent
        $currencies = ['XAF', 'XOF', 'EUR', 'USD'];

        foreach ($currencies as $currency) {
            // updateOrCreate évite les doublons si tu réexécutes le seeder
            Wallet::updateOrCreate(
                [
                    'type'     => 'escrow',
                    'currency' => $currency,
                ],
                [
                    // Le uuid est généré automatiquement par le boot() du modèle si absent,
                    // mais nous l'assurons ici au cas où
                    'uuid'          => (string) Str::uuid(),
                    'owner_type'    => null, // Le compte escrow appartient au système
                    'owner_id'      => null,
                    'wallet_number' => 'ESCROW-' . $currency . '-SYS',
                    'balance'       => 1000000.00, // Commence à 0, il augmente aux émissions et diminue aux paiements
                    'is_active'     => true,
                    'ledger_hash'   => hash('sha256', "ESCROW-INIT-{$currency}-0.00"),
                ]
            );
        }

        $this->command->info('Les comptes système Escrow (Transit) ont été initialisés avec succès.');
    }
}
