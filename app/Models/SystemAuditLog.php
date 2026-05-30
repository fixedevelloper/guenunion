<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SystemAuditLog extends Model
{
    /**
     * Nom de la table associée au modèle.
     *
     * @var string
     */
    protected $table = 'system_audit_logs';

    /**
     * Désactiver les timestamps automatiques standard (uniquement created_at géré).
     */
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'user_id',
        'agency_id',
        'event_type',
        'severity', // enum: 'info', 'warning', 'critical'
        'message',
        'payload',   // Contenu JSON
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Cycle de vie de la création pour générer automatiquement l'UUID.
     */
    protected static function booted(): void
    {
        static::creating(function (SystemAuditLog $log) {
            $log->uuid = (string) Str::uuid();
            $log->created_at = now();
        });
    }

    /**
     * Relation avec l'utilisateur (Collaborateur).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relation avec l'agence associée à l'événement.
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }
}
