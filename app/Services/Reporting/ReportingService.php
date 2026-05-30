<?php

namespace App\Services\Reporting;

use App\Models\Agency;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    /**
     * Seuil critique de liquidité par défaut (500,000 XAF)
     */
    private const DEFAULT_LIQUIDITY_THRESHOLD = 500000;

    /**
     * Extraire l'ensemble des indicateurs de performance du Dashboard.
     */
    public function getDashboardMetrics(string $period): array
    {
        return Cache::remember(
            "dashboard-metrics-{$period}",
            now()->addMinutes(5),
            fn () => $this->generateDashboardMetrics($period)
        );
    }

    /**
     * Compiler les métriques financières et géographiques.
     */
    private function generateDashboardMetrics(string $period): array
    {
        [$startDate, $previousStartDate] = $this->resolvePeriods($period);
        $now = now();

        $currentTotals = $this->getTransactionTotals($startDate, $now);
        $previousTotals = $this->getTransactionTotals($previousStartDate, $startDate);

        return [
            'totals' => $this->buildTotals($currentTotals, $previousTotals),
            'countries' => $this->getCountryMetrics($startDate)->toArray(),
            'corridors' => $this->getTopCorridors($startDate)->toArray(),
            'liquidity' => [
                'low_liquidity_agencies' => $this->getLowLiquidityAgenciesCount()
            ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | EN-TÊTES ET COMPILATIONS DES TOTAUX
    |--------------------------------------------------------------------------
    */

    private function buildTotals(array $current, array $previous): array
    {
        return [
            'volume'                 => (float) $current['volume'],
            'volume_growth'          => $this->calculateGrowth($current['volume'], $previous['volume']),
            'revenue'                => (float) $current['commissions'],
            'revenue_growth'         => $this->calculateGrowth($current['commissions'], $previous['commissions']),
            'transactions_count'     => $current['transactions_count'],
            'active_countries'       => $this->countActiveCountries(),
            'active_corridors'       => $this->countActiveCorridors(),
            'low_liquidity_agencies' => $this->getLowLiquidityAgenciesCount(),
        ];
    }

    private function getTransactionTotals(Carbon $start, Carbon $end): array
    {
        $totals = Transaction::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(amount), 0) as total_volume,
                COALESCE(SUM(fees), 0) as total_commissions,
                COUNT(*) as transactions_count
            ')
            ->first();

        return [
            'volume'             => (float) $totals->total_volume,
            'commissions'        => (float) $totals->total_commissions,
            'transactions_count' => (int) $totals->transactions_count,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PERFORMANCE ANALYTIQUE PAR PAYS
    |--------------------------------------------------------------------------
    */

    private function getCountryMetrics(Carbon $startDate)
    {
        // Alignement sur 'sender_country_id' selon votre schéma de migration
        return DB::table('transactions')
            ->join('countries', 'transactions.sender_country_id', '=', 'countries.id')
            ->selectRaw('
                countries.name,
                countries.code,
                COUNT(transactions.id) as tx_count,
                COALESCE(SUM(transactions.amount), 0) as volume,
                COALESCE(SUM(transactions.fees), 0) as commissions,
                "optimal" as liquidity_status
            ')
            ->where('transactions.status', 'completed')
            ->where('transactions.completed_at', '>=', $startDate)
            ->whereNull('transactions.deleted_at') // Sécurité SoftDeletes en SQL brut
            ->groupBy('countries.id', 'countries.name', 'countries.code')
            ->orderByDesc('volume')
            ->get();
    }

    private function getTopCorridors(Carbon $startDate)
    {
        // Alignement sur 'sender_country_id' et 'recipient_country_id'
        $corridors = DB::table('transactions')
            ->join('countries as source_country', 'transactions.sender_country_id', '=', 'source_country.id')
            ->join('countries as destination_country', 'transactions.recipient_country_id', '=', 'destination_country.id')
            ->selectRaw('
                source_country.name as source,
                destination_country.name as destination,
                COUNT(transactions.id) as tx_count,
                COALESCE(SUM(transactions.amount), 0) as volume
            ')
            ->where('transactions.status', 'completed')
            ->where('transactions.completed_at', '>=', $startDate)
            ->whereNull('transactions.deleted_at') // Sécurité SoftDeletes en SQL brut
            ->groupBy('source_country.id', 'source_country.name', 'destination_country.id', 'destination_country.name')
            ->orderByDesc('tx_count')
            ->limit(5)
            ->get();

        $totalTransactions = $corridors->sum('tx_count');

        return $corridors->map(fn ($corridor) => [
            'source'           => $corridor->source,
            'destination'      => $corridor->destination,
            'tx_count'         => (int) $corridor->tx_count,
            'volume'           => (float) $corridor->volume,
            'share_percentage' => $totalTransactions > 0
                ? round(($corridor->tx_count / $totalTransactions) * 100, 1)
                : 0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PILOTAGE DE LA LIQUIDITÉ ET RECOUVREMENT
    |--------------------------------------------------------------------------
    */

    private function getLowLiquidityAgenciesCount(): int
    {
        return Agency::query()
            ->where('is_active', true)
            ->where('current_balance', '<', self::DEFAULT_LIQUIDITY_THRESHOLD)
            ->count();
    }

    private function countActiveCountries(): int
    {
        // Alignement sur 'sender_country_id'
        return Transaction::query()
            ->where('status', 'completed')
            ->distinct('sender_country_id')
            ->count('sender_country_id');
    }

    private function countActiveCorridors(): int
    {
        // Alignement sur les vraies colonnes : sender_country_id et recipient_country_id
        return Transaction::query()
                ->where('status', 'completed')
                ->selectRaw('COUNT(DISTINCT CONCAT(sender_country_id, "-", recipient_country_id)) as total')
                ->value('total') ?? 0;
    }

    /*
    |--------------------------------------------------------------------------
    | LOGIQUE COMPTABLE ET CALENDRIER
    |--------------------------------------------------------------------------
    */

    private function calculateGrowth(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function resolvePeriods(string $period): array
    {
        $days = match ($period) {
        '7d'    => 7,
            '12m'   => 365,
            default => 30,
        };

        return [
            now()->subDays($days),
            now()->subDays($days * 2),
        ];
    }
}
