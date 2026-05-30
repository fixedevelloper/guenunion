<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Models\Staff;
use App\Models\Agency;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * Extraire le flux des logs système paginé et filtré.
     * Accessible par : super_admin, compliance_officer.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Restriction de sécurité de premier niveau via Spatie Roles
            if (!$user->hasAnyRole(['super_admin', 'compliance_officer'])) {
                return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
            }

            // Récupération du profil Staff de l'auditeur pour déterminer son périmètre géographique
            $staff = Staff::where('user_id', $user->id)->first();

            // 2. Initialisation de la requête avec les relations réelles
            // Les relations transitent maintenant par l'identité et le profil métier staff
            $query = SystemAuditLog::with(['user.staff.agency']);

            // 3. Cloisonnement géographique : un officier de conformité national ne voit que les logs de son pays
            if ($user->hasRole('compliance_officer')) {
                if (!$staff || !$staff->country_id) {
                    return response()->json([
                        'success' => false,
                        'message' => "Erreur de contexte : Aucun pays d'affectation trouvé pour votre profil de conformité."
                    ], 403);
                }

                $countryId = $staff->country_id;

                // Filtrer les logs pour lesquels l'opérateur (User) possède un profil Staff dans le même pays
                $query->whereHas('user.staff', function ($q) use ($countryId) {
                    $q->where('country_id', $countryId);
                });
            }

            // Filtre par niveau de criticité (info, notice, warning, critical)
            if ($request->filled('severity')) {
                $query->where('severity', $request->input('severity'));
            }

            // Filtre par type d'événement (ex: TRANSACTION_INITIATED, WALLET_DEBIT...)
            if ($request->filled('event_type')) {
                $query->where('event_type', $request->input('event_type'));
            }

            // Filtre par opérateur spécifique
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            // Récupération paginée inversée (chronologie descendante)
            $logs = $query->orderBy('created_at', 'desc')->paginate(25);

            // Transformation des données optimisée pour l'affichage Next.js
            $formattedItems = collect($logs->items())->map(function ($log) {
                $logUser = $log->user;
                $logStaff = $logUser?->staff;

                return [
                    'id'            => $log->id,
                    'uuid'          => $log->uuid,
                    'event_type'    => $log->event_type,
                    'severity'      => $log->severity,
                    'message'       => $log->message,
                    'payload'       => $log->payload, // Casté automatiquement en array/object via le modèle
                    'ip_address'    => $log->ip_address,
                    'user_agent'    => $log->user_agent,
                    'created_at'    => $log->created_at->toIso8601String(),
                    'operator'      => $logUser ? [
                        'id'            => $logUser->id,
                        'username'      => $logUser->username,
                        'display_name'  => $logUser->first_name . ' ' . $logUser->last_name,
                        'email'         => $logUser->email ?? 'N/A',
                        'employee_code' => $logStaff?->employee_code ?? 'EXTERNE',
                        'agency_name'   => $logStaff?->agency?->name ?? 'Hors-Guichet / Cloud',
                    ] : [
                    'id'            => null,
                    'username'      => 'SYSTEM',
                    'display_name'  => 'Système Automatique',
                    'email'         => 'system@agensic.internal',
                    'employee_code' => 'CORE',
                    'agency_name'   => 'Serveur Central',
                ]
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formattedItems,
                'meta'    => [
                    'current_page' => $logs->currentPage(),
                    'last_page'    => $logs->lastPage(),
                    'per_page'     => $logs->perPage(),
                    'total'        => $logs->total(),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la récupération des logs d'audit : " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Charger les dépendances pour alimenter les listes déroulantes de filtres.
     * Applique le même cloisonnement géographique pour éviter les fuites d'informations.
     */
    public function dependencies(): JsonResponse
    {
        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // 1. Initialisation de la requête des utilisateurs ayant un rôle staff administratif ou guichet
            $userQuery = User::whereHas('roles', function($q) {
                $q->whereIn('name', ['super_admin', 'country_admin', 'compliance_officer', 'manager', 'cashier']);
            });

            // 2. Application de la restriction géographique si l'auditeur est un compliance_officer national
            if ($user->hasRole('compliance_officer') && $staff) {
                $countryId = $staff->country_id;

                $userQuery->whereHas('staff', function($q) use ($countryId) {
                    $q->where('country_id', $countryId);
                });
            }

            $users = $userQuery->orderBy('last_name', 'asc')->get();

            $formattedUsers = $users->map(function ($u) {
                return [
                    'id'           => $u->id,
                    'display_name' => $u->first_name . ' ' . $u->last_name,
                    'username'     => $u->username,
                    'role_label'   => $u->getRoleNames()->first() ?? 'Staff'
                ];
            });

            // 3. Extraction de la liste unique des types d'événements existants pour l'autocomplétion
            $eventTypes = SystemAuditLog::distinct()->orderBy('event_type', 'asc')->pluck('event_type');

            return response()->json([
                'success'     => true,
                'users'       => $formattedUsers,
                'event_types' => $eventTypes
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du chargement des dépendances d'audit : " . $e->getMessage()
            ], 500);
        }
    }
}
