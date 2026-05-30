<?php

namespace App\Services;

use App\Models\Transaction;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected string $smsApiUrl;
    protected string $smsApiKey;
    protected string $smsSenderId;

    public function __construct()
    {
        // Configuration des clés via le fichier .env
        $this->smsApiUrl   = config('services.sms.api_url', 'https://api.sms-provider.com/v1/send');
        $this->smsApiKey   = config('services.sms.api_key', '');
        $this->smsSenderId = config('services.sms.sender_id', 'VISAMONEY');
    }

    /**
     * Notifier instantanément l'émetteur et le bénéficiaire d'un nouveau transfert de fonds.
     * Cette méthode est conçue pour être appelée en arrière-plan (Queue/Job).
     *
     * @param Transaction $transaction
     * @return void
     */
    public function sendRemittanceAlerts(Transaction $transaction): void
    {
        // 1. Alerte à l'émetteur (Sender) : Confirmation de dépôt et retrait des fonds
        $senderMessage = sprintf(
            "Cher client, votre transfert de %.2f %s vers %s a ete effectue avec succes. Ref: %s. Frais payes: %.2f %s. Merci de votre confiance.",
            $transaction->amount,
            $transaction->currency,
            $transaction->recipient_name,
            $transaction->reference,
            $transaction->fees,
            $transaction->currency
        );

        $this->dispatchSms($transaction->sender_phone, $senderMessage, $transaction->id, 'sender_initiate');

        // 2. Alerte au bénéficiaire (Recipient) : Code de retrait sécurisé indispensable au guichet
        $recipientMessage = sprintf(
            "Bonjour %s, vous avez recu un transfert de %.2f %s de la part de %s. Code secret de retrait obligatoire au guichet: %s. Ne le partagez jamais.",
            $transaction->recipient_name,
            $transaction->amount,
            $transaction->currency,
            $transaction->sender_name,
            $transaction->secure_code
        );

        $this->dispatchSms($transaction->recipient_phone, $recipientMessage, $transaction->id, 'recipient_ready');
    }

    /**
     * Notifier l'émetteur que son bénéficiaire vient de retirer l'argent au guichet.
     *
     * @param Transaction $transaction
     * @return void
     */
    public function sendPayoutConfirmation(Transaction $transaction): void
    {
        $message = sprintf(
            "Notification VisaMoney: Le transfert de %.2f %s a ete retire avec succes par le beneficiaire %s le %s. Ref: %s.",
            $transaction->amount,
            $transaction->currency,
            $transaction->recipient_name,
            $transaction->completed_at ? $transaction->completed_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i'),
            $transaction->reference
        );

        $this->dispatchSms($transaction->sender_phone, $message, $transaction->id, 'sender_payout_complete');
    }

    /**
     * Envoi brut du SMS avec gestion des logs d'audit et traitement des erreurs.
     * Les accents sont retirés (clean_accents) pour éviter le passage automatique en encodage UCS-2
     * qui divise par deux la taille maximale d'un SMS standard (70 caractères au lieu de 160).
     */
    protected function dispatchSms(string $phoneNumber, string $message, int $transactionId, string $alertType): bool
    {
        $cleanNumber = $this->formatInternationalPhoneNumber($phoneNumber);
        $cleanMessage = $this->stripAccents($message);

        try {
            // Simulation ou appel réel de l'API de votre fournisseur SMS (ex: MTN, Orange, Twilio, Africa's Talking)
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->smsApiKey,
                'Accept'        => 'application/json',
            ])->timeout(10)->post($this->smsApiUrl, [
                'sender'      => $this->smsSenderId,
                'recipient'   => $cleanNumber,
                'message'     => $cleanMessage,
                'metadata'    => [
                    'transaction_id' => $transactionId,
                    'type'           => $alertType
                ]
            ]);

            if ($response->successful()) {
                Log::info("SMS envoye avec succes ({$alertType}) au numero {$cleanNumber} pour la Transaction ID: {$transactionId}");
                return true;
            }

            Log::error("Echec de la passerelle SMS ({$alertType}) pour le numero {$cleanNumber}. Code HTTP: " . $response->status(), [
                'response' => $response->body()
            ]);

            return false;

        } catch (Exception $e) {
            Log::alert("Erreur critique lors de la tentative d'envoi SMS au numero {$cleanNumber}", [
                'exception' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            return false;
        }
    }

    /**
     * Nettoyer et normaliser le numéro au format international strict sans le signe '+' (ex: 2376XXXXXXXX)
     */
    private function formatInternationalPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Ajout automatique du code pays par défaut (ex: Cameroun 237) si le numéro est saisi au format local à 9 chiffres
        if (strlen($digits) === 9 && ($digits[0] === '6' || $digits[0] === '2')) {
            $digits = '237' . $digits;
        }

        return $digits;
    }

    /**
     * Supprimer les accents pour maximiser la compatibilité GSM 7-bit standard (160 caractères par SMS).
     */
    private function stripAccents(string $string): string
    {
        return strtr(
            utf8_decode($string),
            utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'),
            'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY'
        );
    }
}
