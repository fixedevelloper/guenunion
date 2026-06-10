<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Source de la demande (ex: Till ID 5 ou Agency ID 2)
            $table->morphs('requester');

            // Destination/Validateur logique de la demande (ex: Agency ID 2 ou Country ID 1)
            $table->morphs('target');

            $table->enum('type', ['supply', 'deposit']); // supply = demande de fonds, deposit = versement / décharge
            $table->decimal('amount', 18, 2); // Précision bancaire standard
            $table->string('currency', 3)->default('XAF');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // Traçabilité des acteurs (Relations avec la table users/staff)
            $table->foreignId('creator_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('validator_id')->nullable()->constrained('users')->onDelete('restrict');

            // Textes de contexte
            $table->string('notes')->nullable(); // Motif de la demande par le superviseur/caissier
            $table->string('rejection_reason')->nullable(); // Motif du refus par l'admin/superviseur

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Index pour optimiser les requêtes de listes (Tableau de bord)
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_transfer_requests');
    }
};
