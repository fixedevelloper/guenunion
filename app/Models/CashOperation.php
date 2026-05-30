<?php

namespace App\Models;

use App\Traits\HasUuid;use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashOperation extends Model
{
    use HasUuid;
    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'agency_id',
        'staff_id',
        'type',
        'amount',
        'description',
        'till_id'
    ];

    /**
     * Les attributs qui doivent être castés dans des types natifs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'float',
        'type' => 'string', // 'opening', 'closing', 'cash_in', 'cash_out', 'adjustment'
    ];

    /**
     * Bottling du modèle pour injecter automatiquement l'UUID à la création.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relation avec l'agence où l'opération physique a eu lieu.
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    /**
     * Relation avec le membre du personnel (caissier) ayant validé le mouvement.
     */
    public function staff(): BelongsTo
    {
        // Si ton modèle d'utilisateur s'appelle User, adapte la classe ici
        return $this->belongsTo(User::class, 'staff_id');
    }
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }
}
