<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Staff extends Model
{
    use SoftDeletes;

    /**
     * Forcer le nom de la table (Singulier/Pluriel géré manuellement)
     */
    protected $table = 'staff';

    /**
     * Les attributs assignables en masse.
     * ALIGNEMENT : Retrait de 'role' (délégué à Spatie) et intégration de 'country_id' et 'created_by_staff_id'
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'country_id',
        'agency_id',
        'employee_code',
        'created_by_staff_id',
        'is_active'
    ];

    /**
     * Les attributs à caster.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Boot de l'entité : Génération automatique du UUID à la création.
     */
    protected static function booted(): void
    {
        static::creating(function (Staff $staff) {
            if (empty($staff->uuid)) {
                $staff->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | LIAISONS D'IDENTITÉ ET HIÉRARCHIQUES
    |--------------------------------------------------------------------------
    */

    /**
     * Compte utilisateur / Identité d'authentification centrale (User)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Le supérieur hiérarchique ou l'opérateur réseau qui a créé ce profil staff
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES ET COUVREMENTS GÉOGRAPHIQUES
    |--------------------------------------------------------------------------
    */

    /**
     * Pays d'affectation et de juridiction légale (OHADA / Régulation financière)
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * Agence physique ou Guichet d'affectation opérationnelle
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    /*
    |--------------------------------------------------------------------------
    | TRÉSORERIE ET INDEMNISATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Portefeuilles personnels du Staff (ex: pour toucher des bonus, commissions de guichet ou reliquats)
     */
    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'owner');
    }


    /**
     * Récupère le guichet (Till) actuellement OUVERT par cet agent.
     */
    public function currentTill()
    {
        // On récupère le guichet à travers la dernière opération d'ouverture active
        return $this->hasOneThrough(
            Till::class,
            CashOperation::class,
            'staff_id', // Clé étrangère sur CashOperation
            'id',       // Clé primaire sur Till
            'user_id',  // Clé primaire sur Staff (ou 'id' selon votre structure)
            'till_id'   // Clé étrangère sur CashOperation pour rejoindre Till
        )
            ->where('cash_operations.type', 'opening')
            ->where('tills.status', 'open')
            ->where('tills.is_active', true)
            ->latest('cash_operations.created_at'); // Prend la session d'ouverture la plus récente
    }
}
