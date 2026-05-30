<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Wallet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'owner_type', 'owner_id', 'wallet_number', 'type', 'currency', 'balance', 'is_active', 'ledger_hash'
    ];

    protected static function booted()
    {
        static::creating(function ($wallet) {
            $wallet->uuid = (string) Str::uuid();
        });
    }

    /**
     * Récupère le propriétaire dynamique (Agency, Customer ou Staff)
     */
    public function owner()
    {
        return $this->morphTo();
    }

    /**
     * Récupérer toutes les écritures comptables (Ledger) liées à ce portefeuille
     */
    public function ledgerEntries()
    {
        return $this->hasMany(TransactionEntry::class, 'wallet_id');
    }
}
