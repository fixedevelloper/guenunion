<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles, HasApiTokens;

    /**
     * Forcer le guard de Spatie Permissions sur l'écosystème API (Sanctum)
     */
    protected $guard_name = 'api';

    /**
     * Les attributs assignables en masse.
     */
    protected $fillable = [
        'uuid',
        'username',
        'email',
        'phone_number',
        'password',
        'last_login_at',
        'last_login_ip',
        'is_active',
        'first_name',
        'last_name'
    ];

    /**
     * Les attributs masqués pour les retours d'API JSON (Next.js).
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Les attributs à caster.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
        'last_login_at'     => 'datetime',
    ];

    /**
     * Boot de l'entité : Génération automatique du UUID à la création.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS (ARCHITECTURE DÉCENTRALISÉE)
    |--------------------------------------------------------------------------
    */

    /**
     * Lien direct vers le profil professionnel du personnel (Staff).
     * ALIGNEMENT : Anciennement 'staffProfile', abrégé pour correspondre à vos requêtes with('staff.agency')
     */
    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class, 'user_id');
    }

    /**
     * Lien direct vers le profil client (KYC / Wallet).
     * ALIGNEMENT : Anciennement 'customerProfile', abrégé pour une syntaxe Eloquent plus fluide
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    /**
     * Récupérer l'historique complet des sessions d'accès de l'utilisateur.
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class, 'user_id');
    }
    public function isSupervisor(): bool
    {
        return $this->hasRole('manager');
    }

    public function isCountryAdmin(): bool
    {
        return $this->hasRole('country_admin');
    }
}
