<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['type', 'status'];

    // Les participants au chat (Clients ou Agents de support)
    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    // Les messages du salon
    public function messages()
    {
        return $this->hasMany(Message::class);
    }
    // L'agence associée (si applicable)
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
