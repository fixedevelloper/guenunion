<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudCheck extends Model
{
    protected $fillable = ['uuid', 'transaction_id', 'risk_score', 'is_flagged', 'reason'];

    protected $casts = [
        'is_flagged' => 'boolean',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
