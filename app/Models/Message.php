<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'type',
        'body',
        'file_path',
        'read_at'
    ];

    /**
     * Les attributs qui doivent être convertis en types natifs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'conversation_id' => 'integer',
        'user_id'         => 'integer',
        'read_at'         => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    /**
     * 👤 Relation : Récupérer l'utilisateur qui a envoyé ce message (L'expéditeur)
     * * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 💬 Relation : Récupérer la conversation à laquelle appartient ce message
     * * @return BelongsTo
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * 💡 Helper local : Vérifier si le message est un fichier/image
     * Useful si vous faites des traitements spécifiques dans vos ressources de l'API
     * * @return bool
     */
    public function isMedia(): bool
    {
        return $this->type === 'image';
    }
}
