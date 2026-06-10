<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustCountryWalletRequest;
use App\Models\Agency;
use App\Models\Country;
use App\Models\SystemAuditLog;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CountryController extends Controller
{
    protected LedgerService $ledgerService;

    /**
     * Injection du LedgerService pour sécuriser cryptographiquement les mouvements de coffre.
     * @param LedgerService $ledgerService
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Country::with(['cities', 'mainWallet'])
                ->orderBy('name', 'asc')
                ->get()
        ], 200);
    }
    public function countries()
    {
        return response()->json([
            'success' => true,
            'data' => Country::query()->orderBy('name', 'asc')->get()
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:countries,name',
            'code' => 'required|string|size:2|unique:countries,code',
            'currency_code' => 'required|string|max:5',
            'currency_symbol' => 'required|string|max:10',
            'phone_prefix' => 'required|string|max:10|unique:countries,phone_prefix',
            'can_cash_in' => 'boolean',
            'can_cash_out' => 'boolean',
            'is_active' => 'boolean'
        ]);

        $country = Country::create($validated);

        return response()->json([
            'success' => true,
            'data' => $country
        ], 201);
    }

    public function toggleStatus(Request $request, $uuid)
    {
        $request->validate([
            'field' => 'required|in:is_active,can_cash_in,can_cash_out'
        ]);

        $country = Country::where('uuid', $uuid)->firstOrFail();
        $field = $request->field;

        // Inversion dynamique du champ booléen ciblé par Next.js
        $country->update([
            $field => !$country->$field
        ]);

        return response()->json([
            'success' => true,
            'message' => "Configuration mise à jour pour le champ : {$field}"
        ], 200);
    }

    /**
     * Ajuster manuellement le solde central d'un pays (Super Admin)
     * @param AdjustCountryWalletRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function adjustWallet(AdjustCountryWalletRequest $request, string $uuid): JsonResponse
    {
        $action = $request->input('action'); // 'credit' ou 'debit'
        $amount = (float) $request->input('amount');
        $note   = $request->input('reference_note');

        // 1. Alignement et sécurité des rôles Spatie
        if (!Auth::user()->hasAnyRole(['super_admin'])) {
            Log::warning("Tentative d'accès non autorisée à l'ajustement de trésorerie nationale", [
                'user_id'      => Auth::id(),
                'user_email'   => Auth::user()->email ?? 'Inconnu',
                'country_uuid' => $uuid,
                'ip'           => request()->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Action refusée. Niveau d\'accréditation insuffisant.'
            ], 403);
        }

        $operator = Auth::user();

        // Log de début de procédure informatique
        Log::info("Début de la procédure d'ajustement de portefeuille national", [
            'operator_id'  => $operator->id,
            'country_uuid' => $uuid,
            'action'       => $action,
            'amount'       => $amount
        ]);

        try {
            // Récupérer le pays cible
            $country = Country::where('uuid', $uuid)->firstOrFail();

            $transaction = DB::transaction(function () use ($operator, $country, $amount, $action, $note) {

                // 2. Verrouillage du portefeuille virtuel principal du PAYS (Owner: Country)
                $countryWallet = Wallet::where('owner_type', Country::class)
                    ->where('owner_id', $country->id)
                    ->where('type', 'main')
                    ->lockForUpdate()
                    ->first();

                if (!$countryWallet || !$countryWallet->is_active) {
                    Log::error("Échec ajustement : Portefeuille national introuvable ou inactif", ['country_id' => $country->id]);
                    throw new Exception("Le portefeuille central du pays est introuvable, suspendu ou non initialisé.", 422);
                }

                // 3. Verrouillage du portefeuille de contrepartie du Système (Escrow)
                $systemMasterWallet = Wallet::where('type', 'escrow')
                    ->where('currency', $countryWallet->currency)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$systemMasterWallet) {
                    Log::error("Échec ajustement : Trésorerie centrale système introuvable", ['currency' => $countryWallet->currency]);
                    throw new Exception("Compte de contrepartie technique (escrow) indisponible pour la devise {$countryWallet->currency}.", 422);
                }

                // 4. CRÉATION DE LA TRANSACTION PARENTE (Audit principal)
                $reference = 'ADJ-NAT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $transaction = Transaction::create([
                    'uuid'         => (string) Str::uuid(),
                    'reference'    => $reference,
                    'type'         => 'adjustment',
                    'status'       => 'initiated',
                    'amount'       => $amount,
                    'fees'         => 0,
                    'taxes'        => 0,
                    'currency'     => $countryWallet->currency,
                    'initiator_id' => $operator->id,
                    'description'  => $note,
                    'metadata'     => [
                        'action_type'  => $action,
                        'triggered_by' => $operator->name,
                        'scope'        => 'national_balance'
                    ]
                ]);

                $countryBalanceBefore = (float) $countryWallet->balance;
                $systemBalanceBefore  = (float) $systemMasterWallet->balance;

                // 5. Mouvements des balances avec garde-fous
                if ($action === 'credit') {
                    if ($systemBalanceBefore < $amount) {
                        Log::warning("Échec ajustement : Trésorerie système globale insuffisante pour émettre ce crédit", [
                            'reference'      => $reference,
                            'system_balance' => $systemBalanceBefore,
                            'requested'      => $amount
                        ]);
                        throw new Exception("La réserve globale du système est insuffisante pour allouer cette ligne de provision.");
                    }

                    $countryWallet->increment('balance', $amount);
                    $systemMasterWallet->decrement('balance', $amount);

                    $countryEntryType = 'credit';
                    $systemEntryType  = 'debit';
                } else {
                    if ($countryBalanceBefore < $amount) {
                        Log::warning("Échec ajustement : Solde du pays insuffisant pour décrémentation", [
                            'reference'       => $reference,
                            'country_balance' => $countryBalanceBefore,
                            'requested'       => $amount
                        ]);
                        throw new Exception("Le solde central actuel du pays est insuffisant pour effectuer ce retrait de fonds.");
                    }

                    $countryWallet->decrement('balance', $amount);
                    $systemMasterWallet->increment('balance', $amount);

                    $countryEntryType = 'debit';
                    $systemEntryType  = 'credit';
                }

                // 6. DOUBLE ÉCRITURE COMPTABLE DANS LE GRAND LIVRE (Ledger)

                // Écriture Pays (Cible)
                $countrySignature = $this->ledgerService->generateSignature(
                    $countryWallet->id, $amount, $countryEntryType, $countryBalanceBefore, (float) $countryWallet->fresh()->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $countryWallet->id,
                    'entry_type'     => $countryEntryType,
                    'amount'         => $amount,
                    'balance_before' => $countryBalanceBefore,
                    'balance_after'  => $countryWallet->fresh()->balance,
                    'row_signature'  => $countrySignature
                ]);

                // Écriture Système (Contrepartie comptable)
                $systemSignature = $this->ledgerService->generateSignature(
                    $systemMasterWallet->id, $amount, $systemEntryType, $systemBalanceBefore, (float) $systemMasterWallet->fresh()->balance
                );
                TransactionEntry::create([
                    'uuid'           => (string) Str::uuid(),
                    'transaction_id' => $transaction->id,
                    'wallet_id'      => $systemMasterWallet->id,
                    'entry_type'     => $systemEntryType,
                    'amount'         => $amount,
                    'balance_before' => $systemBalanceBefore,
                    'balance_after'  => $systemMasterWallet->fresh()->balance,
                    'row_signature'  => $systemSignature
                ]);

                // 7. Synchronisation du champ de lecture rapide (si vous dupliquez le solde dans la table countries)
                $country->update([
                    'wallet_balance' => $countryWallet->fresh()->balance
                ]);

                // 8. CLÔTURE DE LA TRANSACTION
                $transaction->update([
                    'status'       => 'completed',
                    'completed_at' => now()
                ]);

                // 9. JOURNALISATION DE SÉCURITÉ (Audit Log système consultable)
                SystemAuditLog::create([
                    'uuid'       => (string) Str::uuid(),
                    'user_id'    => $operator->id,
                    'country_id' => $country->id, // Adapté pour lier au pays
                    'event_type' => 'COUNTRY.WALLET_ADJUSTMENT',
                    'severity'   => $action === 'credit' ? 'info' : 'warning',
                    'message'    => "Ajustement de la trésorerie nationale du pays {$country->name} via transaction #{$reference}.",
                    'payload'    => [
                        'transaction_uuid' => $transaction->uuid,
                        'reference'        => $reference,
                        'action_type'      => $action,
                        'amount'           => $amount,
                        'reference_note'   => $note
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $transaction;
            });

            // Log de succès applicatif monolithique (Fichiers logs)
            Log::info("Ajustement monétaire national validé et signé avec succès", [
                'reference'   => $transaction->reference,
                'operator_id' => $operator->id,
                'country'     => $country->name,
                'amount'      => $amount
            ]);

            return response()->json([
                'success' => true,
                'message' => "Le portefeuille national a été ajusté, chiffré et enregistré sous la référence {$transaction->reference}.",
                'data'    => [
                    'reference' => $transaction->reference,
                    'status'    => $transaction->status,
                    'new_balance' => $country->fresh()->wallet_balance
                ]
            ], 200);

        } catch (Exception $e) {
            // Log d'erreur critique avec trace complète
            Log::error("Échec critique de l'ajustement du portefeuille national", [
                'message'      => $e->getMessage(),
                'operator_id'  => $operator->id ?? null,
                'country_uuid' => $uuid,
                'payload'      => $request->only(['action', 'amount', 'reference_note']),
                'trace'        => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
