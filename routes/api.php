<?php

use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\Api\AgencyAnalyticsController;
use App\Http\Controllers\Api\AgencyController;
use App\Http\Controllers\Api\AgencyReportController;
use App\Http\Controllers\Api\AgencyTillController;
use App\Http\Controllers\Api\AgencyTransactionController;
use App\Http\Controllers\Api\AgencyVaultController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CashOperationController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\customer\ChatController;
use App\Http\Controllers\Api\customer\KycController;
use App\Http\Controllers\Api\customer\TransactionController;
use App\Http\Controllers\Api\customer\WalletController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\FeesTableController;
use App\Http\Controllers\Api\RemittanceController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\VaultTransferController;
use App\Http\Controllers\CashInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Wallet & Remittance Ecosystem (Agensic)
|--------------------------------------------------------------------------
*/

// ==========================================
// ROUTES PUBLIQUES (Hors Middleware)
// ==========================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:login_throttle');
    Route::post('/customer/login', [AuthController::class, 'loginCustomer'])->name('customerLogin')->middleware('throttle:login_throttle');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::prefix('otp')->group(function () {
        // La route POST demandée
        Route::post('send', [AuthController::class, 'sendOtpRequest']);
        Route::post('verify', [AuthController::class, 'verifyOtp']);
    });
});
Route::get('/transactions/{id}/receipt', [TransactionController::class, 'downloadReceipt'])->name('transactions.receipt');
Route::get('/me/countries', [CountryController::class, 'countries']);
// ==========================================
// ROUTES SÉCURISÉES (Authentification Sanctum)
// ==========================================
Route::middleware(['auth:sanctum'])->group(function () {

    // Déconnexion générique
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    /*
     |--------------------------------------------------------------------------
     | Espace Simulation (Accessible par tout le personnel authentifié)
     |--------------------------------------------------------------------------
     */
    Route::post('/remittance/estimate-fees', [RemittanceController::class, 'estimateFees']);
    // =========================================================================
    // ROUTES DE TRÉSORERIE UNIFIÉES (Vault & Till Transfers)
    // =========================================================================
    Route::prefix('vault-transfers')->group(function () {

        // 1. Initialiser une demande (Accessible par les caissiers et superviseurs)
        Route::post('/', [VaultTransferController::class, 'store']);

        // 2. Annuler sa propre demande tant qu'elle est en attente
        Route::delete('/{id}/cancel', [VaultTransferController::class, 'cancel']);

        // 3. Récupérer le listing adaptatif selon le rôle (Demandes en attente)
        Route::get('/pending', [VaultTransferController::class, 'pendingRequests']);

        // 4. Consulter l'historique des flux archivés (Approuvés / Rejetés)
        Route::get('/history', [VaultTransferController::class, 'history']);

        // 5. Traiter une demande (Approuver ou Rejeter)
        // Note : Le contrôleur valide en interne si c'est un superviseur ou un country_admin
        Route::post('/{id}/process', [VaultTransferController::class, 'process']);

    });
    Route::middleware(['role:customer'])->group(function () {
        Route::get('/me/transactions', [TransactionController::class, 'index']);
        Route::get('/me/transactions/{id}', [TransactionController::class, 'show']);
        Route::get('/me/wallets', [WalletController::class, 'index']);
        Route::get('/me/customers/verify/{phone}', [CustomerController::class, 'verifyCustomer']);
        Route::get('/customers/me', [CustomerController::class, 'showMe']);
        Route::get('me/cities', [CityController::class, 'getCityCustomers']);
        Route::put('/customers/me', [CustomerController::class, 'update']); // PUT ou PATCH selon ta préférence
        Route::get('/me/remittance/estimate-fees', [TransactionController::class, 'estimateFees']);
        Route::post('/me/transactions', [TransactionController::class, 'makeTransaction']);
        Route::post('/me/transfers', [TransactionController::class, 'makeWalletTransaction']);
        Route::post('/me/profile/update', [CustomerController::class, 'updateProfile']);
        Route::post('/kyc-documents', [KycController::class, 'upload']);
    });
    // ── 💬 GESTION DES CONVERSATIONS (SALONS) ───────────────────────────────
    Route::prefix('me/conversations')->group(function () {

        // ⚡ 1. Récupérer ou Créer un salon avec un contact (Id de l'utilisateur cible)
        Route::post('{contact_id}', [ChatController::class, 'createOrGetConversation']);

        // 📖 2. Charger l'historique des messages d'une conversation spécifique
        Route::get('{conversation_id}/messages', [ChatController::class, 'getMessages']);

        // 📤 3. Envoyer un message dans une conversation (Supporte le texte et le Multipart pour les images)
        Route::post('{conversation_id}/messages', [ChatController::class, 'sendMessage']);

        // 📜 4. Optionnel : Récupérer la liste de toutes mes discussions en cours (pour l'écran d'accueil du chat)
        Route::get('/', [ChatController::class, 'getMyConversations']);

    });

    /*
     |--------------------------------------------------------------------------
     | Espace Guichet / Caisse (Cashier, Merchant & Administrateurs)
     |--------------------------------------------------------------------------
     */
    Route::middleware(['role:cashier|manager|super_admin|country_admin'])->group(function () {

        // Données géographiques de service
        Route::get('/countries', [CountryController::class, 'index']);
        Route::get('/cities/agency', [CityController::class, 'getCityByAgencyCountry']);
        Route::get('/cities/by/country', [CityController::class, 'getCityByCountry']);

        // Gestion des clients & Conformité KYC de premier niveau
        Route::get('/customers', [CustomerController::class, 'customers']);
        Route::get('/customers2', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{uuid}', [CustomerController::class, 'show']);
        Route::get('/customers/reference/{reference}', [CustomerController::class, 'searchByReference']);

        // Gestion de la Caisse (Sessions et Historiques)
        Route::get('/cashier/session-status', [CashierSessionController::class, 'getSessionStatus']);
        Route::get('/cashier/dashboard-metrics', [ReportingController::class, 'dashboardCashier']);
        Route::get('/cash/session/status', [CashOperationController::class, 'status']);
        Route::post('/cash/session/open', [CashOperationController::class, 'open']);
        Route::post('/cash/session/close', [CashOperationController::class, 'close']);
        Route::get('/cash/agency-tills', [CashOperationController::class, 'getAgencyTills']);
        Route::get('/cash/history', [RemittanceController::class, 'history']);

        // Opérations financières sur les Mandats (Remittance)
        // CRITIQUE : Ordre immuable des routes pour interdire les interceptions par le paramètre {reference}
        Route::post('/remittance/initiate', [RemittanceController::class, 'initiate']);
        Route::post('/remittance/payout', [RemittanceController::class, 'payout']);
        Route::get('/remittance/payout/search', [RemittanceController::class, 'searchPayout']);
        Route::get('/remittance/payout/{reference}', [RemittanceController::class, 'show']);

        ///transfer
        Route::get('cash/transfer/fees/calculate', [TransferController::class, 'calculateFees']);
        Route::post('/cash/transfer/execute', [TransferController::class, 'execute']);
        Route::post('/cash/cash-in/execute', [CashInController::class, 'execute']);
        Route::post('/cash/cash-out/execute', [CashInController::class, 'executeCashOut']);

        Route::prefix('staff/chat/')->group(function () {
            // Chat P2P entre deux collègues
            Route::post('peer', [AgentChatController::class, 'startAgentChat']);
            Route::post('/agents/find-by-phone', [AgentChatController::class, 'findAgentByPhone']);
            Route::get('/agents', [AgentChatController::class, 'index']);
            Route::get('/agents/by-country', [AgentChatController::class, 'getAgentsByCountry'])
                ->name('chat.agents.country');
            Route::get('/agents/global', [AgentChatController::class, 'getGlobalAgents'])
                ->name('chat.agents.global');
            // Salon de discussion de l'agence locale
            Route::get('agency-channel', [AgentChatController::class, 'getAgencyGroupChat']);
// 🇨🇲 Salon national (regroupe toutes les agences du pays)
            Route::get('/country-channel', [AgentChatController::class, 'getCountryGroupChat'])
                ->name('chat.country');

            // 🌍 Salon global (regroupe absolument tous les agents du système)
            Route::get('/global-channel', [AgentChatController::class, 'getGlobalGroupChat'])
                ->name('chat.global');
            // Pour envoyer et lister les messages, réutilisez les méthodes génériques existantes de votre ChatController :
            Route::post('conversations/{id}/messages', [ChatController::class, 'sendMessage']);
            Route::get('conversations/{id}/messages', [ChatController::class, 'getMessages']);
        });
    });

    /*
     |--------------------------------------------------------------------------
     | Espace Supervision Agences (Manager, Country Admin, Super Admin)
     |--------------------------------------------------------------------------
     */
    Route::middleware(['role:manager|country_admin|super_admin'])->group(function () {
        Route::get('/agencies', [AgencyController::class, 'index']);
        Route::get('/agencies/{id}', [AgencyController::class, 'show']);
        // Gestion du personnel (Staff) de l'ensemble du réseau
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/dependencies', [StaffController::class, 'dependencies']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::patch('/staff/{uuid}/toggle', [StaffController::class, 'toggleStatus']);

        Route::get('/agency/analytics/dashboard', [AgencyAnalyticsController::class, 'getAgencyDashboardData']);
        Route::get('/agency/tills', [AgencyTillController::class, 'index']);
        Route::post('/agency/tills', [AgencyTillController::class, 'store']);
        Route::patch('/agency/tills/{id}/toggle', [AgencyTillController::class, 'toggleStatus']);
        Route::post('/agency/tills/{id}/operation', [AgencyTillController::class, 'handleOperation']);
        Route::get('/agency/transactions', [AgencyTransactionController::class, 'index']);
        Route::get('/agency/vaults', [AgencyVaultController::class, 'index']);
        Route::post('/agency/vaults/transaction', [AgencyVaultController::class, 'storeTransaction']);
        Route::get('/agency/reports', [AgencyReportController::class, 'index']);

        Route::get('/fraud-checks', [ReportingController::class, 'fraudCheckHistory']);
        Route::get('/logs/system', [ReportingController::class, 'systemeLogs']);
        Route::get('/logs/connections', [ReportingController::class, 'historyLogs']);
        Route::get('/commissions', [CommissionController::class, 'index']);
        Route::get('/accounting/preview', [ReportingController::class, 'previewDocument']);


    });

    /*
     |--------------------------------------------------------------------------
     | Espace Haute Administration & Conformité Pays (Country Admin, Super Admin)
     |--------------------------------------------------------------------------
     */
    Route::middleware(['role:country_admin|super_admin'])->group(function () {

        // Gestion de la liquidité des coffres agences (Vault Management)
        Route::post('/agencies/{id}/adjust-vault', [AgencyController::class, 'adjustVault']);
        Route::post('/agencies', [AgencyController::class, 'store']);
        // Configuration des corridors et grilles tarifaires
        Route::get('/regional/fees', [FeesTableController::class, 'getRegionalFees']);
        Route::post('/regional/fees', [FeesTableController::class, 'storeFee']);

        // Reporting Macro-financier consolidé et Validation KYC de second niveau
        Route::get('reporting/global-metrics', [ReportingController::class, 'dashboard']);
        Route::get('reporting/global-transactions', [ReportingController::class, 'globalTransactions']);
        Route::get('reporting/kyc-submissions', [CustomerController::class, 'kycSubmissions']);
        Route::post('customers/{uuid}/kyc-evaluate', [CustomerController::class, 'evaluateKyc']);
        Route::get('/regional-admin/dashboard-metrics', [ReportingController::class, 'getRegionalMetrics']);
        Route::get('reporting/regional-metrics', [ReportingController::class, 'getRegionalMetrics']);
        Route::get('/reporting/regional/agencies', [ReportingController::class, 'getRegionalAgencies']);
        Route::get('/reporting/regional/tills', [ReportingController::class, 'getRegionalTills']);
        Route::get('/reporting/regional/transactions', [ReportingController::class, 'getRegionalTransactions']);
        Route::get('/reporting/regional/staff', [ReportingController::class, 'getRegionalStaff']);
    });

    /*
     |--------------------------------------------------------------------------
     | Espace d'Administration Centrale (Exclusivement réservé au Super Admin)
     |--------------------------------------------------------------------------
     */
    Route::middleware(['role:super_admin'])->group(function () {

        // Paramétrage des Devises et Pays d'exploitation
        Route::post('/countries', [CountryController::class, 'store']);
        Route::patch('/countries/{uuid}/toggle-status', [CountryController::class, 'toggleStatus']);
        Route::post('/countries/{countryUuid}/cities', [CityController::class, 'store']);
        Route::patch('/cities/{uuid}/toggle', [CityController::class, 'toggleStatus']);

        Route::post('/countries/{uuid}/adjust-wallet', [CountryController::class, 'adjustWallet']);
        Route::patch('/staff/{uuid}/toggle', [StaffController::class, 'toggleStatus']);

        // Création et activation des implantations d'agences
        Route::get('/agencies/dependencies', [AgencyController::class, 'dependencies']);
        // Route::post('/agencies', [AgencyController::class, 'store']);
        Route::patch('/agencies/{uuid}/toggle', [AgencyController::class, 'toggleStatus']);

        Route::get('/fees', [FeesTableController::class, 'index']);
    });
});
