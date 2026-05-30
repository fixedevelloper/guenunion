<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FeesTable extends Model
{
    use SoftDeletes;

    /**
     * Nom de la table associée au modèle.
     *
     * @var string
     */
    protected $table = 'fees_tables';

    /**
     * Les attributs assignables en masse (Harmonisé avec la structure réelle de la BDD).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'transaction_type',      // enum: 'transfer', 'cash_in', 'cash_out', 'remittance', 'merchant_payment'
        'source_country_id',     // Clé étrangère du pays d'origine
        'destination_country_id',// Clé étrangère du pays de destination
        'min_amount',            // Borne inférieure du palier (decimal)
        'max_amount',            // Borne supérieure du palier (decimal)
        'fixed_fee',             // Frais fixe (ex: 500.00)
        'percentage_fee',        // Frais en pourcentage (ex: 2.50)
        'tax_percentage',        // Taxe d'État / Nationale (ex: 0.25)
        'is_active',             // Statut d'activation de la règle
    ];

    /**
     * Les attributs qui doivent être convertis (Casting).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'source_country_id'      => 'integer',
        'destination_country_id' => 'integer',
        'min_amount'             => 'float',
        'max_amount'             => 'float',
        'fixed_fee'              => 'float',
        'percentage_fee'         => 'float',
        'tax_percentage'         => 'float',
        'is_active'              => 'boolean',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
    ];

    /**
     * Le cycle de vie "booted" du modèle Eloquent.
     * Génère automatiquement un UUID unique à la création d'un nouveau palier.
     */
    protected static function booted(): void
    {
        static::creating(function (FeesTable $feeTable) {
            $feeTable->uuid = (string) Str::uuid();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS ELOQUENT (Indispensables pour l'affichage Next.js)
    |--------------------------------------------------------------------------
    */

    /**
     * Relation avec le pays d'origine / source des fonds.
     * Accès : $feeTable->sourceCountry
     */
    public function sourceCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'source_country_id');
    }

    /**
     * Relation avec le pays de destination / réception des fonds.
     * Accès : $feeTable->destinationCountry
     */
    public function destinationCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'destination_country_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES LOCAUX (FILTRAGE FINANCIER)
    |--------------------------------------------------------------------------
    */

    /**
     * Scope local pour récupérer uniquement les grilles de frais actives.
     * Utilisation : FeesTable::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope local pour trouver précisément la tranche de frais d'une transaction.
     * Prend désormais en compte le corridor géographique (Origine + Destination).
     * * Utilisation : FeesTable::forCorridor('transfer', 1, 2, 75000)->first();
     */
    public function scopeForCorridor($query, string $type, int $sourceId, int $destinationId, float $amount)
    {
        return $query->where('transaction_type', $type)
            ->where('source_country_id', $sourceId)
            ->where('destination_country_id', $destinationId)
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->where('is_active', true);
    }
}
