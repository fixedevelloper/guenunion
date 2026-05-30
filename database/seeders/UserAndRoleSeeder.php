<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Country;
use App\Models\City;
use App\Models\User;
use App\Models\Staff;
use App\Models\Wallet;use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserAndRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Encapsulation globale dans une transaction pour garantir l'intégrité FinTech
        DB::transaction(function () {

            // 1. ALIGNEMENT GÉOGRAPHIQUE
            $country = Country::where('code', 'CM')->first();
            $city = City::where('name', 'Douala')->where('country_id', $country?->id)->first();

            if (!$country || !$city) {
                $this->command->error("Erreur : Les données géographiques de base (CM - Douala) doivent être injectées par CountryAndCitySeeder avant d'exécuter ce seeder.");
                return;
            }

            // 2. CONFIGURATION DE L'AGENCE DE TEST (Douala Centre)
            $agency = Agency::firstOrCreate(
                ['code' => 'AG-DLA-01'],
                [
                    'name'            => 'Agence Douala - Akwa Centre',
                    'country_id'      => $country->id,
                    'city_id'         => $city->id,
                    'status'          => 'active',
                    'current_balance' => 0.00
                ]
            );
            Wallet::firstOrCreate(
                [
                    'owner_id'   => $agency->id,
                    'owner_type' => Agency::class, // Morphisme vers l'agence
                    'type'       => 'main',        // Type principal pour les opérations de caisse
                ],
                [
                    'uuid'          => (string) Str::uuid(),
                    'wallet_number' => 'WLT-AGE-' . $agency->code . '-01', // Numéro de portefeuille unique et traçable
                    'currency'      => 'XAF',
                    'balance'       => 0.00,
                    'is_active'     => true,
                    'ledger_hash'   => null, // Sera calculé lors de la première transaction
                ]
            );

            // 3. ENUMS DES RÔLES OFFICIELS (Spatie - Synchronisés avec l'API)
            $roles = ['super_admin', 'country_admin', 'manager', 'cashier', 'compliance', 'auditor', 'support','customer'];

            foreach ($roles as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            }

            $this->command->info('Configuration des rôles Spatie validée...');

            // ----------------------------------------------------------------------
            // 4. CRÉATION DU COMPTE : SUPER ADMIN GLOBAL (Vue Omniprésente)
            // ----------------------------------------------------------------------
            $superAdminUser = User::updateOrCreate(
                ['email' => 'admin@agensic-fintech.com'],
                [
                    'uuid'         => (string) Str::uuid(),
                    'username'     => 'lorenzo_admin',
                    'first_name'   => 'Rodrigue',
                    'last_name'    => 'Mbah',
                    'phone_number' => '237699999999',
                    'password'     => Hash::make('Security@Fintech2026!'),
                    'is_active'    => true,
                ]
            );
            $superAdminUser->assignRole('super_admin');

            // Profil Staff : Aucune attache géographique ou structurelle
            $superAdminStaff = Staff::updateOrCreate(
                ['user_id' => $superAdminUser->id],
                [
                    'uuid'                => (string) Str::uuid(),
                    'employee_code'       => 'EMP-SUP-001',
                    'country_id'          => null, // Strictement NULL pour une vue globale totale
                    'agency_id'           => null, // Strictement NULL
                    'created_by_staff_id' => null,
                    'is_active'           => true,
                ]
            );

            // ----------------------------------------------------------------------
            // 5. CRÉATION DU COMPTE : COUNTRY ADMIN (Vue à l'échelle du Cameroun)
            // ----------------------------------------------------------------------
            $countryAdminUser = User::updateOrCreate(
                ['email' => 'cameroun.admin@agensic-fintech.com'],
                [
                    'uuid'         => (string) Str::uuid(),
                    'username'     => 'cm_admin',
                    'first_name'   => 'Admin',
                    'last_name'    => 'Cameroun',
                    'phone_number' => '237699888888',
                    'password'     => Hash::make('CountryAdmin2026!'),
                    'is_active'    => true,
                ]
            );
            $countryAdminUser->assignRole('country_admin');

            $countryAdminStaff = Staff::updateOrCreate(
                ['user_id' => $countryAdminUser->id],
                [
                    'uuid'                => (string) Str::uuid(),
                    'employee_code'       => 'EMP-CMR-001',
                    'country_id'          => $country->id, // Rattaché uniquement au pays
                    'agency_id'           => null,         // Voit toutes les agences du pays
                    'created_by_staff_id' => $superAdminStaff->id, // Tracé : Créé par le Super Admin
                    'is_active'           => true,
                ]
            );

            // ----------------------------------------------------------------------
            // 6. CRÉATION DU COMPTE : AGENCY MANAGER (Directeur de l'agence de Douala)
            // ----------------------------------------------------------------------
            $managerUser = User::updateOrCreate(
                ['email' => 'manager.akwa@agensic-fintech.com'],
                [
                    'uuid'         => (string) Str::uuid(),
                    'username'     => 'manager_akwa',
                    'first_name'   => 'Lorenzo',
                    'last_name'    => 'Manager',
                    'phone_number' => '237699777777',
                    'password'     => Hash::make('ManagerPassword123!'),
                    'is_active'    => true,
                ]
            );
            $managerUser->assignRole('manager');

            $managerStaff = Staff::updateOrCreate(
                ['user_id' => $managerUser->id],
                [
                    'uuid'                => (string) Str::uuid(),
                    'employee_code'       => 'EMP-MGR-001',
                    'country_id'          => $country->id,
                    'agency_id'           => $agency->id,  // Ancré dans l'agence d'Akwa
                    'created_by_staff_id' => $countryAdminStaff->id, // Tracé : Créé par le Country Admin
                    'is_active'           => true,
                ]
            );

            // ----------------------------------------------------------------------
            // 7. CRÉATION DU COMPTE : CASHIER (Guichetier Opérationnel à Douala)
            // ----------------------------------------------------------------------
            $cashierUser = User::updateOrCreate(
                ['email' => 'cashier.akwa@agensic-fintech.com'],
                [
                    'uuid'         => (string) Str::uuid(),
                    'username'     => 'caissier_douala',
                    'first_name'   => 'Jean',
                    'last_name'    => 'Guichetier',
                    'phone_number' => '237677777777',
                    'password'     => Hash::make('CashierPassword123!'),
                    'is_active'    => true,
                ]
            );
            $cashierUser->assignRole('cashier');

            Staff::updateOrCreate(
                ['user_id' => $cashierUser->id],
                [
                    'uuid'                => (string) Str::uuid(),
                    'employee_code'       => 'EMP-CSH-001',
                    'country_id'          => $country->id,
                    'agency_id'           => $agency->id,  // Ancré dans la même agence
                    'created_by_staff_id' => $managerStaff->id, // Tracé : Créé directement par son Manager
                    'is_active'           => true,
                ]
            );

            // Rapports d'exécution détaillés dans la console
            $this->command->info('-------------------------------------------------------');
            $this->command->info(' 🚀 SUCCÈS : Hiérarchie complète injectée avec succès !');
            $this->command->info(' 1. Super Admin   : admin@agensic-fintech.com (Global)');
            $this->command->info(' 2. Country Admin : cameroun.admin@agensic-fintech.com (CM)');
            $this->command->info(' 3. Manager       : manager.akwa@agensic-fintech.com (Agence Akwa)');
            $this->command->info(' 4. Caissier      : cashier.akwa@agensic-fintech.com (Agence Akwa)');
            $this->command->info('-------------------------------------------------------');
        });
    }
}
