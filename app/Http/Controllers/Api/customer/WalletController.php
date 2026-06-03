<?php


namespace App\Http\Controllers\Api\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;

class WalletController extends Controller
{
    /**
     * Récupérer tous les portefeuilles (wallets) du client connecté.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // 1. Récupérer l'utilisateur connecté
            $user = Auth::user();

            // 2. Accéder à son profil client
            $customer = $user->customer;

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }


            $wallets = $customer->wallets()
                ->orderBy('type', 'asc')
                ->get();

            // 4. Retourner la réponse enveloppée dans la clé 'data' attendue par Flutter
            return response()->json([
                'success' => true,
                'message' => 'Portefeuilles récupérés avec succès.',
                'data' => $wallets
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des portefeuilles.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
