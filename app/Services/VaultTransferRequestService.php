<?php

namespace App\Services;

use App\Models\VaultTransferRequest;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\TransactionEntry;
use App\Models\Till;
use App\Models\Agency;
use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class VaultTransferRequestService
{
    /**
     * Initialise une demande de mouvement de fonds polymorphe.
     * @param array $data
     * @param Model $requester
     * @param Model $target
     * @param int $creatorId
     * @return VaultTransferRequest
     */
    public function createRequest(array $data, Model $requester, Model $target, int $creatorId): VaultTransferRequest
    {
        return VaultTransferRequest::create([
            'uuid'           => (string) Str::uuid(),
            'requester_id'   => $requester->id,
            'requester_type' => get_class($requester),
            'target_id'      => $target->id,
            'target_type'    => get_class($target),
            'type'           => $data['type'], // 'supply' ou 'deposit'
            'amount'         => (float) $data['amount'],
            'currency'       => $data['currency'] ?? 'XAF',
            'status'         => 'pending',
            'creator_id'     => $creatorId,
            'notes'          => $data['notes'] ?? null,
        ]);
    }

    /**
     * Traite (Approuve ou Rejette) une demande de transfert de fonds avec exécution comptable.
     * @param int $requestId
     * @param string $action
     * @param int $validatorId
     * @param string|null $rejectionReason
     * @return bool
     */
    public function processRequest(int $requestId, string $action, int $validatorId, ?string $rejectionReason = null): bool
    {
        return DB::transaction(function () use ($requestId, $action, $validatorId, $rejectionReason) {

            // 1. Verrouillage de la demande pour empêcher les traitements concurrents
            $request = VaultTransferRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== 'pending') {
                throw new Exception("Cette demande de transfert a déjà été traitée (Statut : {$request->status}).", 422);
            }

            // Cas de rejet simple
            if ($action === 'reject') {
                $request->update([
                    'status' => 'rejected',
                    'validator_id' => $validatorId,
                    'rejection_reason' => $rejectionReason,
                    'processed_at' => now()
                ]);
                return true;
            }

            // 2. Résolution dynamique et verrouillage des portefeuilles concernés
            $sourceWallet = $this->resolveWallet($request->requester_type, $request->requester_id);
            $targetWallet = $this->resolveWallet($request->target_type, $request->target_id);

            $amount = (float) $request->amount;

            // 3. Détermination des rôles de débit/crédit selon la nature du flux
            // 'supply' = La cible finance le demandeur | 'deposit' = Le demandeur verse à la cible
            if ($request->type === 'supply') {
                $walletToDebit = $targetWallet; // Ex: L'agence ou le Pays donne
                $walletToCredit = $sourceWallet; // Ex: Le guichet ou l'agence reçoit
            } else {
                $walletToDebit = $sourceWallet; // Ex: Le guichet ou l'agence rend son surplus
                $walletToCredit = $targetWallet; // Ex: L'agence ou le Pays encaisse
            }

            // 4. Contrôle de provision sur le compte débiteur
            if ((float) $walletToDebit->balance < $amount) {
                throw new Exception("Opération impossible : Le solde disponible sur le compte débiteur est insuffisant (" . number_format($walletToDebit->balance, 0, ',', ' ') . " {$request->currency}).", 422);
            }

            // 5. Création de la transaction d'audit système
            $transaction = Transaction::create([
                'uuid'         => (string) Str::uuid(),
                'reference'    => 'VLT-' . strtoupper(Str::random(10)),
                'type'         => $this->mapTransactionType($request->requester_type, $request->type),
                'status'       => 'completed',
                'amount'       => $amount,
                'currency'     => $request->currency,
                'initiator_id' => $validatorId,
                'completed_at' => now(),
                'sender_name'  => $this->getEntityName($walletToDebit),
                'recipient_name' => $this->getEntityName($walletToCredit)
            ]);

            // 6. Exécution des mouvements financiers (Double Écriture Comptable)

            // ÉCRITURE A : Le Débit
            $debitBalanceBefore = (float) $walletToDebit->balance;
            $walletToDebit->decrement('balance', $amount);
            $this->createLedgerEntry($transaction->id, $walletToDebit->id, 'debit', $amount, $debitBalanceBefore, $walletToDebit->fresh()->balance);

            // ÉCRITURE B : Le Crédit
            $creditBalanceBefore = (float) $walletToCredit->balance;
            $walletToCredit->increment('balance', $amount);
            $this->createLedgerEntry($transaction->id, $walletToCredit->id, 'credit', $amount, $creditBalanceBefore, $walletToCredit->fresh()->balance);

            // 7. Validation finale de la demande
            $request->update([
                'status'       => 'approved',
                'validator_id' => $validatorId,
                'processed_at' => now()
            ]);

            return true;
        });
    }

    /**
     * Résout et verrouille le portefeuille principal d'une entité polymorphe.
     * @param string $morphType
     * @param int $morphId
     * @return Wallet
     * @throws Exception
     */
    private function resolveWallet(string $morphType, int $morphId): Wallet
    {
        $wallet = Wallet::where('owner_type', $morphType)
            ->where('owner_id', $morphId)
            ->where('type', 'main')
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            $entityName = class_basename($morphType);
            throw new Exception("Le portefeuille comptable principal de l'entité [{$entityName} ID: {$morphId}] est introuvable.", 404);
        }

        return $wallet;
    }

    /**
     * Génère une ligne dans le Grand Livre (Ledger).
     */
    private function createLedgerEntry(int $transactionId, int $walletId, string $entryType, float $amount, float $before, float $after): void
    {
        TransactionEntry::create([
            'uuid'           => (string) Str::uuid(),
            'transaction_id' => $transactionId,
            'wallet_id'      => $walletId,
            'entry_type'     => $entryType,
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $after
        ]);
    }

    /**
     * Mappe le type de transaction système selon le niveau hiérarchique.
     * @param string $requesterType
     * @param string $requestType
     * @return string
     */
    private function mapTransactionType(string $requesterType, string $requestType): string
    {
        if ($requesterType === Till::class) {
            return $requestType === 'supply' ? 'vault_to_till' : 'till_to_vault';
        }
        return $requestType === 'supply' ? 'country_to_agency' : 'agency_to_country';
    }

    /**
     * Helper pour récupérer le nom lisible de l'entité propriétaire du portefeuille.
     */
    private function getEntityName(Wallet $wallet): string
    {
        $owner = $wallet->owner;
        return $owner?->name ?? class_basename($wallet->owner_type);
    }
}
