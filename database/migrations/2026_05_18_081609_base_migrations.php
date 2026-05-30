<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Architecture Fintech Professionnelle & Souveraine
     * Gestion des Scopes d'Autorité : SuperAdmin, CountryAdmin, Manager, Staff
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. COUNTRIES
        |--------------------------------------------------------------------------
        */
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->string('code', 2)->unique(); // ISO (ex: CM, GA, CI)
            $table->string('currency_code', 5); // ex: XAF, XOF
            $table->string('currency_symbol', 10)->nullable();
            $table->string('phone_prefix', 10)->unique(); // ex: +237
            $table->boolean('can_cash_in')->default(true);
            $table->boolean('can_cash_out')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
        });

        /*
        |--------------------------------------------------------------------------
        | 2. CITIES
        |--------------------------------------------------------------------------
        */
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country_id', 'is_active']);
        });

        /*
        |--------------------------------------------------------------------------
        | 3. USERS (Authentification unique - Sans contraintes géographiques directes)
        |--------------------------------------------------------------------------
        */
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('username')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->unique();
            $table->string('phone_number')->nullable()->unique();
            $table->string('password');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        /*
        |--------------------------------------------------------------------------
        | 4. AGENCIES
        |--------------------------------------------------------------------------
        */
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('parent_agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('address')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_id', 'city_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | 5. TILLS (Guichets physiques de l'agence)
        |--------------------------------------------------------------------------
        */
        Schema::create('tills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->decimal('current_balance', 18, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['open', 'close', 'suspended']);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 6. STAFF (Le cœur de votre logique de rôles et de périmètres)
        |--------------------------------------------------------------------------
        */
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code')->unique();

            // --- GESTION DES PERSPECTIVES DE VUE (SCOPES) ---
            // SuperAdmin : country_id = null AND agency_id = null
            // CountryAdmin : country_id = X AND agency_id = null
            // Manager/Cashier : country_id = X AND agency_id = Y
            $table->foreignId('country_id')->nullable()->constrained('countries')->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->restrictOnDelete();

            // Traçabilité de la hiérarchie de création demandée
            // Qui a créé ce membre du staff (permet de valider la logique d'arborescence en Backend)
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Index pour requêtes de filtrage rapides basées sur le périmètre
            $table->index(['country_id', 'agency_id']);
            $table->index('created_by_staff_id');
        });

        /*
        |--------------------------------------------------------------------------
        | 7. CUSTOMERS
        |--------------------------------------------------------------------------
        */
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('address')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->string('id_expiry_date')->nullable();
            $table->enum('kyc_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->enum('kyc_level', ['none', 'basic', 'medium', 'full'])->default('none');
            $table->enum('status', ['pending', 'active', 'blocked', 'blacklisted'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reference', 'status']);
            $table->index('kyc_status');
        });

        /*
        |--------------------------------------------------------------------------
        | 8. WALLETS (Polymorphique : s'adapte à l'agence, au client ou au staff)
        |--------------------------------------------------------------------------
        */
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('owner');
            $table->string('wallet_number')->unique();
            $table->enum('type', ['main', 'commission', 'settlement', 'escrow', 'treasury']);
            $table->string('currency', 3)->default('XAF');
            $table->decimal('balance', 18, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->string('ledger_hash')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_id', 'owner_type', 'type']);
        });

        /*
        |--------------------------------------------------------------------------
        | 9. TRANSACTIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();
            $table->enum('type', [
                'transfer', 'cash_in', 'cash_out', 'deposit', 'withdrawal',
                'remittance', 'merchant_payment', 'bill_payment', 'peer_to_peer',
                'commission', 'adjustment', 'refund'
            ]);
            $table->enum('status', ['initiated', 'processing', 'completed', 'paid', 'failed', 'reversed', 'cancelled'])->default('initiated');
            $table->decimal('amount', 18, 2);
            $table->decimal('fees', 18, 2)->default(0);
            $table->decimal('taxes', 18, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            $table->foreignId('sender_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('secure_code', 20)->nullable()->unique();
            $table->string('sender_name')->nullable();
            $table->string('sender_phone')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_email')->nullable();

            $table->foreignId('source_agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->foreignId('destination_agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->foreignId('sender_country_id')->nullable()->constrained('countries')->restrictOnDelete();
            $table->foreignId('sender_city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->foreignId('recipient_country_id')->nullable()->constrained('countries')->restrictOnDelete();
            $table->foreignId('recipient_city_id')->nullable()->constrained('cities')->restrictOnDelete();

            // L'utilisateur (user) technique qui a validé l'action au guichet
            $table->foreignId('initiator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reference', 'status']);
            $table->index(['secure_code', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | 10. TRANSACTION ENTRIES
        |--------------------------------------------------------------------------
        */
        Schema::create('transaction_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('row_signature')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 11. COMMISSIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('percentage', 8, 2)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 12. KYC DOCUMENTS
        |--------------------------------------------------------------------------
        */
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['national_id', 'passport', 'driving_license', 'residence_permit']);
            $table->string('document_number');
            $table->string('front_image');
            $table->string('back_image')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
        |--------------------------------------------------------------------------
        | 13. CASH OPERATIONS (Brouillard de caisse lié au guichet et au staff)
        |--------------------------------------------------------------------------
        */
        Schema::create('cash_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Clés étrangères et contraintes strictes
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('till_id')->nullable()->constrained('tills')->restrictOnDelete();

            // Référence explicite à la table des opérateurs (staffs)
            $table->foreignId('staff_id')->constrained()->restrictOnDelete();

            // Enum mis à jour pour correspondre à vos contrôleurs de flux (credit / debit)
            $table->enum('type', ['opening', 'closing', 'cash_in', 'cash_out', 'adjustment']);

            // Précision financière (18 chiffres au total, 2 décimales) -> Parfait pour le FCFA / Devises
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->timestamps();

            // ⚡ INDEX CONCEPTS POUR LE POLLING ET L'AUDIT COMPTABLE
            // Optimise la récupération de la dernière opération par guichet (Méthode status())
            $table->index(['till_id', 'type', 'created_at']);

            // Optimise le filtrage par agence pour les rapports de clôture journaliers
            $table->index(['agency_id', 'created_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | 14. FRAUD CHECKS
        |--------------------------------------------------------------------------
        */
        Schema::create('fraud_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->integer('risk_score')->default(0);
            $table->boolean('is_flagged')->default(false);
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 15. OTP CODES
        |--------------------------------------------------------------------------
        */
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 16. SYSTEM AUDIT LOGS
        |--------------------------------------------------------------------------
        */
        Schema::create('system_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->text('message');
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        /*
        |--------------------------------------------------------------------------
        | 17. EXCHANGE RATES
        |--------------------------------------------------------------------------
        */
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_currency', 3);
            $table->string('destination_currency', 3);
            $table->decimal('rate', 18, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 18. FEES TABLES
        |--------------------------------------------------------------------------
        */
        Schema::create('fees_tables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('transaction_type', ['transfer', 'cash_in', 'cash_out', 'remittance', 'merchant_payment']);
            $table->foreignId('source_country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('destination_country_id')->constrained('countries')->restrictOnDelete();
            $table->decimal('min_amount', 18, 2);
            $table->decimal('max_amount', 18, 2);
            $table->decimal('fixed_fee', 18, 2)->default(0);
            $table->decimal('percentage_fee', 8, 2)->default(0);
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        /*
        |--------------------------------------------------------------------------
        | 19. LOGIN HISTORIES
        |--------------------------------------------------------------------------
        */
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('phone_attempted');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->string('failure_reason')->nullable();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('logged_out_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('fees_tables');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('system_audit_logs');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('fraud_checks');
        Schema::dropIfExists('cash_operations');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('transaction_entries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('tills');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('countries');
    }
};
