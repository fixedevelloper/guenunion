<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Country extends Model
{
    use HasFactory, SoftDeletes,HasUuid;

    /**
     * Les attributs de la table des pays qui sont assignables en masse.
     * Conformes aux spécifications de notre CountryAndCitySeeder.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',         // Code ISO unique à 2 lettres (ex: CM, CG, GA)
        'name',         // Nom officiel du pays (ex: Cameroun)
        'currency',     // Devise locale unifiée ou spécifique (ex: XAF)
        'phone_prefix', // Préfixe téléphonique international sans le '+' (ex: 237, 242)
        'is_active',    // Statut d'ouverture du pays pour le réseau de transfert
    ];

    /**
     * Le cast des types de colonnes pour assurer l'intégrité des données au format JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /*
     |--------------------------------------------------------------------------
     | RELATIONS ÉLÉGANTES (Eloquent Relationships)
     |--------------------------------------------------------------------------
     */

    /**
     * Un pays possède plusieurs villes (ex: Cameroun -> Douala, Yaoundé, etc.).
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * Un pays abrite plusieurs agences physiques de guichet.
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    /**
     * Règles tarifaires actives au départ de ce pays (Corridors Source).
     */
    public function outboundFees(): HasMany
    {
        return $this->hasMany(FeesTable::class, 'source_country_id');
    }

    /**
     * Règles tarifaires actives à la destination de ce pays (Corridors Destination).
     */
    public function inboundFees(): HasMany
    {
        return $this->hasMany(FeesTable::class, 'destination_country_id');
    }

    /*
     |--------------------------------------------------------------------------
     | PORTÉES RECHERCHE (Local Scopes)
     |--------------------------------------------------------------------------
     */

    /**
     * Filtre rapide pour récupérer uniquement les pays ouverts aux opérations.
     * Utilisation : Country::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
