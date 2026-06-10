<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use SoftDeletes;

    /**
     * Les attributs transférables en masse (Mass Assignable).
     * Parfaitement alignés avec votre schéma de migration.
     */
    protected $fillable = [
        'uuid',
        'reference',
        'type',
        'status',
        'amount',
        'fees',
        'taxes',
        'currency',
        'sender_customer_id',
        'secure_code',
        'sender_name',
        'sender_phone',
        'recipient_name',
        'recipient_phone',
        'recipient_email',
        'source_agency_id',
        'destination_agency_id',
        'source_till_id',
        'destination_till_id',
        'sender_country_id',
        'sender_city_id',
        'recipient_country_id',
        'recipient_city_id',
        'initiator_id',
        'completed_at',
        'cancelled_at',
        'reversed_at',
        'metadata',
        'description'
    ];

    /**
     * Typage natif des attributs (Casting).
     * Synchronisé avec le format 'decimal(18,2)', les objets Datetime et le champ JSON 'metadata'.
     */
    protected $casts = [
        'amount'       => 'decimal:2',
        'fees'         => 'decimal:2',
        'taxes'        => 'decimal:2',
        'metadata'     => 'array',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reversed_at'  => 'datetime',
    ];

    /**
     * Événements du cycle de vie du modèle (Booted).
     */
    protected static function booted()
    {
        static::creating(function ($transaction) {
            // Assignation automatique d'un UUID propre si non défini au préalable
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    // =========================================================================
    // RELATIONS CLIENTS ET INTERVENANTS
    // =========================================================================

    /**
     * Le client émetteur enregistré dans le système (si existant / KYC complet).
     */
    public function senderCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'sender_customer_id');
    }

    /**
     * L'utilisateur / guichetier (staff technique) ayant validé l'action en agence.
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    // =========================================================================
    // RELATIONS DE SÉCURISATION ET STRUCTURES D'AGENCES
    // =========================================================================

    /**
     * L'agence physique d'origine ou de dépôt de la transaction.
     */
    public function sourceAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'source_agency_id');
    }

    /**
     * L'agence cible ou de retrait des fonds (ex: cas des 'remittance' ou 'transfer').
     */
    public function destinationAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'destination_agency_id');
    }

    // =========================================================================
    // TRAÇABILITÉ ET CONFORMITÉ GÉOGRAPHIQUE
    // =========================================================================

    public function senderCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'sender_country_id');
    }

    public function senderCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'sender_city_id');
    }

    public function recipientCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'recipient_country_id');
    }

    public function recipientCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'recipient_city_id');
    }

    // =========================================================================
    // ÉCRITURES DU GRAND LIVRE & LOGIQUE FINANCIÈRE
    // =========================================================================

    /**
     * Les écritures de mouvements de comptes / caisses associées (Ledger Entries).
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TransactionEntry::class, 'transaction_id');
    }

    /**
     * Historique ou dispatching des répartitions de commissions liées à cette transaction.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'transaction_id');
    }

    // =========================================================================
    // ACCESSEURS DE CALCUL DE FLUX (Mutators)
    // =========================================================================

    /**
     * Calcule le montant brut total (Principal + Frais + Taxes réglementaires).
     * Utile pour vérifier l'encours à débiter des comptes de caisse.
     */
    public function getTotalGrossAmountAttribute(): float
    {
        return (float) ($this->amount + $this->fees + $this->taxes);
    }
}
