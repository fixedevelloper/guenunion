<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'phone_attempted', // mis à jour
        'ip_address',
        'user_agent',
        'status',
        'failure_reason',
        'agency_id',
        'created_at',
        'logged_out_at'   // mis à jour
    ];

    protected $casts = [
        'created_at'    => 'datetime',
        'logged_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
