<?php

namespace App\Listeners;

use App\Models\VaultTransferRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageSent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HandleCashGapNotification
{
    /**
     * Déclenché juste après la création d'une VaultTransferRequest.
     */
    public function handle($event): void
    {
        $request = $event->vaultTransferRequest; // Votre modèle de transfert

        // On ne cible que les dépôts d'écarts (surplus de caisse)
        if ($request->type !== 'gap_deposit') {
            return;
        }

        // 1. Trouver le canal de discussion du groupe de l'agence cible
        $conversation = Conversation::where('type', 'agency_group')
            ->where('id', $request->target_id) // Si lié à l'ID de l'agence
            ->first();

        // Alternative : Envoyer au Manager principal si le groupe n'existe pas
        if (!$conversation) {
            return;
        }

        // 2. Créer le message automatique du Robot Système (User ID: 1 ou null)
        $systemMessage = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $request->creator_id, // L'ID du caissier qui a ouvert/fermé la caisse
            'type'            => 'text',
            'body'            => "🚨 [ALERTE SÉCURITÉ CAISSE] : Un surplus de " . number_format($request->amount, 2, '.', ' ') . " {$request->currency} a été déclaré. Une demande de transfert [Ref: VLT] est en attente de validation comptable.",
            'file_path'       => null,
        ]);

        // 3. Diffuser en temps réel sur Reverb pour que l'écran du Manager flashe
        broadcast(new MessageSent($systemMessage))->toOthers();
    }
}
