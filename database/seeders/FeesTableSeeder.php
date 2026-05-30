<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\FeesTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FeesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Récupération des pays configurés par notre CountryAndCitySeeder
        $cameroun = Country::where('code', 'CM')->first();
        $congo = Country::where('code', 'CG')->first();
        $gabon = Country::where('code', 'GA')->first();

        if (!$cameroun || !$congo || !$gabon) {
            $this->command->error("Erreur : Les pays CM, CG ou GA doivent être seedés avant d'exécuter FeesTableSeeder.");
            return;
        }

        // Définition des grilles tarifaires par corridor
        $corridorsData = [

            // ==========================================
            // CORRIDOR 1 : CAMEROUN -> CAMEROUN (National)
            // L'État applique une taxe de 0.25% sur les transferts nationaux
            // ==========================================
            [
                'source_id'      => $cameroun->id,
                'destination_id' => $cameroun->id,
                'type'           => 'remittance',
                'paliers'        => [
                    ['min' => 0,       'max' => 5000,    'fixed' => 150,  'percent' => 0,    'tax' => 0.25],
                    ['min' => 5001,    'max' => 10000,   'fixed' => 300,  'percent' => 0,    'tax' => 0.25],
                    ['min' => 10001,   'max' => 50000,   'fixed' => 500,  'percent' => 0,    'tax' => 0.25],
                    ['min' => 50001,   'max' => 100000,  'fixed' => 850,  'percent' => 0,    'tax' => 0.25],
                    ['min' => 100001,  'max' => 500000,  'fixed' => 0,    'percent' => 1.00, 'tax' => 0.25],
                    ['min' => 500001,  'max' => 2000000, 'fixed' => 0,    'percent' => 0.80, 'tax' => 0.25],
                ]
            ],

            // ==========================================
            // CORRIDOR 2 : CAMEROUN -> CONGO (Transfrontalier)
            // Pas de taxe nationale directe mais pourcentage de virement plus élevé
            // ==========================================
            [
                'source_id'      => $cameroun->id,
                'destination_id' => $congo->id,
                'type'           => 'remittance',
                'paliers'        => [
                    ['min' => 0,       'max' => 25000,   'fixed' => 1000, 'percent' => 0,    'tax' => 0.00],
                    ['min' => 25001,   'max' => 100000,  'fixed' => 2500, 'percent' => 0,    'tax' => 0.00],
                    ['min' => 100001,  'max' => 500000,  'fixed' => 0,    'percent' => 2.50, 'tax' => 0.00],
                    ['min' => 500001,  'max' => 5000000, 'fixed' => 0,    'percent' => 2.00, 'tax' => 0.00],
                ]
            ],

            // ==========================================
            // CORRIDOR 3 : CAMEROUN -> GABON (Transfrontalier)
            // Tarif compétitif régional
            // ==========================================
            [
                'source_id'      => $cameroun->id,
                'destination_id' => $gabon->id,
                'type'           => 'remittance',
                'paliers'        => [
                    ['min' => 0,       'max' => 30000,   'fixed' => 1200, 'percent' => 0,    'tax' => 0.00],
                    ['min' => 30001,   'max' => 150000,  'fixed' => 3000, 'percent' => 0,    'tax' => 0.00],
                    ['min' => 150001,  'max' => 700000,  'fixed' => 0,    'percent' => 2.20, 'tax' => 0.00],
                    ['min' => 700001,  'max' => 5000000, 'fixed' => 0,    'percent' => 1.80, 'tax' => 0.00],
                ]
            ],
        ];

        // 2. INJECTION LOGIQUE DANS LA BASE DE DONNÉES
        foreach ($corridorsData as $corridor) {
            foreach ($corridor['paliers'] as $palier) {
                FeesTable::create([
                    'uuid'                   => (string) Str::uuid(),
                    'transaction_type'       => $corridor['type'],
                    'source_country_id'      => $corridor['source_id'],
                    'destination_country_id' => $corridor['destination_id'],
                    'min_amount'             => $palier['min'],
                    'max_amount'             => $palier['max'],
                    'fixed_fee'              => $palier['fixed'],
                    'percentage_fee'         => $palier['percent'],
                    'tax_percentage'         => $palier['tax'],
                    'is_active'              => true,
                ]);
            }
        }

        $this->command->info('-------------------------------------------------------');
        $this->command->info(' SUCCÈS : Grille tarifaire des corridors CEMAC initialisée !');
        $this->command->info(' -> Paliers configurés pour CM->CM, CM->CG et CM->GA.');
        $this->command->info('-------------------------------------------------------');
    }
}
