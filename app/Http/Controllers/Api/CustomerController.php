<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Models\Staff;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * RECHERCHE PRÉDICTIVE / FILTRE DES CLIENTS (Pour le guichet de caisse).
     * Accessible par : merchant, cashier, manager, country_admin, super_admin.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Jointure obligatoire avec 'user' pour interroger l'état civil réel du client
            $query = Customer::with('user');

            // Barrière géographique : Un Admin Pays ne peut rechercher que les clients enregistrés dans son pays
            if ($user->hasRole('country_admin') && $staff) {
                $query->where('country_id', $staff->country_id);
            }

            // Recherche globale croisée (Nom, Prénom, Téléphone) sur la table 'users' liée
            if ($request->has('search')) {
                $search = $request->input('search');

                if (is_numeric(preg_replace('/[^0-9]/', '', $search))) {
                    $cleanPhone = clean_phone($search);
                    $query->whereHas('user', function ($q) use ($cleanPhone) {
                        $q->where('phone_number', 'LIKE', "%{$cleanPhone}%");
                    });
                } else {
                    $query->whereHas('user', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%");
                    });
                }
            }

            // Filtrer par état de compte (Par défaut : comptes actifs pour les opérations de guichet)
            $status = $request->input('status', 'active');
            $query->where('status', $status);

            // Limitation pour optimiser la performance de l'autocomplétion sur l'UI Next.js
            $customers = $query->whereHas('user')
                ->handleSort($query) // Utilisation de votre scope ou tri standard
                ->limit(10)
                ->get();

            // Normalisation à plat pour simplifier l'intégration avec React Query / Select Components
            $formatted = $customers->map(function ($c) {
                return [
                    'id'             => $c->id,
                    'uuid'           => $c->uuid,
                    'reference'      => $c->reference,
                    'first_name'     => $c->user->first_name,
                    'last_name'      => $c->user->last_name,
                    'display_name'   => $c->user->first_name . ' ' . $c->user->last_name,
                    'phone_number'   => $c->user->phone_number,
                    'email'          => $c->user->email,
                    'id_type'        => $c->id_type,
                    'id_number'      => $c->id_number,
                    'id_expiry_date' => $c->id_expiry_date,
                    'kyc_level'      => $c->kyc_level,
                    'status'         => $c->status,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formatted
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la recherche client : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ENREGISTRER UN NOUVEAU CLIENT AVEC VÉRIFICATION KYC (Au guichet).
     * Accessible par : merchant, cashier, manager.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();

        $validated = $request->validate([
            // Données d'identité (Table Users)
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'phone'        => 'required|string|unique:users,phone_number',
            'email'        => 'nullable|email|unique:users,email',
            // Données KYC / Profil (Table Customers)
            'id_type'      => 'required|string|in:CNI,PASSPORT,DRIVING_LICENSE,REFUGEE_CARD',
            'id_number'    => 'required|string|max:50|unique:customers,id_number',
            'id_expiry'    => 'required|date|after:today',
            'dob'          => 'required|date|before:-18 years', // Protection légale de majorité
            'address'      => 'nullable|string',
            'country_id'   => 'required|exists:countries,id',
            'city_id'      => 'required|exists:cities,id',
        ]);

        // Protection de juridiction pour l'agent de guichet
        if ($user->hasRole('country_admin' ) || $user->hasRole('manager') || $user->hasRole('cashier')) {
            if ($staff && (int)$validated['country_id'] !== (int)$staff->country_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Action interdite : Vous ne pouvez pas immatriculer un client en dehors de votre pays d'affectation."
                ], 403);
            }
        }

        try {
            return DB::transaction(function () use ($validated) {

                // 1. Création de l'entité centrale d'authentification
                $customerUser = User::create([
                    'uuid'         => (string) Str::uuid(),
                    'username'     => strtolower($validated['first_name'][0] . Str::slug($validated['last_name']) . rand(100, 999)),
                    'first_name'   => strtoupper($validated['first_name']),
                    'last_name'    => ucwords(strtolower($validated['last_name'])),
                    'phone_number' => clean_phone($validated['phone']),
                    'email'        => $validated['email'] ?? null,
                    'password'     => Hash::make('Default@2026'), // Exigence de mot de passe temporaire
                    'is_active'    => true,
                ]);

                // Habilitation du rôle client
                $customerUser->assignRole('customer');

                // 2. Création du profil KYC couplé
                $customer = Customer::create([
                    'uuid'           => (string) Str::uuid(),
                    'user_id'        => $customerUser->id,
                    'reference'      => 'CLI-' . strtoupper(Str::random(8)),
                    'birth_date'     => $validated['dob'],
                    'id_type'        => $validated['id_type'],
                    'id_number'      => strtoupper($validated['id_number']),
                    'id_expiry_date' => $validated['id_expiry'],
                    'country_id'     => $validated['country_id'],
                    'city_id'        => $validated['city_id'],
                    'address'        => $validated['address'],
                    'kyc_level'      => 'full', // Initialisation directe au guichet physique (Vérification de visu)
                    'kyc_status'     => 'approved',
                    'status'         => 'active',
                ]);

                Log::info("KYC_ARCH: Client [{$customer->reference}] créé pour User [{$customerUser->id}]");

                return response()->json([
                    'success' => true,
                    'message' => 'Profil client créé et validé avec succès au guichet.',
                    'data'    => [
                        'user'     => $customerUser,
                        'customer' => $customer
                    ]
                ], 201);
            });

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement de l\'immatriculation : ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * CONSULTER LA FICHE CLIENT ET SON HISTORIQUE DE TRANSACTIONS.
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Récupération de la fiche avec la relation User d'identité
            $customer = Customer::with('user')->where('uuid', $uuid)->firstOrFail();

            // Isolation géographique des fiches clients
            if ($user->hasRole('country_admin') && $staff) {
                if ($customer->country_id !== $staff->country_id) {
                    return response()->json(['success' => false, 'message' => "Accès non autorisé : ce client dépend d'une autre juridiction régionale."], 403);
                }
            }

            // Récupération des transactions du grand livre liées à ce client
            $transactions = \App\Models\Transaction::where('sender_customer_id', $customer->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data'    => [
                    'customer'     => [
                        'id'             => $customer->id,
                        'uuid'           => $customer->uuid,
                        'reference'      => $customer->reference,
                        'first_name'     => $customer->user?->first_name,
                        'last_name'      => $customer->user?->last_name,
                        'phone_number'   => $customer->user?->phone_number,
                        'email'          => $customer->user?->email,
                        'id_type'        => $customer->id_type,
                        'id_number'      => $customer->id_number,
                        'id_expiry_date' => $customer->id_expiry_date,
                        'kyc_level'      => $customer->kyc_level,
                        'kyc_status'     => $customer->kyc_status,
                        'status'         => $customer->status,
                        'created_at'     => $customer->created_at->toIso8601String()
                    ],
                    'transactions' => $transactions->items(),
                    'pagination'   => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ]
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Profil client introuvable ou inaccessible."
            ], 404);
        }
    }

    /**
     * HISTORIQUE DES DEMANDES DE VÉRIFICATION KYC (Back-office Conformité).
     */
    public function kycSubmissions(Request $request): JsonResponse
    {
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $status = $request->input('status', 'pending'); // pending, approved, rejected

        $query = Customer::with(['user', 'kycDocument' => function($q) {
            $q->with('verifiedByUser:id,first_name,last_name');
        }])
            ->where('kyc_status', $status);

        // Restriction de périmètre géographique pour les contrôleurs nationaux (Compliance)
        if ($user->hasRole('country_admin') && $staff) {
            $query->where('country_id', $staff->country_id);
        }

        // Recherche textuelle multicritère sur l'utilisateur ou la référence
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function($uQ) use ($search) {
                        $uQ->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhere('phone_number', 'LIKE', "%{$search}%");
                    });
            });
        }

        $query->orderByDesc('updated_at');

        // Compteurs d'alertes dynamiques pour l'UI Next.js (Badges de notification)
        $countQuery = Customer::where('kyc_status', 'pending');
        if ($user->hasRole('country_admin') && $staff) {
            $countQuery->where('country_id', $staff->country_id);
        }
        $pendingCount = $countQuery->count();

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
            'meta'    => [
                'pending_count' => $pendingCount
            ]
        ], 200);
    }

    /**
     * EVALUER UN DOSSIER DE CONFORMITÉ KYC (Approuver / Rejeter).
     */
    public function evaluateKyc(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:255'
        ]);

        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $customer = Customer::where('uuid', $uuid)->firstOrFail();
        $kycDocument = $customer->kycDocument;

        // Sécurité géographique : Empêcher l'évaluation d'un dossier hors juridiction pays
        if ($user->hasRole('country_admin') && $staff) {
            if ($customer->country_id !== $staff->country_id) {
                return response()->json(['success' => false, 'message' => "Action non autorisée sur ce dossier géographique."], 403);
            }
        }

        if ($request->action === 'approve') {
            // 1. Passage du niveau de confiance au palier maximal (Full) pour libérer les verrous et plafonds
            $customer->update([
                'kyc_status'      => 'approved',
                'kyc_verified_at' => now(),
                'kyc_level'       => 'full',
                'status'          => 'active'
            ]);

            // 2. Signature de traçabilité de l'auditeur sur le document probant
            if ($kycDocument) {
                $kycDocument->update([
                    'verified_at' => now(),
                    'verified_by' => $user->id
                ]);
            }
        } else {
            // Logique de Rejet de conformité
            $customer->update([
                'kyc_status' => 'rejected',
                'kyc_level'  => 'none',
                // Consolidation historique dans le payload d'erreur destiné à l'application mobile client
                'metadata'   => array_merge(($customer->metadata ?? []), [
                    'rejection_reason' => $request->reason,
                    'rejected_at'      => now()->toIso8601String(),
                    'rejected_by'      => $user->id
                ])
            ]);

            if ($kycDocument) {
                $kycDocument->delete(); // Soft delete sécurisé de la pièce non conforme
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Le dossier de conformité KYC a été traité avec succès.'
        ], 200);
    }
}
