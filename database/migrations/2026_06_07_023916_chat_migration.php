<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migration pour les Conversations
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // 'peer_to_peer' (Client-Client) ou 'support' (Client-Support)
            $table->string('type')->default('peer_to_peer');
            // 'open' (en cours), 'resolved' (fermé pour le support)
            $table->string('status')->default('open');
            $table->timestamps();
        });

// 2. Migration Pivot (Membres de la conversation)
        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

// 3. Migration pour les Messages
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Expéditeur
            $table->string('type')->default('text'); // 'text', 'image', 'document'
            $table->text('body')->nullable(); // Optionnel si c'est uniquement une image
            $table->string('file_path')->nullable(); // URL ou chemin de l'image stockée
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
