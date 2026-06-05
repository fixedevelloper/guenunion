<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\FraudCheck;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FraudCheckService
{
    /**
     * Analyse le risque de fraude pour n'importe quel type d'opération financière.
     */
    public function analyze(string $operationType, ?int $customerId, float $amount, array $context = []): array
    {
        $score = 0;
        $reasons = [];

        // 1. RÈGLE COMMUNE : Seuil de conformité sur le montant (Normes AML / COBAC)
        if ($amount >= 1000000) {
            $score += 35;
            $reasons[] = "Montant unitaire élevé (>= 1M XAF) pour un(e) {$operationType}.";
        }

        // 2. RÈGLES BEHAVIORALES : Vélocité (Fréquence des opérations du client aujourd'hui)
        if ($customerId) {
            $todayCount = Transaction::where('sender_customer_id', $customerId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            if ($todayCount >= 10) {
                $score += 50;
                $reasons[] = "Vélocité suspecte : {$todayCount} opérations globales aujourd'hui.";
            }
        }

        // 3. ROUTAGE DE LA SÉCURITÉ PAR SEGMENT MÉTIEU
        switch ($operationType) {

            // --- SEGMENT A : OPÉRATIONS EN ESPÈCES ET GUICHET ---
            case 'cash_in':
            case 'deposit':
                // Risque de fractionnement des dépôts pour blanchiment (Anti-Smurfing)
                if ($amount >= 500000 && ($context['is_anonymous'] ?? false)) {
                    $score += 40;
                    $reasons[] = "Dépôt d'espèces important sans identification client complète.";
                }
                break;

            case 'cash_out':
            case 'withdrawal':
                // Risque de vidage de compte immédiat (Phishing / Vol de carte ou de compte)
                if ($amount >= 500000) {
                    $score += 25;
                    $reasons[] = "Retrait massif d'espèces (Risque immédiat de liquidité et de conformité).";
                }
                break;

            // --- SEGMENT B : TRANSFERTS ET ENVOIS DE FONDS ---
            case 'transfer':
            case 'peer_to_peer':
            case 'remittance':
                // Risque AML lié aux transferts rapides ou corridors internationaux
                $senderPhone = $context['sender_phone'] ?? null;
                if ($senderPhone) {
                    // Calcul du cumul journalier pour cet expéditeur
                    $dailySum = Transaction::where('sender_phone', $senderPhone)
                        ->where('created_at', '>=', now()->startOfDay())
                        ->whereIn('status', ['completed', 'paid', 'processing'])
                        ->sum('amount');

                    if (($dailySum + $amount) > 5000000) {
                        $score += 80; // Score immédiatement bloquant
                        $reasons[] = "Plafond AML dépassé : Cumul d'envois journalier supérieur à 5M XAF.";
                    }
                }

                // Protection contre le détournement de fonds en interne (Complicité caissier-bénéficiaire)
                if ($operationType === 'remittance' && isset($context['original_transaction'])) {
                    $currentStaffId = auth()->id();
                    if ($context['original_transaction']->initiator_id === $currentStaffId) {
                        $score += 100; // Blocage absolu
                        $reasons[] = "Fraude interne : Interdiction de décaisser un mandat initié par soi-même.";
                    }
                }
                break;

            // --- SEGMENT C : PAIEMENTS MARCHANDS ET FACTURES ---
            case 'merchant_payment':
            case 'bill_payment':
                // Risque de piratage de compte (Achat compulsif / Virement vers un faux marchand)
                if ($amount >= 2000000) {
                    $score += 30;
                    $reasons[] = "Paiement marchand inhabituellement élevé.";
                }
                break;

            // --- SEGMENT D : OPÉRATIONS TECHNIQUES, LIVRES ET EXTORNES ---
            case 'adjustment':
            case 'refund':
                // Les ajustements manuels par un admin/staff sont des cibles prioritaires de fraude
                $score += 45;
                $reasons[] = "Opération d'ajustement ou de remboursement (Nécessite une double validation hiérarchique).";
                break;

            case 'commission':
                // Risque technique : Tentative de forcer une génération de commission virtuelle
                if (!auth()->user()?->hasRole('system-core')) {
                $score += 90;
                $reasons[] = "Alerte intrusion : Génération manuelle de commission non autorisée.";
            }
                break;

            default:
                Log::warning("[FRAUD-SERVICE] Type d'opération inconnu passé à l'analyseur : {$operationType}");
                break;
        }

        // 4. SYNTHÈSE DES COMPORTEMENTS DU SCORE
        $finalScore = min($score, 100);

        return [
            'risk_score' => $finalScore,
            'is_flagged' => $finalScore >= 40, // À surveiller / Demander OTP ou double validation
            'is_blocked' => $finalScore >= 80, // Bloqué immédiatement à l'exécution
            'reason'     => empty($reasons) ? 'Opération conforme aux indicateurs de sécurité.' : implode(' | ', $reasons)
        ];
    }

    /**
     * Enregistre le contrôle de fraude de manière immuable dans le Ledger.
     * @param int $transactionId
     * @param array $analysis
     * @return FraudCheck
     */
    public function logCheck(int $transactionId, array $analysis): FraudCheck
    {
        return FraudCheck::create([
            'uuid'           => (string) Str::uuid(),
            'transaction_id' => $transactionId,
            'risk_score'     => $analysis['risk_score'],
            'is_flagged'     => $analysis['is_flagged'],
            'reason'         => $analysis['reason']
        ]);
    }
}
