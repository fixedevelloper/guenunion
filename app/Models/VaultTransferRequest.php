<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultTransferRequest extends Model
{
    protected $fillable = [
        'uuid', 'requester_type', 'requester_id', 'target_type', 'target_id',
        'type', 'amount', 'currency', 'status', 'creator_id', 'validator_id',
        'notes', 'rejection_reason', 'processed_at'
    ];

    protected $casts = [
        'amount'       => 'float',
        'processed_at' => 'datetime',
    ];

    /**
     * L'entité qui a initié la demande (Un Till ou une Agency)
     */
    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * L'entité cible qui doit fournir/recevoir les fonds (Une Agency ou un Country)
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }
}
