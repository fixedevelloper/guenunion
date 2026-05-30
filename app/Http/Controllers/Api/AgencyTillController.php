<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Agency;
use App\Models\Till;
use App\Models\CashOperation;
use App\Models\Wallet;use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;

class AgencyTillController extends Controller
{
    private function getAgencyId()
    {
        $staff = Staff::where('user_id', auth()->id())->first();
        return $staff ? $staff->agency_id : null;
    }

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) return response()->json(['message' => 'Non autorisé.'], 403);

        $query = Till::where('agency_id', $agencyId);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'required|string|unique:tills,code|max:50',
            'current_balance' => 'nullable|numeric|min:0'
        ]);

        // Extraction sécurisée du solde initial (par défaut 0.00)
        $initialBalance = (float) ($validated['current_balance'] ?? 0.00);

        // Encapsulation dans une transaction pour éviter un guichet sans portefeuille en cas de crash
        DB::beginTransaction();
        try {
            // 1. Création du Guichet (Till)
            $till = Till::create([
                'agency_id'       => $agencyId,
                'name'            => $validated['name'],
                'code'            => strtoupper(trim($validated['code'])),
                'current_balance' => $initialBalance, // Maintien de la colonne de contrôle sur le guichet
                'is_active'       => true
            ]);

            // 2. Création du Portefeuille Polymorphe rattaché au Guichet
            $wallet = Wallet::create([
                'uuid'          => (string) Str::uuid(),
                'owner_id'      => $till->id,
                'owner_type'    => Till::class, // Morphisme vers le modèle Till
                'wallet_number' => 'WLT-TIL-' . $till->code . '-' . date('Ymd'), // Numéro de portefeuille unique et traçable
                'type'          => 'main', // Type principal pour la gestion d'encaisse du guichet
                'currency'      => 'XAF', // Devise par défaut de la zone CEMAC
                'balance'       => $initialBalance,
                'is_active'     => true,
                'ledger_hash'   => null
            ]);

            DB::commit();

            // On charge la relation wallet pour la réponse JSON si besoin côté Frontend
            $till->setRelation('wallet', $wallet);

            return response()->json([
                'success' => true,
                'message' => 'Guichet et portefeuille d\'encaisse initialisés avec succès.',
                'data'    => $till
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Échec de l\'initialisation financière du guichet : ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleStatus($id, Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        $till = Till::where('id', $id)->where('agency_id', $agencyId)->firstOrFail();

        $till->update([
            'is_active' => (bool) $request->input('is_active')
        ]);

        return response()->json(['success' => true, 'data' => $till]);
    }

    public function handleOperation($id, Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $validated = $request->validate([
            'type'        => 'required|in:credit,debit',
            'amount'      => 'required|numeric|min:1',
            'description' => 'required|string|max:500'
        ]);

        $amount = (float) $validated['amount'];

        // Déclenchement de la transaction de base de données
        DB::beginTransaction();
        try {
            // 1. Récupération et verrouillage (Lock) du guichet (Till) et de son agence parente
            $till = Till::where('id', $id)->where('agency_id', $agencyId)->lockForUpdate()->firstOrFail();
            $agency = Agency::where('id', $agencyId)->lockForUpdate()->firstOrFail();

            // 2. Récupération des portefeuilles polymorphes associés (Lockés également pour éviter la concurrence)
            // Le portefeuille 'main' de l'agence fait office de coffre-fort (Vault)
            $agencyWallet = Wallet::where('owner_id', $agency->id)
                ->where('owner_type', Agency::class)
                ->where('type', 'main')
                ->lockForUpdate()
                ->first();

            // Le guichet (Till) possède aussi son portefeuille d'encaisse de type 'main'
            $tillWallet = Wallet::where('owner_id', $till->id)
                ->where('owner_type', Till::class)
                ->where('type', 'main')
                ->lockForUpdate()
                ->first();

            if (!$agencyWallet || !$tillWallet) {
                return response()->json(['message' => "Configuration financière manquante (Portefeuilles introuvables)."], 422);
            }

            // 3. Traitement des flux selon le type d'opération
            if ($validated['type'] === 'credit') {
                // APPROVISIONNEMENT : Coffre Agence (Débit) -> Tiroir Guichet (Crédit)
                if ($agencyWallet->balance < $amount) {
                    return response()->json(['message' => "Le solde du coffre de l'agence est insuffisant pour approvisionner ce guichet."], 400);
                }

                // Mise à jour comptable des soldes des portefeuilles
                $agencyWallet->balance -= $amount;
                $tillWallet->balance += $amount;

                // Optionnel : Si tu synchronises des colonnes déduites sur tes tables principales
                $agency->current_balance = $agencyWallet->balance;
                $till->current_balance = $tillWallet->balance;

            } else {
                // DÉLESTAGE DE SÉCURITÉ : Tiroir Guichet (Débit) -> Coffre Agence (Crédit)
                if ($tillWallet->balance < $amount) {
                    return response()->json(['message' => "Le tiroir-caisse ne dispose pas d'assez d'encaisse pour effectuer ce délestage."], 400);
                }

                // Mise à jour comptable des soldes des portefeuilles
                $tillWallet->balance -= $amount;
                $agencyWallet->balance += $amount;

                // Optionnel : Synchronisation des totaux de contrôle
                $till->current_balance = $tillWallet->balance;
                $agency->vault_balance = $agencyWallet->balance;
            }

            // Enregistrement des états des portefeuilles
            $agencyWallet->save();
            $tillWallet->save();
            $agency->save();
            $till->save();

            // 4. Enregistrement de la pièce comptable d'audit (Conformité Loi OHADA & traçabilité stricte)
            CashOperation::create([
                'till_id'            => $till->id,
                'agency_id'          => $agencyId,
                'staff_id'            => auth()->id(), // Le manager/directeur à l'origine du mouvement
                'type'               => $validated['type']==='credit'?'cash_in':'cash_out',
                'amount'             => $amount,
                'description'        => $validated['description'],
                'source_wallet_id'   => $validated['type'] === 'credit' ? $agencyWallet->id : $tillWallet->id,
                'destination_wallet_id' => $validated['type'] === 'credit' ? $tillWallet->id : $agencyWallet->id,
                // 'ledger_hash' => ... Si tu implémentes un chaînage de hash à cette étape
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Flux de trésorerie validé et synchronisé en portefeuille.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => "Erreur transactionnelle : " . $e->getMessage()], 500);
        }
    }
}
