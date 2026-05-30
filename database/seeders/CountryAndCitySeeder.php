<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\City;
use Illuminate\Database\Seeder;

class CountryAndCitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. DÉFINITION DES PAYS DE LA ZONE CEMAC
        $countries = [
            [
                'code'         => 'CM',
                'name'         => 'Cameroun',
                'currency'     => 'XAF',
                'phone_prefix' => '237',
                'is_active'    => true,
                'villes'       => ['Douala', 'Yaoundé', 'Garoua', 'Maroua', 'Ngaoundéré', 'Bafoussam', 'Bamenda']
            ],
            [
                'code'         => 'CG',
                'name'         => 'Congo-Brazzaville',
                'currency'     => 'XAF',
                'phone_prefix' => '242',
                'is_active'    => true,
                'villes'       => ['Brazzaville', 'Pointe-Noire', 'Dolisie', 'Oyo']
            ],
            [
                'code'         => 'GA',
                'name'         => 'Gabon',
                'currency'     => 'XAF',
                'phone_prefix' => '241',
                'is_active'    => true,
                'villes'       => ['Libreville', 'Port-Gentil', 'Franceville', 'Oyem']
            ],
            [
                'code'         => 'TD',
                'name'         => 'Tchad',
                'currency'     => 'XAF',
                'phone_prefix' => '235',
                'is_active'    => true,
                'villes'       => ["N'Djaména", 'Moundou', 'Sarh', 'Abéché']
            ],
            [
                'code'         => 'CF',
                'name'         => 'République Centrafricaine',
                'currency'     => 'XAF',
                'phone_prefix' => '236',
                'is_active'    => true,
                'villes'       => ['Bangui', 'Bimbo', 'Berbérati']
            ],
            [
                'code'         => 'GQ',
                'name'         => 'Guinée Équatoriale',
                'currency'     => 'XAF',
                'phone_prefix' => '240',
                'is_active'    => true,
                'villes'       => ['Malabo', 'Bata', 'Oyala', 'Mongomo']
            ],
        ];

        // 2. INSERTION COMPOSITE (PAYS + VILLES)
        foreach ($countries as $countryData) {

            // Extraction et création du pays s'il n'existe pas
            $country = Country::updateOrCreate(
                ['code' => $countryData['code']], // Clé de vérification unique (ex: 'CM')
                [
                    'name'         => $countryData['name'],
                    'currency_code'     => $countryData['currency'],
                    'phone_prefix' => $countryData['phone_prefix'],
                    'is_active'    => $countryData['is_active'],
                ]
            );

            $this->command->info("Insertion du pays : {$country->name} (+{$country->phone_prefix})...");

            // Insertion des villes associées de manière fluide
            foreach ($countryData['villes'] as $cityName) {
                City::firstOrCreate(
                    [
                        'name'       => $cityName,
                        'country_id' => $country->id
                    ],
                    [
                        'is_active'  => true
                    ]
                );
            }
        }

        $this->command->info('-------------------------------------------------------');
        $this->command->info(' SUCCÈS : Données géographiques de la zone CEMAC injectées !');
        $this->command->info('-------------------------------------------------------');
    }
}
