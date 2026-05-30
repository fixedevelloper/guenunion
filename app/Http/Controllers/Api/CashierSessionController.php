<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashOperation;
use App\Models\Till;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CashierSessionController extends Controller
{
    /**
     * Récupérer l'état comptable en direct de la caisse physique du caissier connecté.
     */
    public function getSessionStatus(): JsonResponse
    {
        $user = Auth::user();

        // 1. Sécurité : Alignement strict sur le rôle Spatie 'cashier' de la BDD
        if (!$user->hasRole('cashier')) {
            return response()->json([
                'success' => false,
                'message' => 'Profil non autorisé. Réservé aux agents de guichet.'
            ], 403);
        }

        // 2. Extraction du profil métier depuis la table Staff
        $staff = Staff::with(['agency.country'])->where('user_id', $user->id)->first();

        if (!$staff) {
            return response()->json([
                'success' => false,
                'message' => 'Profil opérateur introuvable.'
            ], 403);
        }

        // 3. Récupérer son agence de rattachement via la relation Staff
        $agency = $staff->agency;

        if (!$agency) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune agence rattachée à votre profil opérateur.'
            ], 403);
        }

        /**
         * 4. Identification du guichet (Till) de l'utilisateur.
         * Recherche basée sur le till_id stocké dans la table `staff` (ou première caisse active de l'agence)
         */
        $tillId = $staff->till_id;

        $till = Till::where('agency_id', $agency->id)
            ->when($tillId, function ($query) use ($tillId) {
                return $query->where('id', $tillId);
            })
            ->where('is_active', true)
            ->first();

        // Initialisation des variables d'état de session de caisse
        $isOpen = false;
        $currentBalance = 0.00;

        if ($till) {
            /**
             * Détermination de l'état d'ouverture (Dernière opération comptable de ce guichet)
             */
            $lastOperation = CashOperation::where('till_id', $till->id)
                ->whereIn('type', ['opening', 'closing'])
                ->orderByDesc('created_at')
                ->first();

            $isOpen = $lastOperation && $lastOperation->type === 'opening';
            $currentBalance = (float) $till->current_balance;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                // Conservé pour la compatibilité stricte avec le layout de l'UI Next.js
                'user' => [
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'code' => $staff->employee_code, // Récupération du vrai code employé unique
                ],
                // Métadonnées d'infrastructure demandées par le Header global
                'agency_name'     => $agency->name,
                'till_id'         => $till ? $till->id : null,
                'till_name'       => $till ? $till->name : 'Aucun guichet assigné',
                'till_code'       => $till ? $till->code : '—',

                // États financiers dynamiques de la caisse physique (Tills)
                'is_open'         => $isOpen,
                'current_balance' => $currentBalance,
                'currency'        => $agency->country->currency_code ?? 'XAF',
            ]
        ], 200);
    }
}
