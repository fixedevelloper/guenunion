<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Till extends Model
{
    use HasUuid;
    protected $fillable = ['agency_id', 'name', 'code', 'current_balance', 'is_active','status'];

    protected $casts = ['current_balance' => 'float', 'is_active' => 'boolean'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CashOperation::class);
    }
    public function wallet(): MorphOne
    {
        // 'owner' doit correspondre au préfixe utilisé dans ta migration : $table->morphs('owner')
        return $this->morphOne(Wallet::class, 'owner');
    }
}
