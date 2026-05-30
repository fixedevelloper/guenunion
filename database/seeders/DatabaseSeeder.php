<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CountryAndCitySeeder::class, // ÉTAPE 1 : Cartographie CEMAC
            UserAndRoleSeeder::class,    // ÉTAPE 2 : Siège social, Rôles et Comptes admins
            FeesTableSeeder::class,
            WalletEscrowSeeder::class,
        ]);
    }
}
