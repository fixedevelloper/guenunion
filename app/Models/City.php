<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory,HasUuid;

    /**
     * Les attributs de la table des villes qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',       // Nom de la ville (ex: Douala, Brazzaville, Libreville)
        'country_id', // Identifiant du pays de rattachement (Clé étrangère)
        'is_active',  // Statut d'activation de la ville dans le réseau
    ];

    /**
     * Le cast des types de colonnes pour les réponses JSON de l'API.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'  => 'boolean',
        'country_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
     |--------------------------------------------------------------------------
     | RELATIONS (Eloquent Relationships)
     |--------------------------------------------------------------------------
     */

    /**
     * Une ville appartient à un seul et unique pays (ex: Douala appartient au Cameroun).
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Une ville peut abriter plusieurs agences de transfert physiques.
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    /*
     |--------------------------------------------------------------------------
     | PORTÉES DE RECHERCHE (Local Scopes)
     |--------------------------------------------------------------------------
     */

    /**
     * Filtre rapide pour récupérer uniquement les villes opérationnelles.
     * Utilisation : City::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
