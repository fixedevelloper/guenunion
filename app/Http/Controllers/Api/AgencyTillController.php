<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Agency;
use App\Models\Till;
use App\Models\CashOperation;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Wallet;use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // On sélectionne les champs de tills et on récupère la balance du wallet associé
        $query = Till::where('tills.agency_id', $agencyId)
            ->join('wallets', function ($join) {
                $join->on('wallets.owner_id', '=', 'tills.id')
                    ->where('wallets.owner_type', '=', Till::class) // Ou 'App\Models\Till' selon votre BDD
                    ->where('wallets.type', '=', 'main')
                    ->where('wallets.is_active', '=', true);
            })
            ->select([
                'tills.id',
                'tills.uuid',
                'tills.agency_id',
                'tills.name',
                'tills.code',
                'wallets.balance as current_balance', // Écrase/Remplace le current_balance par celui du Wallet
                'tills.is_active',
                'tills.status',
                'tills.created_at',
                'tills.updated_at'
            ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('tills.name', 'like', "%{$search}%")
                    ->orWhere('tills.code', 'like', "%{$search}%");
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }
/*    public function index(Request $request): JsonResponse
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
    }*/

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId();
        if (!$agencyId) {
            return response()->json(['success' => false, 'message' => 'Opération non autorisée. Votre compte n\'est rattaché à aucune agence.'], 403);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'required|string|unique:tills,code|max:50',
            'current_balance' => 'nullable|numeric|min:0'
        ], [
            'code.unique' => 'Ce code de guichet est déjà attribué au sein du réseau.',
            'current_balance.min' => 'Le solde initial ne peut pas être un montant négatif.'
        ]);

        $initialBalance = (float) ($validated['current_balance'] ?? 0.00);

        // Début de la transaction globale avec isolation stricte
        DB::beginTransaction();
        try {

            // 1. Si le guichet commence avec de l'argent, on doit sécuriser et amputer le portefeuille de l'agence
            $agencyWallet = null;
            if ($initialBalance > 0) {
                $agencyWallet = Wallet::where('owner_id', $agencyId)
                    ->where('owner_type', Agency::class)
                    ->where('type', 'main')
                    ->lockForUpdate() // Verrouillage pessimiste pour éviter les débits simultanés
                    ->first();

                if (!$agencyWallet) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Impossible d\'initialiser le guichet : Le portefeuille principal de l\'agence est introuvable.'
                    ], 404);
                }

                if ((float) $agencyWallet->balance < $initialBalance) {
                    return response()->json([
                        'success' => false,
                        'message' => "Provision insuffisante dans le coffre de l'agence. Solde disponible : " . number_format($agencyWallet->balance, 0, ',', ' ') . " XAF"
                    ], 422);
                }
            }

            // 2. Création du Guichet (Till)
            $till = Till::create([
                'agency_id'       => $agencyId,
                'name'            => $validated['name'],
                'code'            => strtoupper(trim($validated['code'])),
                'current_balance' => $initialBalance, // Dotation cash physique initiale
                'is_active'       => true,
                'status'          => 'close' // Le guichet est créé fermé par défaut
            ]);

            // 3. Création du Portefeuille Polymorphe rattaché au Guichet
            $tillWallet = Wallet::create([
                'uuid'          => (string) Str::uuid(),
                'owner_id'      => $till->id,
                'owner_type'    => Till::class,
                'wallet_number' => 'WLT-TIL-' . $till->code . '-' . date('Ymd'),
                'type'          => 'main',
                'currency'      => 'XAF',
                'balance'       => $initialBalance, // Dotation virtuelle initiale
                'is_active'     => true,
                'ledger_hash'   => null
            ]);

            // 4. Si dotation initiale > 0 : On effectue le mouvement d'écriture double-partie
            if ($initialBalance > 0 && $agencyWallet) {

                // Création d'une transaction système pivot pour l'audit trail
                $transaction = Transaction::create([
                    'uuid'             => (string) Str::uuid(),
                    'reference'        => 'DOT-' . strtoupper(Str::random(10)),
                    'type'             => 'adjustment', // Transfert de coffre à guichet
                    'status'           => 'completed',
                    'amount'           => $initialBalance,
                    'currency'         => 'XAF',
                    'source_agency_id' => $agencyId,
                    'source_till_id'   => $till->id,
                    'initiator_id'     => auth()->id(),
                    'completed_at'     => now(),
                    'sender_name'      => 'Coffre Agence',
                    'recipient_name'   => "Guichet {$till->name}"
                ]);

                // Écriture A : Débit du portefeuille de l'Agence
                $agencyBalanceBefore = (float) $agencyWallet->balance;
                $agencyWallet->decrement('balance', $initialBalance);

                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $agencyWallet->id,
                    'entry_type'     => 'debit',
                    'amount'         => $initialBalance,
                    'balance_before' => $agencyBalanceBefore,
                    'balance_after'  => $agencyWallet->fresh()->balance
                ]);

                // Écriture B : Crédit du portefeuille du nouveau Guichet (Déjà inclus dans le create, on trace l'historique)
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $tillWallet->id,
                    'entry_type'     => 'credit',
                    'amount'         => $initialBalance,
                    'balance_before' => 0.00,
                    'balance_after'  => $initialBalance
                ]);
            }

            DB::commit();

            $till->setRelation('wallet', $tillWallet);

            return response()->json([
                'success' => true,
                'message' => 'Guichet créé avec succès et doté depuis les réserves de l\'agence.',
                'data'    => $till
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::critical("Erreur d'initialisation financière de guichet : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur interne a annulé l\'initialisation comptable du guichet.'
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
                $agency->current_balance = $agencyWallet->balance;
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
