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
// 1. Table Conversations (Modifiée)
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // Types possibles : 'peer_to_peer', 'agent_to_agent', 'agency_group', 'support'
            $table->string('type')->default('peer_to_peer');
            $table->string('status')->default('open');

            // Relation optionnelle avec l'agence (Utile pour le type 'agency_group')
            $table->foreignId('agency_id')
                ->nullable()
                ->constrained('agencies') // Ajustez le nom de votre table agences si nécessaire
                ->onDelete('cascade');

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
