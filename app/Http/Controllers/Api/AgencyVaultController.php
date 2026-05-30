<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\CashOperation;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgencyVaultController extends Controller
{
    /**
     * Récupérer l'ID de l'agence du gestionnaire connecté.
     */
    private function getAgencyId()
    {
        if (auth()->user()->agency_id) {
            return auth()->user()->agency_id;
        }

        $staff = Staff::where('user_id', auth()->id())->first();
        return $staff ? $staff->agency_id : null;
    }

    public function index(): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['message' => 'Non autorisé ou agence non assignée.'], 403);
        }

        $agency = Agency::with(['wallets' => function ($query) {
            $query->where('type', 'main'); // On cible le coffre-fort principal de l'agence
        }])->findOrFail($agencyId);

        // On récupère le solde du wallet "main", sinon on fallback sur la colonne current_balance de l'agence
        $mainWallet = $agency->wallets->first();
        $vaultBalance = $mainWallet ? (float) $mainWallet->balance : (float) $agency->current_balance;

        // Récupération de l'historique des opérations du livre de caisse de l'agence
        $history = CashOperation::where('agency_id', $agencyId)
            ->with(['staff', 'till'])
            ->latest()
            ->take(50)
            ->get()
            ->map(function ($op) {
                // Détermination de la direction pour le composant Next.js
                $direction = in_array($op->type, ['cash_in', 'opening']) ? 'in' : 'out';

                return [
                    'id' => $op->id,
                    'uuid' => $op->uuid,
                    'date' => $op->created_at->format('d/m/Y à H:i'),
                    'direction' => $direction,
                    'type' => $op->type, // 'opening', 'closing', 'cash_in', 'cash_out', 'adjustment'
                    'description' => $op->description ?? 'Mouvement de coffre d\'agence',
                    'manager_name' => $op->staff->name ?? 'Agent Système',
                    'till_code' => $op->till?->code ?? 'COFFRE-CENTRAL',
                    'amount' => (float) $op->amount,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'vault_balance' => $vaultBalance,
                'history' => $history
            ]
        ], 200);
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:cash_in,cash_out,adjustment',
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:500',
            'till_id' => 'nullable|exists:tills,id'
        ]);

        $agency = Agency::findOrFail($agencyId);

        // Récupération du Wallet Polymorphique 'main'
        $vaultWallet = $agency->wallets()->where('type', 'main')->first();
        $amount = (float) $validated['amount'];

        DB::beginTransaction();
        try {
            if ($validated['type'] === 'cash_out') {
                // Vérification de l'encaisse disponible
                if ($vaultWallet && $vaultWallet->balance < $amount) {
                    return response()->json(['message' => 'Fonds insuffisants dans le coffre central de l\'agence.'], 400);
                }

                // Débit du coffre
                if ($vaultWallet) {
                    $vaultWallet->decrement('balance', $amount);
                } else {
                    $agency->decrement('current_balance', $amount);
                }

            } elseif ($validated['type'] === 'cash_in') {
                // Crédit du coffre
                if ($vaultWallet) {
                    $vaultWallet->increment('balance', $amount);
                } else {
                    $agency->increment('current_balance', $amount);
                }
            }

            // Enregistrement de la ligne d'audit dans les CashOperations
            $operation = CashOperation::create([
                'agency_id'   => $agencyId,
                'staff_id'    => auth()->id(), // ID de l'utilisateur (Directeur/Auteur)
                'till_id'     => $validated['till_id'] ?? null,
                'type'        => $validated['type'],
                'amount'      => $amount,
                'description' => $validated['description'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'L\'opération sur le coffre de l\'agence a été enregistrée avec succès.',
                'uuid' => $operation->uuid
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement de l\'opération : ' . $e->getMessage()
            ], 500);
        }
    }
}
