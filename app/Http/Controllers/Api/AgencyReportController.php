<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\CashOperation;
use App\Models\Till;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgencyReportController extends Controller
{
    private function getAgencyId()
    {
        if (auth()->user()->agency_id) {
            return auth()->user()->agency_id;
        }

        $staff = Staff::where('user_id', auth()->id())->first();
        return $staff ? $staff->agency_id : null;
    }

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['message' => 'Non autorisé ou agence absente.'], 403);
        }

        // Calcul de la plage de dates selon la période reçue (daily, weekly, monthly)
        $period = $request->input('period', 'daily');
        $startDate = Carbon::today();

        if ($period === 'weekly') {
            $startDate = Carbon::now()->startOfWeek();
        } elseif ($period === 'monthly') {
            $startDate = Carbon::now()->startOfMonth();
        }

        // 1. Calcul des Métriques Globales sur la période donnée
        $operations = CashOperation::where('agency_id', $agencyId)
            ->where('created_at', '>=', $startDate)
            ->select('type', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('type')
            ->get();

        $totalCashIn = $operations->where('type', 'cash_in')->first()?->total_amount ?? 0;
        $totalCashOut = $operations->where('type', 'cash_out')->first()?->total_amount ?? 0;
        $totalAdjustment = $operations->where('type', 'adjustment')->first()?->total_amount ?? 0;

        // Nombre de tiroirs-caisses actifs/ouverts sur l'agence
        $activeTillsCount = Till::where('agency_id', $agencyId)
          //  ->where('status', 'open')
            ->count();

        // 2. Ventilation/Breakdowns par Tiroir-Caisse
        // On récupère le volume traité pour chaque tiroir impliqué
        $tillsData = Till::where('agency_id', $agencyId)
            ->with(['agency','agency.staff']) // Relation vers l'utilisateur assigné au guichet si existant
            ->get()
            ->map(function ($till) use ($startDate) {
                // Volume Dépôts (cash_in) sur ce guichet particulier
                $cashInVolume = CashOperation::where('till_id', $till->id)
                    ->where('type', 'cash_in')
                    ->where('created_at', '>=', $startDate)
                    ->sum('amount');

                // Volume Retraits (cash_out) sur ce guichet particulier
                $cashOutVolume = CashOperation::where('till_id', $till->id)
                    ->where('type', 'cash_out')
                    ->where('created_at', '>=', $startDate)
                    ->sum('amount');

                return [
                    'till_code' => $till->code,
                    'cashier_name' => $till->staff->name ?? 'Aucun agent',
                    'cash_in_volume' => (float) $cashInVolume,
                    'cash_out_volume' => (float) $cashOutVolume,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => [
                    'total_cash_in' => (float) $totalCashIn,
                    'total_cash_out' => (float) $totalCashOut,
                    'total_fees' => (float) ($totalCashIn * 0.01), // Exemple : 1% de frais d'agence simulé
                    'active_tills_count' => $activeTillsCount
                ],
                'breakdowns' => $tillsData
            ]
        ], 200);
    }
}
