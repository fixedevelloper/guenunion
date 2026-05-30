<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgencyTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Identification de l'agence du manager connecté
        $staff = Staff::where('user_id', $user->id)->first();
        if (!$staff || !$staff->agency_id) {
            return response()->json(['message' => 'Accès restreint aux directeurs de succursales.'], 403);
        }

        $agencyId = $staff->agency_id;

        // Requête de base sur les transactions de l'agence uniquement
        $query = Transaction::where('source_agency_id', $agencyId)
            ->with(['till', 'cashier']); // Chargement des relations utiles (tiroir et agent)

        // Filtre par type (cash_in, cash_out)
        if ($request->has('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        // Filtre par statut (success, pending, failed)
        if ($request->has('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Recherche textuelle élargie
        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('till', function($t) use ($search) {
                        $t->where('code', 'like', "%{$search}%");
                    });
            });
        }

        // Récupération triée par ordre chronologique décroissant
        $transactions = $query->latest()->paginate(50);

        // Formatage de la collection de sortie pour Next.js
        $formatted = collect($transactions->items())->map(function ($tx) {
            return [
                'id' => $tx->id,
                'reference' => $tx->reference,
                'transaction_type' => $tx->type,
                'amount' => (float) $tx->amount,
                'fees_amount' => (float) ($tx->fees_amount ?? 0),
                'status' => $tx->status,
                'customer_name' => $tx->customer_name,
                'customer_phone' => $tx->customer_phone,
                'till_code' => $tx->till?->code ?? 'GUI-GENERIC',
                'cashier_name' => $tx->cashier?->name ?? 'Agent de guichet',
                'payment_method' => $tx->payment_method ?? 'Espèces',
                'created_at_formatted' => $tx->created_at->format('d/m/Y à H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ]
        ], 200);
    }
}
