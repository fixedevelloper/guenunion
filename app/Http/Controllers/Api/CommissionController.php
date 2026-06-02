<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CommissionController extends Controller
{
    /**
     * Récupère le grand livre des commissions basées sur le modèle dédié.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Initialisation de la requête avec un chargement optimisé (Eager Loading)
            $query = Commission::query()->with([
                'transaction:id,reference,amount,currency,status,sender_name,recipient_name',
                'wallet:id,name,holder_type' // Adaptez les colonnes selon votre table wallets
            ]);

            // 1. FILTRE : Par portefeuille spécifique
            if ($request->filled('wallet_id')) {
                $query->where('wallet_id', $request->wallet_id);
            }

            // 2. RECHERCHE TEXTUELLE MUTLICRITÈRE (Référence transaction, ID, Description)
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'LIKE', "%{$search}%")
                        ->orWhere('uuid', 'LIKE', "%{$search}%")
                        ->orWhereHas('transaction', function ($txQuery) use ($search) {
                            $txQuery->where('reference', 'LIKE', "%{$search}%")
                                ->orWhere('sender_name', 'LIKE', "%{$search}%")
                                ->orWhere('recipient_name', 'LIKE', "%{$search}%");
                        });
                });
            }

            // 3. PAGINATION ET TRI (Du plus récent au plus ancien)
            $perPage = (int) $request->input('per_page', 20);
            $commissions = $query->orderByDesc('created_at')->paginate($perPage);

            // 4. NORMALISATION DU FLUX POUR L'UI NEXT.JS
            $formattedData = collect($commissions->items())->map(function ($com) {
                return [
                    'id'           => $com->id,
                    'uuid'         => $com->uuid,
                    'amount'       => (float) $com->amount,
                    'percentage'   => (float) $com->percentage,
                    'description'  => $com->description,
                    'date'         => $com->created_at->toIso8601String(),
                    'wallet'       => $com->wallet ? [
                        'id'   => $com->wallet->id,
                        'name' => $com->wallet->name,
                    ] : null,
                    'transaction'  => $com->transaction ? [
                        'id'               => $com->transaction->id,
                        'reference'        => $com->transaction->reference,
                        'principal_amount' => (float) $com->transaction->amount,
                        'currency'         => $com->transaction->currency,
                        'status'           => $com->transaction->status,
                        'sender'           => $com->transaction->sender_name,
                        'recipient'        => $com->transaction->recipient_name,
                    ] : null,
                ];
            });

            return response()->json([
                'success'    => true,
                'data'       => $formattedData,
                'pagination' => [
                    'current_page' => $commissions->currentPage(),
                    'last_page'    => $commissions->lastPage(),
                    'total'        => $commissions->total(),
                    'per_page'     => $commissions->perPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur lors de l'extraction du modèle Commission : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur lors du traitement comptable des commissions."
            ], 500);
        }
    }
}
