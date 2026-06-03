<?php


namespace App\Http\Controllers\Api\customer;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;


class TransactionController extends Controller
{
    /**
     * Récupérer l'historique des transactions du client connecté.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // 1. Récupérer l'utilisateur connecté
            $user = Auth::user();

            // S'assurer que l'utilisateur a un profil client associé
            // (Ajuste 'customer' selon le nom de ta relation dans le modèle User)
            $customer = $user->customer;

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }

            // 2. Bâtir la requête de recherche des transactions
            // Le client est concerné s'il est l'expéditeur direct OU si son numéro est le numéro destinataire
            $transactions = Transaction::where(function ($query) use ($customer) {
                $query->where('sender_customer_id', $customer->id)
                    ->orWhere('recipient_phone', $customer->phone_number);
                // 💡 Note : si tu as un 'recipient_customer_id', utilise-le,
                // sinon la correspondance par numéro de téléphone (XAF / Cameroun) fonctionne très bien.
            })
                // Charger les relations utiles pour éviter le problème de requêtes N+1 (Eager Loading)
                ->with(['sourceAgency', 'destinationAgency', 'senderCountry', 'recipientCountry'])
                ->orderBy('created_at', 'desc') // Plus récentes en premier
                ->paginate($request->input('per_page', 15));

            // 3. Retourner la réponse formattée pour ton application Flutter
            return response()->json([
                'success' => true,
                'message' => 'Historique des transactions récupéré avec succès.',
                'data' => $transactions->items(), // La liste brute des objets Transaction
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page'    => $transactions->lastPage(),
                    'per_page'     => $transactions->perPage(),
                    'total'        => $transactions->total(),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des transactions.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

}
