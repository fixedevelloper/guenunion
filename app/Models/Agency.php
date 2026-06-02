<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Agency extends Model
{
    use SoftDeletes, HasUuid;

    /**
     * Les attributs assignables en masse.
     */
    protected $fillable = [
        'uuid',
        'code',
        'name',
        'parent_agency_id',
        'country_id',
        'city_id',
        'address',
        'phone_number',
        'email',
        'current_balance',
        'status',
        'is_active'
    ];

    /**
     * Les attributs à caster.
     */
    protected $casts = [
        'current_balance' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    /**
     * Boot de l'entité.
     * Note : Si votre trait HasUuid gère déjà le hook 'creating',
     * vous pouvez supprimer complètement cette méthode booted().
     */
    protected static function booted(): void
    {
        static::creating(function (Agency $agency) {
            if (empty($agency->uuid)) {
                $agency->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS HIERARCHIQUES ET GEOGRAPHIQUES
    |--------------------------------------------------------------------------
    */

    /**
     * Relation récursive : Agence mère (Siège ou Hub régional)
     */
    public function parentAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'parent_agency_id');
    }

    /**
     * Relation récursive : Sous-agences ou points de vente rattachés
     */
    public function subAgencies(): HasMany
    {
        return $this->hasMany(Agency::class, 'parent_agency_id');
    }

    /**
     * Pays d'implantation de l'agence
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * Ville de rattachement de l'agence
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS OPERATIONNELLES (STAFF & TRASURERIE)
    |--------------------------------------------------------------------------
    */

    /**
     * Le personnel affecté au périmètre de cette agence (Managers, Caissiers, Guichetiers)
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'agency_id');
    }
    public function tills(): HasMany
    {
        return $this->hasMany(Till::class, 'agency_id');
    }
    /**
     * LE COFFRE-FORT DE L'AGENCE (Portefeuille principal, compte de clearing ou de commissions)
     * Accès : $agency->wallets()->where('type', 'main')->first();
     */
    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'owner');
    }
}
