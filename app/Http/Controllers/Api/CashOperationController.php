<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Till;
use App\Models\CashOperation;
use App\Models\Staff;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class CashOperationController extends Controller
{
    /**
     * TOLÉRANCE POUR LA COMPARAISON DE FLOTTANTS (en FCFA / Unités monétaires)
     */
    private const FLOAT_TOLERANCE = 0.01;

    /**
     * ÉTAT ACTUEL D'UNE CAISSE SPÉCIFIQUE (Utilisé par le Polling du Layout)
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Extraction du profil staff (via la table 'staff')
            $staff = Staff::with('agency.country')->where('user_id', $user->id)->first();

            if (!$staff || !$staff->agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune agence rattachée à votre profil opérateur.'
                ], 403);
            }

            $agency = $staff->agency;
            $till = null;

            // 2. Récupération de la caisse ciblée
            $tillId = $request->query('till_id');

            if ($tillId) {
                $till = Till::where('id', $tillId)->where('agency_id', $agency->id)->first();
            } else {
                $lastOpeningOp = CashOperation::where('agency_id', $agency->id)
                    ->where('staff_id', $staff->id)
                    ->where('type', 'opening')
                    ->orderByDesc('id')
                    ->first();

                if ($lastOpeningOp) {
                    $till = Till::find($lastOpeningOp->till_id);
                }
            }

            // Si aucune caisse n'est trouvée ou traçable dans l'historique de l'agent
            if (!$till) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'is_open'     => false,
                        'agency_name' => $agency->name,
                        'currency'    => $agency->country->currency_code ?? 'XAF',
                        'user'        => ['name' => $user->name, 'email' => $user->email]
                    ]
                ], 200);
            }

            // 3. Récupération du solde depuis le portefeuille polymorphe principal du guichet
            $tillWallet = Wallet::where('owner_id', $till->id)
                ->where('owner_type', Till::class)
                ->where('type', 'main')
                ->first();

            $currentBalance = $tillWallet ? (float) $tillWallet->balance : (float) $till->current_balance;

            // 🔍 ANALYSE DU JOURNAL DE CETTE CAISSE SPÉCIFIQUE
            $lastCycleOperation = CashOperation::where('till_id', $till->id)
                ->whereIn('type', ['opening', 'closing'])
                ->orderByDesc('id')
                ->first();

            $isOpen = $lastCycleOperation
                && $lastCycleOperation->type === 'opening'
                && $lastCycleOperation->staff_id === $staff->id;

            return response()->json([
                'success' => true,
                'data' => [
                    'is_open'         => (bool) $isOpen,
                    'agency_name'     => $agency->name,
                    'till_id'         => $till->id,
                    'till_name'       => $till->name,
                    'till_code'       => $till->code,
                    'current_balance' => $currentBalance,
                    'currency'        => $agency->country->currency_code ?? 'XAF',
                    'user'            => [
                        'name'  => $user->name,
                        'email' => $user->email
                    ]
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur statut caisse découplée : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Erreur de synchronisation de la session guichet."
            ], 500);
        }
    }

    /**
     * OUVERTURE DE CAISSE (D'UN GUICHET)
     */
    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'till_id'         => 'required|integer|exists:tills,id',
            'opening_balance' => 'required|numeric|min:0',
        ]);

        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            if (!$staff || !$staff->agency_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Rattachement agence introuvable."
                ], 403);
            }

            $agencyId = $staff->agency_id;
            $tillCode = '';

            $operation = DB::transaction(function () use ($user, $staff, $agencyId, $request, &$tillCode) {

                // 1. Lock pessimiste sur la caisse pour éviter les ouvertures simultanées
                $lockedTill = Till::where('id', $request->input('till_id'))
                    ->where('agency_id', $agencyId)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedTill) {
                    throw new Exception("Caisse introuvable ou non rattachée à votre agence.");
                }

                if ($lockedTill->status === 'open') {
                    throw new Exception("Ce guichet/caisse est déjà actif sous la responsabilité d'un opérateur.");
                }

                // 2. Lock pessimiste sur le portefeuille principal du guichet
                $tillWallet = Wallet::where('owner_id', $lockedTill->id)
                    ->where('owner_type', Till::class)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$tillWallet) {
                    throw new Exception("Configuration financière invalide : Portefeuille d'encaisse introuvable pour ce guichet.");
                }

                $tillCode = $lockedTill->code;
                $openingBalance = (float) $request->input('opening_balance');

                // Le solde théorique de référence est désormais extrait du portefeuille réel
                $theoreticalBalance = (float) $tillWallet->balance;
                $discrepancy = $openingBalance - $theoreticalBalance;

                // 3. Journalisation de l'ouverture
                $cashOp = CashOperation::create([
                    'uuid'        => (string) Str::uuid(),
                    'agency_id'   => $agencyId,
                    'till_id'     => $lockedTill->id,
                    'staff_id'    => $staff->id,
                    'type'        => 'opening',
                    'amount'      => $openingBalance,
                    'description' => "Ouverture de session guichet [{$lockedTill->code}] par {$user->name}. Solde comptable attendu : " . number_format($theoreticalBalance, 2, '.', ' ') . " XAF.",
                ]);

                // 4. Gestion et ajustement immédiat des Écarts d'ouverture
                if (abs($discrepancy) > self::FLOAT_TOLERANCE) {
                    CashOperation::create([
                        'uuid'        => (string) Str::uuid(),
                        'agency_id'   => $agencyId,
                        'till_id'     => $lockedTill->id,
                        'staff_id'    => $staff->id,
                        'type'        => 'adjustment',
                        'amount'      => abs($discrepancy),
                        'description' => $discrepancy > 0
                            ? "Écart d'ouverture positif (surplus de " . abs($discrepancy) . " XAF constaté sur le guichet [{$lockedTill->code}])."
                            : "Écart d'ouverture négatif (manquant de " . abs($discrepancy) . " XAF constaté sur le guichet [{$lockedTill->code}]).",
                    ]);
                }

                // 5. Aligner le portefeuille et la table de contrôle sur le solde d'ouverture déclaré
                $tillWallet->update([
                    'balance'   => $openingBalance,
                    'is_active' => true
                ]);

                $lockedTill->update([
                    'staff_id'        => $staff->id,
                    'status'          => 'open',
                    'current_balance' => $openingBalance,
                ]);

                $staff->update(['till_id' => $lockedTill->id]);

                return $cashOp;
            });

            return response()->json([
                'success' => true,
                'message' => "Le guichet {$tillCode} est désormais ouvert et placé sous votre responsabilité.",
                'data'    => $operation
            ], 201);

        } catch (Exception $e) {
            Log::error("Erreur ouverture caisse : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * CLÔTURE DE CAISSE (D'UN GUICHET)
* @param Request $request
* @return JsonResponse
*/
    public function close(Request $request): JsonResponse
    {
        $request->validate([
            'declared_balance' => 'required|numeric|min:0',
            'notes'            => 'nullable|string|max:500',
        ]);

        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            if (!$staff || !$staff->agency_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Rattachement agence manquant."
                ], 403);
            }

            $closingOperation = DB::transaction(function () use ($staff, $request) {

                // 1. Recherche de la dernière opération d'ouverture pour ce staff
                $lastOpening = CashOperation::where('agency_id', $staff->agency_id)
                    ->where('staff_id', $staff->id)
                    ->where('type', 'opening')
                    ->orderByDesc('id')
                    ->first();

                if (!$lastOpening) {
                    throw new Exception("Aucune opération d'ouverture de caisse n'a été trouvée pour votre profil.");
                }

                // 2. CORRECTION : Récupération et Verrouillage de l'objet Till complet via l'ID historique
                $lockedTill = Till::where('id', $lastOpening->till_id)
                    ->where('agency_id', $staff->agency_id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedTill) {
                    throw new Exception("Caisse introuvable ou non rattachée à votre agence.");
                }

                if ($lockedTill->status !== 'open') {
                    throw new Exception("Cette caisse n'est pas actuellement déclarée comme ouverte.");
                }

                logger($lockedTill);
                // Sécurité hiérarchique : Un opérateur ne peut pas fermer la caisse active d'un autre collègue
                if ($lockedTill->staff_id !== $staff->id) {
                   // throw new Exception("Action refusée : Vous n'êtes pas le gestionnaire assigné à cette caisse.");
                }

                // 3. Lock pessimiste sur le portefeuille principal du guichet
                $tillWallet = Wallet::where('owner_id', $lockedTill->id)
                    ->where('owner_type', Till::class)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$tillWallet) {
                    throw new Exception("Erreur financière : Le portefeuille lié à cette caisse est inaccessible.");
                }

                // Déduction des écarts basés sur le solde réel sécurisé du portefeuille
                $theoretical = (float) $tillWallet->balance;
                $declared    = (float) $request->input('declared_balance');
                $difference  = $declared - $theoretical;

                // Justification obligatoire en cas d'écart
                if (abs($difference) > self::FLOAT_TOLERANCE && empty($request->input('notes'))) {
                    throw new Exception("Une justification écrite (Notes) est obligatoire en cas d'écart sur ce guichet.");
                }

                // 4. Enregistrement de l'action de fermeture
                $operation = CashOperation::create([
                    'agency_id'   => $staff->agency_id,
                    'till_id'     => $lockedTill->id,
                    'staff_id'    => $staff->id,
                    'type'        => 'closing',
                    'amount'      => $declared,
                    'description' => "Clôture guichet [{$lockedTill->code}] | Solde réel attendu : " .
                        number_format($theoretical, 2, '.', ' ') . ' | Note : ' .
                        ($request->input('notes') ?? 'Aucune note fournie.'),
                ]);

                // 5. Enregistrement de l'écart de clôture si existant
                if (abs($difference) > self::FLOAT_TOLERANCE) {
                    CashOperation::create([
                        'agency_id'   => $staff->agency_id,
                        'till_id'     => $lockedTill->id,
                        'staff_id'    => $staff->id,
                        'type'        => 'adjustment',
                        'amount'      => abs($difference),
                        'description' => $difference > 0
                            ? "Surplus de caisse constaté à la clôture du guichet [{$lockedTill->code}] d'un montant de " . abs($difference) . " XAF."
                            : "Manquant de caisse constaté à la clôture du guichet [{$lockedTill->code}] d'un montant de " . abs($difference) . " XAF.",
                    ]);
                }

                // 6. Réinitialisation et mise à jour du portefeuille et du guichet
                $tillWallet->update([
                    'balance' => $declared
                ]);

                $lockedTill->update([
                    'staff_id'        => null,
                    'status'          => 'close',
                    'current_balance' => $declared,
                ]);

                // Désassigner également le guichet sur le profil du staff pour libérer sa session
                $staff->update(['till_id' => null]);

                return $operation;
            });

            return response()->json([
                'success' => true,
                'message' => 'Caisse du guichet clôturée et libérée avec succès.',
                'data'    => $closingOperation
            ], 201);

        } catch (Exception $e) {
            Log::error("Erreur clôture caisse : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * RÉCUPÉRER TOUTES LES CAISSES DISPONIBLES DE L'AGENCE
     */
    public function getAgencyTills(): JsonResponse
    {
        $staff = Staff::where('user_id', Auth::id())->first();

        if (!$staff || !$staff->agency_id) {
            return response()->json(['success' => false, 'message' => 'Aucune agence rattachée à votre profil.'], 403);
        }

        // On charge la relation polymorphe du portefeuille principal pour remonter les vrais soldes comptables
        $tills = Till::where('agency_id', $staff->agency_id)
            ->where('is_active', true)
            ->with(['wallet' => function ($query) {
                $query->where('type', 'main');
            }])
            ->get();

        // Formater la collection pour préserver l'intégrité de la réponse attendue par Next.js
        $formattedTills = $tills->map(function ($till) {
            return [
                'id'              => $till->id,
                'name'            => $till->name,
                'code'            => $till->code,
                'current_balance' => $till->wallet ? (float) $till->wallet->balance : (float) $till->current_balance
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $formattedTills
        ]);
    }
}
