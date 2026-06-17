<?php


namespace App\Http\Controllers\Api\customer;


use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKycDocumentRequest;
use App\Models\KycDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

    public function changePin(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;

        // 🛡️ Détermination du statut : s'agit-il d'une configuration initiale ?
        $isFirstTime = is_null($customer->transaction_pin);

        // 1. Validation dynamique des données entrantes
        $validator = Validator::make($request->all(), [
            'old_pin' => [$isFirstTime ? 'nullable' : 'required', 'string', 'digits:4'],
            'new_pin' => ['required', 'string', 'digits:4', $isFirstTime ? '' : 'different:old_pin'],
        ], [
            'old_pin.required'  => 'Le code PIN actuel est obligatoire pour effectuer cette modification.',
            'old_pin.digits'    => 'Le code PIN actuel doit comporter exactement 4 chiffres.',
            'new_pin.required'  => 'Le nouveau code PIN est obligatoire.',
            'new_pin.digits'    => 'Le nouveau code PIN doivent comporter exactement 4 chiffres.',
            'new_pin.different' => 'Le nouveau code PIN doit être différent du code PIN actuel.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        // 2. Vérification du PIN actuel (uniquement s'il ne s'agit pas d'une première configuration)
        if (!$isFirstTime) {
            if (!Hash::check($request->input('old_pin'), $customer->transaction_pin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le code PIN actuel saisi est incorrect.'
                ], 400);
            }
        }

        // 3. Chiffrement et persistance du code PIN
        $customer->update([
            'transaction_pin' => Hash::make($request->input('new_pin'))
        ]);

        // 4. Message de retour adapté au contexte utilisateur (UX propre)
        $successMessage = $isFirstTime
            ? 'Votre code PIN de transaction a été configuré avec succès.'
            : 'Votre code PIN de transaction a été mis à jour avec succès.';

        return response()->json([
            'success' => true,
            'message' => $successMessage,
            'data'    => null
        ], 200);
    }
    public function verifyPin(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
        ]);

        $user = $request->user();
        $customer = $user->customer;

        if (!$customer || !$customer->transaction_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun code PIN configuré pour ce compte.'
            ], 404);
        }

        // Comparaison sécurisée avec la BDD chiffrée
        if (!Hash::check($request->input('pin'), $customer->transaction_pin)) {
            return response()->json([
                'success' => false,
                'message' => 'Code PIN de transaction incorrect.'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code PIN validé avec succès.',
            'data'    => null
        ], 200);
    }
}
