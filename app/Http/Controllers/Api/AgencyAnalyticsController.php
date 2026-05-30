<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Agency;
use App\Models\Transaction;
use App\Models\Till; // Utilisation du modèle Till
use Illuminate\Http\JsonResponse;

class AgencyAnalyticsController extends Controller
{
    public function getAgencyDashboardData(): JsonResponse
    {
        $user = auth()->user();
        $staff = Staff::where('user_id', $user->id)->first();

        if (!$staff || !$staff->agency_id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $agencyId = $staff->agency_id;
        $today = now()->startOfDay();

        // 1. Solde du coffre
        $vaultBalance = Agency::find($agencyId)->vault_balance ?? 0;

        // 2. Flux du jour
        $totalCashIn = Transaction::where('source_agency_id', $agencyId)
            ->where('type', 'cash_in')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        $totalCashOut = Transaction::where('source_agency_id', $agencyId)
            ->where('type', 'cash_out')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        // 3. Récupération des Tills actifs
        // On récupère les tills actifs de l'agence
        $activeTills = Till::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->get()
            ->map(function ($till) {
                return [
                    'id' => $till->id,
                    'cashier_name' => $till->name, // ou relation si vous avez un user rattaché
                    'employee_code' => $till->code,
                    'current_balance' => (float) $till->current_balance,
                ];
            });

        // 4. Dernières transactions
        $recentTransactions = Transaction::where('source_agency_id', $agencyId)
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'vault_balance' => (float) $vaultBalance,
                'total_cash_in' => (float) $totalCashIn,
                'total_cash_out' => (float) $totalCashOut,
                'active_cashiers_count' => $activeTills->count(),
                'active_sessions' => $activeTills,
                'recent_transactions' => $recentTransactions,
            ]
        ]);
    }
}
