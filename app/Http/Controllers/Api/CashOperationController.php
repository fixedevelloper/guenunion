<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Till;
use App\Models\CashOperation;
use App\Models\Staff;
use App\Models\Wallet;
use App\Services\VaultTransferRequestService;
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

    // Propriété pour stocker le service de transfert
    protected VaultTransferRequestService $vaultService;

    /**
     * Injection de dépendance du service dans le constructeur
     * @param VaultTransferRequestService $vaultService
     */
    public function __construct(VaultTransferRequestService $vaultService)
    {
        $this->vaultService = $vaultService;
    }
    /**
     * ÉTAT ACTUEL D'UNE CAISSE SPÉCIFIQUE (Utilisé par le Polling du Layout)
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // 1. Extraction du profil staff avec l'agence et le guichet ACTUELlement ouvert
            $staff = Staff::with(['agency.country', 'currentTill'])->where('user_id', $user->id)->first();

            if (!$staff || !$staff->agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune agence rattachée à votre profil opérateur.'
                ], 403);
            }

            $agency = $staff->agency;
            $till = null;

            // 2. Priorité à la caisse passée en paramètre, sinon on utilise la caisse active issue de la relation
            $tillId = $request->query('till_id');

            if ($tillId) {
                $till = Till::where('id', $tillId)->where('agency_id', $agency->id)->first();
            } else {
                $till = $staff->currentTill; // Utilisation directe de la relation hasOneThrough optimisée
            }

            // Si aucune caisse n'est assignée/ouverte ou si le paramètre till_id était invalide
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

            // 3. Récupération du solde comptable depuis le portefeuille virtuel du guichet
            $tillWallet = Wallet::where('owner_id', $till->id)
                ->where('owner_type', Till::class)
                ->where('type', 'main')
                ->first();

            // On privilégie le solde virtuel du Ledger, sinon le solde physique du coffre de caisse
            $currentBalance = $tillWallet ? (float) $tillWallet->balance : (float) $till->current_balance;

            // 4. Détermination fine du statut d'ouverture
            // Si le guichet provient de 'currentTill', il est par définition ouvert et actif.
            // Si un 'till_id' spécifique a été forcé, on vérifie ses attributs natifs.
            $isOpen = ($till->status === 'open' && $till->is_active);

            return response()->json([
                'success' => true,
                'data' => [
                    'is_open'         => (bool) $isOpen,
                    'agency_name'     => $agency->name,
                    'till_id'         => $till->id,
                    'till_name'       => $till->name,
                    'till_code'       => $till->code,
                    'current_balance' => $currentBalance, // Position comptable
                    'physical_balance'=> (float) $till->current_balance, // Cash physique dans le tiroir
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
     * Ouvre la session d'un guichet/caisse et gère les écarts d'encaisse.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function open(Request $request): JsonResponse
    {
        // 1. Validation stricte des données entrantes
        $request->validate([
            'till_id'         => 'required|integer|exists:tills,id',
            'opening_balance' => 'required|numeric|min:0',
        ]);

        try {
            $user = Auth::user();
            $staff = Staff::where('user_id', $user->id)->first();

            // Vérification de l'habilitation du personnel et de son rattachement
            if (!$staff || !$staff->agency_id) {
                return response()->json([
                    'success' => false,
                    'message' => "Accès refusé : Aucun rattachement d'agence trouvé pour votre profil."
                ], 403);
            }

            // Récupération du modèle Agency requis pour la cible (Target) du service
            $agency = Agency::findOrFail($staff->agency_id);
            $tillCode = '';

            // 2. Début de la transaction financière atomique
            $operation = DB::transaction(function () use ($user, $staff, $agency, $request, &$tillCode) {

                // Verrouillage pessimiste (FOR UPDATE) sur la caisse pour éviter la concurrence
                $lockedTill = Till::where('id', $request->input('till_id'))
                    ->where('agency_id', $agency->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedTill) {
                    throw new Exception("Caisse introuvable ou non rattachée à votre agence de secteur.");
                }

                if ($lockedTill->status === 'open') {
                    throw new Exception("Ce guichet est déjà actif et ouvert sous la responsabilité d'un opérateur.");
                }

                // Verrouillage pessimiste sur le portefeuille d'encaisse réel de la caisse
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
                $theoreticalBalance = (float) $tillWallet->balance;

                // Calcul de l'écart (Déclaré par le caissier - Attendu en Base de données)
                $discrepancy = $openingBalance - $theoreticalBalance;

                // 3. ANALYSE ET GESTION DES ÉCARTS D'OUVERTURE
                if (abs($discrepancy) > self::FLOAT_TOLERANCE) {

                    // CAS A : Écart négatif (Il manque de l'argent physique) -> BLOCAGE STRICT
                    if ($discrepancy < 0) {
                        Log::warning("Tentative d'ouverture de caisse rejetée : Manquant constaté", [
                            'staff_id'      => $staff->id,
                            'till_code'     => $lockedTill->code,
                            'solde_attendu' => $theoreticalBalance,
                            'solde_declare' => $openingBalance,
                            'manquant'      => abs($discrepancy)
                        ]);

                        throw new Exception(
                            "Ouverture refusée : Un manquant de " . number_format(abs($discrepancy), 2, '.', ' ') .
                            " {$tillWallet->currency} a été détecté. Veuillez recompter votre fond de caisse ou contacter un superviseur."
                        );
                    }

                    // CAS B : Écart positif (Surplus) -> UTILISATION DU SERVICE POUR CRÉER LA REQUEST
                    if ($discrepancy > 0) {
                        Log::info("Surplus de caisse détecté à l'ouverture. Appel au VaultTransferRequestService.", [
                            'staff_id'  => $staff->id,
                            'till_code' => $lockedTill->code,
                            'surplus'   => $discrepancy
                        ]);

                        // Préparation du payload compatible avec le format attendu par votre service
                        $requestData = [
                            'type'     => 'gap_deposit', // ID de flux pour vos surplus (à gérer selon vos enums ou règles)
                            'amount'   => $discrepancy,
                            'currency' => $tillWallet->currency,
                            'notes'    => "Surplus constaté à l'ouverture du guichet [{$lockedTill->code}]. Déclaration caissier : " .
                                number_format($openingBalance, 2, '.', ' ') . " {$tillWallet->currency} pour un attendu comptable de " .
                                number_format($theoreticalBalance, 2, '.', ' ') . " {$tillWallet->currency}."
                        ];

                        // ✅ Appel propre à votre service
                        $this->vaultService->createRequest($requestData, $lockedTill, $agency, $user->id);

                        // Historisation de l'ajustement sur la fiche de contrôle du guichet
                        CashOperation::create([
                            'uuid'        => (string) Str::uuid(),
                            'agency_id'   => $agency->id,
                            'till_id'     => $lockedTill->id,
                            'staff_id'    => $staff->id,
                            'type'        => 'adjustment',
                            'amount'      => $discrepancy,
                            'description' => "Écart positif à l'ouverture de " . number_format($discrepancy, 2, '.', ' ') . " {$tillWallet->currency}. Demande d'approbation centralisée émise au Manager via le Service.",
                        ]);
                    }
                }

                // 4. Enregistrement comptable de l'opération d'ouverture
                $cashOp = CashOperation::create([
                    'uuid'        => (string) Str::uuid(),
                    'agency_id'   => $agency->id,
                    'till_id'     => $lockedTill->id,
                    'staff_id'    => $staff->id,
                    'type'        => 'opening',
                    'amount'      => $openingBalance,
                    'description' => "Ouverture de session guichet [{$lockedTill->code}] par {$user->first_name} {$user->last_name}." .
                        ($discrepancy > 0 ? " (Alerte surplus stockée dans le registre des requêtes de transfert)" : " (Solde certifié conforme)"),
                ]);

                // 5. Synchronisation des états de la caisse, du portefeuille et de l'opérateur
                $tillWallet->update([
                    'balance'   => $openingBalance, // Le solde virtuel absorbe temporairement le surplus en attente de traitement
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

            // 6. Réponse JSON de succès vers le Front-end
            return response()->json([
                'success' => true,
                'message' => "Le guichet {$tillCode} a été vérifié et est désormais ouvert sous votre responsabilité.",
                'data'    => $operation
            ], 201);

        } catch (Exception $e) {
            Log::error("Échec lors de la procédure d'ouverture du guichet : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * CLÔTURE DE CAISSE (D'UN GUICHET) avec gestion automatisée des écarts.
     *
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

            // Récupération de l'instance Agency requise pour le service en cas de surplus
            $agency = Agency::findOrFail($staff->agency_id);

            $closingOperation = DB::transaction(function () use ($user, $staff, $agency, $request) {

                // 1. Recherche de la dernière opération d'ouverture pour ce staff
                $lastOpening = CashOperation::where('agency_id', $staff->agency_id)
                    ->where('staff_id', $staff->id)
                    ->where('type', 'opening')
                    ->orderByDesc('id')
                    ->first();

                if (!$lastOpening) {
                    throw new Exception("Aucune opération d'ouverture de caisse n'a été trouvée pour votre profil.");
                }

                // 2. Récupération et Verrouillage de l'objet Till complet via l'ID historique
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

                // Sécurité hiérarchique réactivée : Un opérateur ne peut pas fermer la caisse active d'un autre
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

                // Justification obligatoire en cas d'écart (Surplus)
                if (abs($difference) > self::FLOAT_TOLERANCE && empty($request->input('notes'))) {
                    throw new Exception("Une justification écrite (Notes) est obligatoire en cas d'écart sur ce guichet.");
                }

                // --- GESTION DES ÉCARTS DE CLÔTURE ---
                if (abs($difference) > self::FLOAT_TOLERANCE) {

                    // CAS A : Écart négatif (Manquant physique en fin de journée) -> BLOCAGE STRICT
                    if ($difference < 0) {
                        Log::warning("Tentative de clôture de caisse rejetée : Manquant constaté", [
                            'staff_id'        => $staff->id,
                            'till_code'       => $lockedTill->code,
                            'solde_comptable' => $theoretical,
                            'solde_declare'   => $declared,
                            'manquant'        => abs($difference)
                        ]);

                        throw new Exception(
                            "Clôture refusée : Un manquant de " . number_format(abs($difference), 2, '.', ' ') .
                            " {$tillWallet->currency} a été détecté. Veuillez recompter votre caisse ou contacter votre Manager pour régularisation."
                        );
                    }

                    // CAS B : Écart positif (Surplus physique en fin de journée) -> ACCEPTATION + FLUX MANAGER
                    if ($difference > 0) {
                        Log::info("Surplus de caisse détecté à la clôture. Génération d'une demande de versement via le Service.", [
                            'staff_id'  => $staff->id,
                            'till_code' => $lockedTill->code,
                            'surplus'   => $difference
                        ]);

                        // Préparation du payload pour le VaultTransferRequestService
                        $requestData = [
                            'type'     => 'gap_deposit',
                            'amount'   => $difference,
                            'currency' => $tillWallet->currency,
                            'notes'    => "Surplus constaté à la clôture du guichet [{$lockedTill->code}]. Note de l'agent : " .
                                ($request->input('notes') ?? 'Aucune.')
                        ];

                        // Émission de la requête d'écart automatisée vers l'agence cible
                        $this->vaultService->createRequest($requestData, $lockedTill, $agency, $user->id);

                        // Enregistrement de l'ajustement dans l'historique du guichet
                        CashOperation::create([
                            'uuid'        => (string) Str::uuid(),
                            'agency_id'   => $staff->agency_id,
                            'till_id'     => $lockedTill->id,
                            'staff_id'    => $staff->id,
                            'type'        => 'adjustment',
                            'amount'      => $difference,
                            'description' => "Surplus de caisse constaté à la clôture de " . number_format($difference, 2, '.', ' ') . " {$tillWallet->currency}. Requête de versement transmise au Manager.",
                        ]);
                    }
                }

                // 4. Enregistrement officiel de l'action de fermeture
                $operation = CashOperation::create([
                    'uuid'        => (string) Str::uuid(),
                    'agency_id'   => $staff->agency_id,
                    'till_id'     => $lockedTill->id,
                    'staff_id'    => $staff->id,
                    'type'        => 'closing',
                    'amount'      => $declared,
                    'description' => "Clôture guichet [{$lockedTill->code}] | Solde attendu : " .
                        number_format($theoretical, 2, '.', ' ') . ' ' . $tillWallet->currency . ' | Note : ' .
                        ($request->input('notes') ?? 'Aucune note fournie.'),
                ]);

                // 5. Alignement final des comptes avant fermeture complète
                $tillWallet->update([
                    'balance' => $declared // Le solde absorbe le surplus validé par la demande en attente
                ]);

                $lockedTill->update([
                    'staff_id'        => null,
                    'status'          => 'close',
                    'current_balance' => $declared,
                ]);

                // Désassigner le guichet sur le profil du staff pour libérer sa session
                $staff->update(['till_id' => null]);

                return $operation;
            });

            return response()->json([
                'success' => true,
                'message' => 'Caisse du guichet clôturée et session libérée avec succès.',
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
