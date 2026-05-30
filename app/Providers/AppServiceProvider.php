<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Rate Limiter personnalisé pour la connexion au guichet.
         * Limite : 5 tentatives par minute.
         */
        RateLimiter::for('login_throttle', function (Request $request) {
            // Nettoyage rapide du paramètre phone pour s'assurer que la clé de cache reste identique
            $phone = clean_phone($request->input('phone', ''));

            // On crée une clé unique basée sur le téléphone ET l'IP pour éviter qu'un pirate
            // ne bloque à distance le compte d'un caissier en changeant d'IP (IP-Phone lock).
            $throttleKey = 'login:' . $phone . '|' . $request->ip();

            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Trop de tentatives de connexion infructueuses. Votre accès est temporairement bloqué pour 60 secondes.'
                ], 429, $headers);
            });
        });
    }
}
