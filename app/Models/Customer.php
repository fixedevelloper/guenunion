<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'user_id', 'reference', 'first_name', 'last_name', 'birth_date','id_number','id_expiry_date','id_type',
        'gender', 'phone_number', 'email', 'country_id', 'city_id', 'address', 'kyc_level', 'status'
    ];

    protected static function booted()
    {
        static::creating(function ($customer) {
            $customer->uuid = (string) Str::uuid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
    /**
     * Récupérer tous les wallets du client.
     */
    public function wallets()
    {
        return $this->morphMany(Wallet::class, 'owner');
    }

    /**
     * Récupérer spécifiquement le wallet principal (Main Wallet) du client.
     */
    public function mainWallet()
    {
        return $this->morphOne(Wallet::class, 'owner')->where('type', 'main');
    }
    public function scopeHandleSort(Builder $query): Builder
    {
        // Si tu as une logique de tri dynamique via la requête, applique-la ici.
        // Sinon, applique un tri par défaut (par exemple, du plus récent au plus ancien).
        return $query->orderBy('id', 'desc');
    }
}
