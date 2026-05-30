<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransactionEntry extends Model
{
    // Pas de SoftDeletes ici ! Le Grand Livre ne doit jamais effacer de données.

    protected $fillable = [
        'uuid', 'transaction_id', 'wallet_id', 'entry_type', 'amount', 'balance_before', 'balance_after', 'row_signature'
    ];

    protected static function booted()
    {
        static::creating(function ($entry) {
            $entry->uuid = (string) Str::uuid();
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
