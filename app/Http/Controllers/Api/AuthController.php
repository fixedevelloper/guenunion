<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\Staff;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * CONNEXION ET GÉNÉRATION DU TOKEN SANCTUM (Login).
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $cleanPhone = clean_phone($request->input('phone'));

            // Récupération de l'utilisateur d'authentification
            $user = User::where('phone_number', $cleanPhone)->first();

            // 1. Vérification de l'existence et du mot de passe
            if (!$user || !Hash::check($request->password, $user->password)) {
                $this->logLoginAttempt($user, $request, 'failed', 'invalid_credentials');
                return response()->json(['message' => 'Identifiants incorrects.'], 401);
            }

            // 2. Vérification du statut d'activité du compte central
            if (!$user->is_active) {
                $this->logLoginAttempt($user, $request, 'failed', 'suspended_account');
                return response()->json(['message' => 'Compte suspendu ou inactif.'], 403);
            }

            // Extraire le profil Staff associé si l'utilisateur fait partie de l'équipe
            $staff = Staff::with(['agency'])->where('user_id', $user->id)->first();

            // Vérification optionnelle : si l'utilisateur n'est pas un client mais n'a pas de profil Staff actif
            if (!$user->hasRole('customer') && (!$staff || !$staff->is_active)) {
                $this->logLoginAttempt($user, $request, 'failed', 'inactive_staff_profile');
                return response()->json(['message' => 'Profil opérateur non autorisé ou inactif.'], 403);
            }

            // 3. Sécurité de session unique : Révocation des jetons précédents
            $user->tokens()->delete();

            // 4. Génération du nouveau jeton d'accès Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            // 5. Enregistrement de l'historique avec le contexte du Staff
            $this->logLoginAttempt($user, $request, 'success', null, $staff);

            return Helpers::success([
                'success'      => true,
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => [
                    'id'           => $user->id,
                    'username'     => $user->username,
                    'first_name'   => $user->first_name,
                    'last_name'    => $user->last_name,
                    'roles'        => $user->getRoleNames(),
                    'permissions'  => $user->getAllPermissions()->pluck('name'), // Spatie Permissions
                    'context'      => $staff ? [
                        'staff_id'      => $staff->id,
                        'employee_code' => $staff->employee_code,
                        'agency_id'     => $staff->agency_id,
                        'agency_name'   => $staff->agency?->name,
                        'till_id'       => $staff->till_id,
                        'till_name'     => $staff->till?->name,
                        'country_id'    => $staff->country_id,
                    ] : null
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur critique lors de la tentative de login : " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Une erreur interne est survenue.'], 500);
        }
    }

    /**
     * Méthode helper cloisonnée pour tracer les logs d'accès.
     */
    private function logLoginAttempt(?User $user, Request $request, string $status, ?string $reason = null, ?Staff $staff = null): void
    {
        try {
            LoginHistory::create([
                'user_id'         => $user?->id,
                'phone_attempted' => $request->input('phone'),
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
                'status'          => $status,
                'failure_reason'  => $reason,
                // Renseignement du périmètre d'agence via l'entité Staff décentralisée
                'agency_id'       => $staff ? $staff->agency_id : null,
                'created_at'      => now()
            ]);
        } catch (Exception $e) {
            Log::error("Impossible d'écrire l'historique d'accès (LoginHistory) : " . $e->getMessage());
        }
    }

    /**
     * ÉTAPE 1 : DEMANDE DE RÉINITIALISATION (Forgot Password).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        try {
            $email = $request->input('email');

            // Purge des anciens jetons obsolètes pour cet e-mail
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Création d'un token aléatoire sécurisé de signature
            $token = Str::random(60);

            // Enregistrement hashé en BDD pour la conformité cryptographique
            DB::table('password_reset_tokens')->insert([
                'email'      => $email,
                'token'      => Hash::make($token),
                'created_at' => now()
            ]);

            // Routage vers l'application Web Next.js
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $resetLink = rtrim($frontendUrl, '/') . '/auth/reset-password?token=' . $token . '&email=' . urlencode($email);

            // TODO: Déclencher l'envoi de l'e-mail ou SMS de recouvrement
            // Mail::to($email)->send(new ResetPasswordMail($resetLink));

            Log::info("PASSWORD_FORGOT: Demande de réinitialisation générée pour l'adresse : {$email}");

            return response()->json([
                'success' => true,
                'message' => 'Un lien de réinitialisation sécurisé a été généré avec succès.',
                'debug_link' => config('app.env') === 'local' ? $resetLink : null
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Impossible de traiter la demande : " . $e->getMessage()
            ], 422);
        }
    }

    /**
     * ÉTAPE 2 : EXÉCUTION DU CHANGEMENT DE MOT DE PASSE (Reset Password).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min'       => 'Le nouveau mot de passe doit contenir au moins 8 caractères.'
        ]);

        try {
            $email = $request->input('email');
            $token = $request->input('token');

            $resetData = DB::table('password_reset_tokens')->where('email', $email)->first();

            if (!$resetData) {
                throw new Exception("Cette demande de réinitialisation n'existe pas ou a expiré.");
            }

            // Durée de validité stricte : 60 minutes maximum
            if (now()->parse($resetData->created_at)->addMinutes(60)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
                throw new Exception("Le jeton de sécurité a expiré. Veuillez soumettre une nouvelle demande.");
            }

            // Validation de l'authenticité de la clé brute transmise
            if (!Hash::check($token, $resetData->token)) {
                throw new Exception("Code de sécurité invalide.");
            }

            // Enregistrement du nouveau mot de passe sécurisé
            $user = User::where('email', $email)->firstOrFail();
            $user->password = Hash::make($request->input('password'));
            $user->save();

            // Nettoyage immédiat anti-rejeu (Replay Attack)
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Révocation complète des sessions pour forcer la réauthentification globale
            $user->tokens()->delete();

            Log::notice("PASSWORD_RESET: Le mot de passe de l'utilisateur [{$user->username}] a été modifié.");

            return response()->json([
                'success' => true,
                'message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.'
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur de réinitialisation : " . $e->getMessage()
            ], 422);
        }
    }

    /**
     * DÉCONNEXION (Logout).
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user) {
                // Clôture temporelle de la ligne de session active dans l'historique
                LoginHistory::where('user_id', $user->id)
                    ->where('status', 'success')
                    ->whereNull('logged_out_at')
                    ->orderBy('created_at', 'desc')
                    ->first()
                    ?->update([
                    'logged_out_at' => now()
                ]);

                // Suppression du token Sanctum actuel utilisé par le client Next.js
                $user->currentAccessToken()->delete();

                Log::info("AUTH_LOGOUT: Déconnexion de l'utilisateur ID [{$user->id}]. Session fermée.");
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion effectuée avec succès. Heure de fermeture de session enregistrée.'
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur lors du logout : " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la déconnexion.'], 500);
        }
    }
}
