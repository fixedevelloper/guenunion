<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\User;
use App\Models\Country;
use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StaffController extends Controller
{
    /**
     * Liste complète des utilisateurs internes (Personnel Réseau).
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            $currentStaff = Staff::where('user_id', $user->id)->first();

            // 1. Définition des rôles du personnel (staff) selon les enums Spatie validés
            $staffRoles = ['super_admin', 'country_admin', 'manager', 'cashier', 'compliance', 'auditor', 'support'];

            // 2. Requête de base avec chargement de la relation décentralisée 'staff'
            $query = User::with(['roles', 'staff.agency', 'staff.country'])
                ->role($staffRoles)
                ->orderBy('first_name', 'asc');

            // 3. Cloisonnement strict des données selon le niveau hiérarchique du demandeur
            if (!$user->hasRole('super_admin') && $currentStaff) {
                if ($user->hasRole('country_admin')) {
                    // Un Country Admin ne voit que le personnel de son pays
                    $query->whereHas('staff', function ($q) use ($currentStaff) {
                        $q->where('country_id', $currentStaff->country_id);
                    });
                } else {
                    // Les autres niveaux (Managers, Caissiers...) ne voient que le personnel de leur agence
                    $query->whereHas('staff', function ($q) use ($currentStaff) {
                        $q->where('agency_id', $currentStaff->agency_id);
                    });
                }
            }

            $users = $query->get();

            // 4. Normalisation et formatage pour le composant Next.js
            $formatted = $users->map(function ($u) {
                $profile = $u->staff; // Alignement sur la relation 'staff'
                return [
                    'id'            => $u->id,
                    'uuid'          => $u->uuid,
                    'username'      => $u->username,
                    'name'          => trim($u->first_name . ' ' . $u->last_name),
                    'email'         => $u->email,
                    'phone_number'  => $u->phone_number,
                    'is_active'     => (bool) $u->is_active,
                    'role'          => $u->roles->first()?->name,
                    'employee_code' => $profile?->employee_code ?? '—',
                    'agency_name'   => $profile?->agency?->name ?? 'Hors-Réseau / Siège',
                    'country_name'  => $profile?->country?->name ?? '—',
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération du personnel : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Charge les dépendances pour alimenter dynamiquement le formulaire Next.js.
     */
    public function dependencies(): JsonResponse
    {
        return response()->json([
            'countries' => Country::select('id', 'name', 'code')->orderBy('name')->get(),
            'agencies'  => Agency::select('id', 'name', 'country_id')->orderBy('name')->get()
        ], 200);
    }

    /**
     * Enregistrer un nouveau membre du personnel avec contrôle hiérarchique strict.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validation stricte des données reçues de Next.js
        $validated = $request->validate([
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'name'          => 'required|string|max:200',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'required|string|max:20',
            'employee_code' => 'required|string|unique:staff,employee_code',
            'role'          => 'required|string|in:cashier,manager',
            'agency_id'     => 'required|exists:agencies,id',
            'password'      => 'required|string|min:6',
        ]);

        try {
            // 2. Isolation dans une transaction pour éviter les comptes fantômes
            $staff = DB::transaction(function () use ($validated, $request) {

                // Étape A : Création de l'utilisateur technique (Accès système)
                $user = User::create([
                    'username'     => strtolower(Str::slug($validated['name']) . rand(100, 999)),
                    'name'       => $validated['name'],
                    'first_name' => $validated['first_name'], // si votre table possède cette colonne
                    'last_name'  => $validated['last_name'],  // si votre table possède cette colonne
                    'email'      => $validated['email'],
                    'phone'      => $validated['phone'],
                    'password'   => Hash::make($validated['password']),
                    'is_active'  => true,
                ]);

                // Étape B : Attribution du rôle (via Spatie Permission ou votre système d'enums)

                    $user->assignRole($validated['role']);


                // Étape C : Héritage du pays depuis l'agence sélectionnée pour le cloisonnement
                $agency = Agency::findOrFail($validated['agency_id']);

                // Étape D : Création du profil métier lié
                return Staff::create([
                    'user_id'       => $user->id,
                    'agency_id'     => $agency->id,
                    'country_id'    => $agency->country_id, // L'agent hérite automatiquement du pays de son agence
                    'employee_code' => $validated['employee_code'],
                    'phone'         => $validated['phone'],
                    'is_active'     => true,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Agent opérationnel enrôlé avec succès.',
                'data'    => $staff->load('user', 'agency')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Échec de l'enrôlement en base de données : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistrer un Administrateur Pays via le scope d'infrastructure.
     */
    public function storeRegionalOrCountryAdmin(Request $request): JsonResponse
    {
        if (!Auth::user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Seul le Super Administrateur peut déployer des nœuds administratifs nationaux.'], 403);
        }

        $validated = $request->validate([
            'name'         => 'required|string|max:150',
            'email'        => 'required|email|unique:users,email',
            'phone_number' => 'nullable|string|unique:users,phone_number',
            'password'     => 'required|string|min:8',
            'country_id'   => 'required|integer|exists:countries,id',
        ]);

        $result = DB::transaction(function () use ($validated) {
            $nameParts = explode(' ', trim($validated['name']), 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? '';

            $user = User::create([
                'uuid'         => (string) Str::uuid(),
                'username'     => strtolower(Str::slug($firstName) . rand(100, 999)),
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
                'password'     => Hash::make($validated['password']),
                'is_active'    => true,
            ]);

            $staff = Staff::create([
                'uuid'                => (string) Str::uuid(),
                'user_id'             => $user->id,
                'employee_code'       => 'ADM-' . strtoupper(Str::random(3)) . rand(100, 999),
                'country_id'          => $validated['country_id'],
                'agency_id'           => null,
                'created_by_staff_id' => Staff::where('user_id', Auth::id())->value('id'),
                'is_active'           => true,
            ]);

            $user->assignRole('country_admin');

            return compact('user', 'staff');
        }); // syntaxe corrigée ici ()

        return response()->json([
            'success' => true,
            'message' => 'Administrateur National / Pays déployé avec succès.',
            'data'    => $result
        ], 201);
    }

    /**
     * Activer / Suspendre immédiatement un compte utilisateur.
     */
    public function toggleStatus($uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        if (Auth::id() === $user->id) {
            return response()->json([
                'success' => false,
                'message' => "Opération interdite : vous ne pouvez pas suspendre votre propre session d'accès."
            ], 403);
        }

        $user->update([
            'is_active' => !$user->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => "Le statut d'accès du compte a été modifié."
        ], 200);
    }
}
