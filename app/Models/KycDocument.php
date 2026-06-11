<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class KycDocument extends Model
{
    use SoftDeletes;

    /**
     * Les attributs transférables (assignables en masse).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'customer_id',
        'type',
        'document_number',
        'front_image',
        'back_image',
        'verified_at',
        'verified_by',
    ];
    protected $appends = ['is_verified'];
    /**
     * Obtenir les attributs qui doivent être convertis (Casting).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Le cycle de vie "booted" du modèle Eloquent.
     * Génère automatiquement un UUID standard lors de la soumission du dossier.
     */
    protected static function booted(): void
    {
        static::creating(function (KycDocument $document) {
            $document->uuid = (string) Str::uuid();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS ELOQUENT
    |--------------------------------------------------------------------------
    */

    /**
     * Relation avec le client (Titulaire de la pièce d'identité)
     * Accès : $kycDocument->customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Relation avec l'agent / compliance officer qui a analysé et validé la pièce
     * Accès : $kycDocument->verifiedByUser
     */
    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSEURS / ATTRIBUTS VIRTUELS (HELPERS POUR NEXT.JS)
    |--------------------------------------------------------------------------
    */

    /**
     * Accesseur pour savoir si la pièce d'identité est actuellement validée
     * Accès dans le code : $kycDocument->is_verified (booléen)
     */
    public function getIsVerifiedAttribute(): bool
    {
        return !is_null($this->verified_at);
    }
}
