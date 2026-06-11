<?php


namespace App\Http\Controllers\Api\customer;


use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKycDocumentRequest;
use App\Models\KycDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class KycController extends Controller
{
    public function upload(StoreKycDocumentRequest $request): JsonResponse
    {
        logger($request->all());
        try {
            $user = $request->user();
            $customer = $user->customer;

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil client introuvable.'
                ], 404);
            }

            // Utilisation d'une transaction pour lier la création du document et le statut du client
            $customer = DB::transaction(function () use ($request, $customer) {

                // 1. Traitement et stockage de l'image Recto (Obligatoire)
                $frontPath = $request->file('front_image')->store('kyc/recto', 'local');

                // 2. Traitement et stockage de l'image Verso (Optionnelle, ex: Passeport)
                $backPath = null;
                if ($request->hasFile('back_image')) {
                    $backPath = $request->file('back_image')->store('kyc/verso', 'local');
                }

                // 3. Création de la ligne dans la table 'kyc_documents'
                // Note : Assurez-vous que ces champs sont dans le $fillable de votre modèle KycDocument
                $customer->kycDocuments()->create([
                    'uuid'            => (string) Str::uuid(),
                    'type'            => $request->input('type'),
                    'document_number' => $request->input('document_number'), // Reçoit "N/A" depuis Android
                    'front_image'     => $frontPath,
                    'back_image'      => $backPath,
                    'verified_at'     => null,
                    'verified_by'     => null,
                ]);

                // 4. Mise à jour de l'état du client dans la table 'customers'
                $customer->update([
                    'kyc_status' => 'pending', // Statut passe en attente
                ]);

                return $customer;
            });

            // 5. Rechargement des relations pour retourner l'état complet à Android
            $customer->load(['user', 'kycDocuments']);

            // Si votre Retrofit attend ApiResponse<CustomerModel> (Option A)
            return response()->json([
                'success' => true,
                'message' => 'Documents KYC soumis avec succès. Analyse en cours.',
                'data'    => $customer
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur critique lors de l'upload KYC : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique est survenue lors du traitement de vos documents.'
            ], 500);
        }
    }
}
