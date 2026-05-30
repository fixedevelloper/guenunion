<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\FeesTable;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemittanceService
{
    /**
     * Calculer les frais et taxes pour un montant et un type de transaction donnés.
     * Cette méthode peut être appelée de manière autonome depuis la caisse pour simulation.
     *
     * @param float $amount
     * @param string $type
     * @param int $sourceCountryId
     * @param int $destinationCountryId
     * @return array
     * @throws Exception
     */
    public function calculateFees(float $amount, string $type, int $sourceCountryId, int $destinationCountryId): array
    {
        // Recherche de la règle tarifaire correspondant exacte au corridor et au palier de montant
        $feeRule = FeesTable::where('transaction_type', $type)
            ->where('source_country_id', $sourceCountryId)
            ->where('destination_country_id', $destinationCountryId)
            ->where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->first();

        if (!$feeRule) {
            throw new \Exception("Aucun tarif configuré pour ce montant ou ce corridor international.");
        }

        // Calcul mathématique des frais
        $fixedFee = (float) $feeRule->fixed_fee;
        $percentageFee = $amount * ((float) $feeRule->percentage_fee / 100);
        $totalFees = $fixedFee + $percentageFee;

        // Calcul des taxes gouvernementales
        $taxes = $amount * ((float) $feeRule->tax_percentage / 100);

        return [
            'base_amount'           => $amount,
            'fixed_fee'             => $fixedFee,
            'percentage_fee'        => $percentageFee,
            'total_fees'            => $totalFees,
            'taxes'                 => $taxes,
            'total_amount_required' => $amount + $totalFees + $taxes
        ];
    }

    /**
     * Initie un transfert d'argent (Remittance) au guichet
     *
     * @param User $initiator
     * @param Agency $sourceAgency
     * @param array $data Contient les clés validées du contrôleur (sender_customer_id, recipient_email, etc.)
     * @return Transaction
     * @throws Exception
     */
    public function initiateRemittance(User $initiator, Agency $sourceAgency, array $data): Transaction
    {
        // 1. Validation de l'état opérationnel de l'agence émettrice
        if ($sourceAgency->status !== 'active' || !$sourceAgency->is_active) {
            throw new Exception("L'agence émettrice n'est pas autorisée à effectuer des transactions.");
        }

        $amount = (float) $data['amount'];

        // 2. Calcul des frais via le service (appel de la méthode publique isolée)
        $feesDetail = $this->calculateFees($amount, 'remittance', $sourceAgency->country_id, $data['destination_country_id']);

        $fees = (float) $feesDetail['total_fees'];
        $taxes = (float) $feesDetail['taxes'];
        $totalDebit = (float) $feesDetail['total_amount_required'];

        // 3. Exécution de la transaction financière atomique
        return DB::transaction(function () use ($initiator, $sourceAgency, $data, $amount, $fees, $taxes, $totalDebit, $feesDetail) {

            // Verrouillage pessimiste (FOR UPDATE) du portefeuille de l'agence
            $agencyWallet = Wallet::where('owner_type', Agency::class)
                ->where('owner_id', $sourceAgency->id)
                ->where('type', 'main')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$agencyWallet) {
                throw new Exception("Le portefeuille principal de l'agence est introuvable ou inactif.");
            }

            // Vérification minutieuse de la provision de l'agence
            if ((float) $agencyWallet->balance < $totalDebit) {
                throw new Exception("Le solde du coffre de l'agence est insuffisant pour couvrir le transfert et ses frais.");
            }

            // Verrouillage pessimiste (FOR UPDATE) du compte système Escrow de transit
            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $sourceAgency->country->currency_code ?? 'XAF')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet) {
                throw new Exception("Le compte système de transit (Escrow) est indisponible.");
            }

            // Génération des identifiants uniques et secrets de transaction (conforme aux contraintes de la table)
            $reference = 'TX-' . strtoupper(Str::random(4)) . '-' . date('ymdHis');
            $secureCode = $data['secure_code'] ?? $this->generateSecureCode();

            // A. Création de l'enregistrement maître de la Transaction (Alignée sur le nouveau schéma)
            $transaction = Transaction::create([
                'uuid'                   => (string) Str::uuid(), // Requis par votre contrainte unique
                'reference'              => $reference,
                'type'                   => 'remittance',
                'status'                 => 'completed', // Émis avec succès, en attente de décaissement ('paid')
                'amount'                 => $amount,
                'fees'                   => $fees,
                'taxes'                  => $taxes,
                'currency'               => $agencyWallet->currency,

                // --- PROFIL & SNAPSHOT EXPÉDITEUR ---
                'sender_customer_id'     => $data['sender_customer_id'] ?? null,
                'sender_name'            => $data['sender_name'],
                'sender_phone'           => $data['sender_phone'],

                // --- INFOS BÉNÉFICIAIRE ---
                'recipient_name'         => $data['recipient_name'],
                'recipient_phone'        => $data['recipient_phone'],
                'recipient_email'        => $data['recipient_email'] ?? null,
                'secure_code'            => $secureCode,

                // --- AFFILIATION DE L'AGENCE ÉMETTRICE ---
                'source_agency_id'       => $sourceAgency->id,
                'destination_agency_id'  => null, // Sera mis à jour lors du Payout (retrait)

                // --- IMMUABILITÉ GÉOGRAPHIQUE AU MOMENT DE L'ENVOI ---
                'sender_country_id'      => $sourceAgency->country_id,
                'sender_city_id'         => $data['sender_city_id'] ?? $sourceAgency->city_id,
                'recipient_country_id'   => $data['destination_country_id'], // Lié au pays cible
                'recipient_city_id'      => $data['recipient_city_id'] ?? null, // Optionnel à l'émission

                // --- ACTEUR TECHNIQUE & TIMESTAMPS ---
                'initiator_id'           => $initiator->id,
                'completed_at'           => now(),
                'description'            => "Émission de mandat cash au guichet par le caissier : {$initiator->username}",
                'metadata'               => [
                    'fees_breakdown'     => $feesDetail,
                    'device_context'     => [
                        'ip'         => request()->ip(),
                        'user_agent' => request()->userAgent()
                    ]
                ]
            ]);

            // B. OPÉRATION DE DÉBIT : Portefeuille de l'agence émettrice
            $agencyBalanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->balance = $agencyBalanceBefore - $totalDebit;
            $agencyWallet->save();

            // Calcul de l'empreinte de sécurité pour la ligne de débit
            $agencySignature = $this->generateLedgerSignature(
                $agencyWallet->id,
                $totalDebit,
                'debit',
                $agencyBalanceBefore,
                (float) $agencyWallet->balance
            );

            TransactionEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'debit',
                'amount'         => $totalDebit,
                'balance_before' => $agencyBalanceBefore,
                'balance_after'  => $agencyWallet->balance,
                'row_signature'  => $agencySignature
            ]);

            // C. OPÉRATION DE CRÉDIT : Compte Système de Transit (Escrow)
            $escrowBalanceBefore = (float) $escrowWallet->balance;
            $escrowWallet->balance = $escrowBalanceBefore + $totalDebit;
            $escrowWallet->save();

            // Calcul de l'empreinte de sécurité pour la ligne de crédit
            $escrowSignature = $this->generateLedgerSignature(
                $escrowWallet->id,
                $totalDebit,
                'credit',
                $escrowBalanceBefore,
                (float) $escrowWallet->balance
            );

            TransactionEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id'      => $escrowWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $totalDebit,
                'balance_before' => $escrowBalanceBefore,
                'balance_after'  => $escrowWallet->balance,
                'row_signature'  => $escrowSignature
            ]);

            // D. Mise à jour de la balance de contrôle de l'entité agence
            // Correction : Utilisation du nom de colonne exact défini dans votre contrôleur ('current_balance')
            $sourceAgency->decrement('current_balance', $totalDebit);

            return $transaction;
        });
    }
    /**
     * Valider et décaisser un retrait de mandat cash au guichet d'une agence réceptrice.
     * (Adapté pour la conformité KYC et l'intégration du formulaire Next.js)
     *
     * @param User $initiator Le caissier payeur
     * @param Agency $destinationAgency L'agence où se présente le bénéficiaire
     * @param array $data Données validées du formulaire (reference, secure_code, kyc...)
     * @return Transaction
     * @throws Exception
     */
    public function payoutRemittance(User $initiator, Agency $destinationAgency, array $data): Transaction
    {
        // 1. Validation de l'état opérationnel de l'agence distributrice
        if ($destinationAgency->status !== 'active' || !$destinationAgency->is_active) {
            throw new Exception("L'agence distributrice n'est pas autorisée à effectuer des paiements.");
        }

        // 2. Exécution de la transaction financière atomique
        return DB::transaction(function () use ($initiator, $destinationAgency, $data) {

            // Double verrouillage de sécurité : Recherche par Référence (MTCN) ET Code Secret
            // Utilise le verrouillage pessimiste (FOR UPDATE) pour éviter le "Double Spending"
            $transaction = Transaction::where('reference', trim($data['reference']))
                ->where('secure_code', trim($data['secure_code']))
                ->where('type', 'remittance')
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                throw new Exception("Mandat introuvable. La référence ou le code secret est incorrect.");
            }

            // Sécurité critique : Le mandat doit être disponible, pas encore payé
            if ($transaction->status !== 'completed') {
                if ($transaction->status === 'paid') {
                    throw new Exception("Ce mandat a déjà été payé au bénéficiaire.");
                }
                throw new Exception("Ce mandat n'est pas disponible pour un retrait (Statut actuel: {$transaction->status}).");
            }

            // Note : Le contrôle de conformité anti-fraude AML/KYC sur le numéro de téléphone
            // peut être optionnel ou croisé avec le nom si la pièce d'identité est valide.
            // Si tu souhaites le maintenir via la requête initiale, assure-toi que le front envoie le téléphone.

            $amountToPay = (float) $transaction->amount;

            // Le montant total qui doit quitter le compte de transit inclut le principal + les frais + taxes collectés à l'émission
            $totalInTransit = (float) ($amountToPay + $transaction->fees + $transaction->taxes);

            // Verrouillage pessimiste du compte de transit système (Escrow) dans la bonne devise
            $escrowWallet = Wallet::where('type', 'escrow')
                ->where('currency', $transaction->currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            // Verrouillage pessimiste du portefeuille principal de l'agence de destination
            $agencyWallet = Wallet::where('owner_type', Agency::class)
                ->where('owner_id', $destinationAgency->id)
                ->where('type', 'main')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$escrowWallet || !$agencyWallet) {
                throw new Exception("Erreur d'accès aux livres comptables ou portefeuilles inactifs.");
            }

            // --- ÉCRITURES COMPTABLES COMPENSATOIRES ---

            // A. OPÉRATION DE DÉBIT : Compte de transit Système (Escrow)
            $escrowBalanceBefore = (float) $escrowWallet->balance;
            $escrowWallet->balance = $escrowBalanceBefore - $totalInTransit;
            $escrowWallet->save();

            // Empreinte cryptographique du débit Escrow
            $escrowSignature = $this->generateLedgerSignature(
                $escrowWallet->id,
                $totalInTransit,
                'debit',
                $escrowBalanceBefore,
                (float) $escrowWallet->balance
            );

            TransactionEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id'      => $escrowWallet->id,
                'entry_type'     => 'debit',
                'amount'         => $totalInTransit,
                'balance_before' => $escrowBalanceBefore,
                'balance_after'  => $escrowWallet->balance,
                'row_signature'  => $escrowSignature
            ]);

            // B. OPÉRATION DE CRÉDIT : Portefeuille de l'agence distributrice (Compensation virtuelle)
            $agencyBalanceBefore = (float) $agencyWallet->balance;
            $agencyWallet->balance = $agencyBalanceBefore + $amountToPay;
            $agencyWallet->save();

            // Empreinte cryptographique du crédit de l'agence
            $agencySignature = $this->generateLedgerSignature(
                $agencyWallet->id,
                $amountToPay,
                'credit',
                $agencyBalanceBefore,
                (float) $agencyWallet->balance
            );

            TransactionEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id'      => $agencyWallet->id,
                'entry_type'     => 'credit',
                'amount'         => $amountToPay,
                'balance_before' => $agencyBalanceBefore,
                'balance_after'  => $agencyWallet->balance,
                'row_signature'  => $agencySignature
            ]);

            // C. Clôture définitive du mandat avec enregistrement des pièces KYC du bénéficiaire
            $transaction->update([
                'status'                => 'paid', // Passage immédiat au statut final bloquant
                'destination_agency_id' => $destinationAgency->id,
                'recipient_country_id'  => $destinationAgency->country_id,
                'recipient_city_id'     => $destinationAgency->city_id,
                'completed_at'          => now(),

                // Traçabilité de la pièce d'identité vérifiée au comptoir
                'recipient_id_type'     => $data['recipient_id_type'],
                'recipient_id_number'   => strtoupper(trim($data['recipient_id_number'])),
                'recipient_id_expiry'   => $data['recipient_id_expiry'],

                // ID du caissier qui a procédé au paiement
                'payout_user_id'        => $initiator->id,

                'description'           => $transaction->description . " | Payé à l'agence [" . $destinationAgency->name . "] par le caissier: " . $initiator->name
            ]);

            // Incrémentation du solde physique (physique cash out) de l'agence réceptrice
            $destinationAgency->increment('current_balance', $amountToPay);

            return $transaction;
        });
    }
    /**
     * Générer un code secret de retrait unique immuable à 12 chiffres (Format Standard Express Union)
     */
    private function generateSecureCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 3; $i++) {
                $code .= str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
            }
            $exists = Transaction::where('secure_code', $code)->exists();
        } while ($exists);

        return $code;
    }

    /**
     * Générer une signature de sécurité HMAC pour s'assurer que personne ne modifie les soldes en base de données.
     * Déporte la logique vers le LedgerService centralisé.
     */
    private function generateLedgerSignature(
        int $walletId,
        float $amount,
        string $type,
        float $balanceBefore,
        float $balanceAfter
    ): string {
        return app(LedgerService::class)->generateSignature(
            $walletId,
            $amount,
            $type,
            $balanceBefore,
            $balanceAfter
        );
    }
}

/**
 * Fonction d'aide pour nettoyer les chaînes de numéros de téléphone (à placer dans un helper global)
 */
if (!function_exists('clean_phone')) {
    function clean_phone($phone) {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
