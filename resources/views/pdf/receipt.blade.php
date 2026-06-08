<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu de Transaction - {{ $transaction->reference }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 0;
            font-size: 14px;
        }
        .receipt-box {
            max-width: 600px;
            margin: auto;
            padding: 20px;
            border: 1px solid #eee;
            background-color: #fff;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .brand {
            font-size: 24px;
            font-weight: bold;
            color: #1a56db; /* Bleu pro */
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .subtitle {
            font-size: 12px;
            color: #777;
            margin-top: 5px;
        }
        .amount-card {
            background-color: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 25px;
            border: 1px solid #e2e8f0;
        }
        .amount-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
        }
        .amount-value {
            font-size: 28px;
            font-weight: 900;
            color: #0f172a;
            margin: 5px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            background-color: #def7ec;
            color: #03543f;
            font-size: 12px;
            font-weight: bold;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .info-table td {
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-table td.label {
            color: #64748b;
            font-weight: 500;
        }
        .info-table td.value {
            text-align: right;
            font-weight: bold;
            color: #0f172a;
        }
        .secure-code-box {
            background-color: #fef2f2;
            border: 1px dashed #f87171;
            border-radius: 6px;
            padding: 12px;
            text-align: center;
            margin-top: 20px;
        }
        .secure-code-title {
            font-size: 11px;
            color: #991b1b;
            font-weight: bold;
        }
        .secure-code-value {
            font-size: 18px;
            font-weight: bold;
            color: #b91c1c;
            letter-spacing: 2px;
            margin-top: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 11px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="receipt-box">
    <div class="header">
        <div class="brand">GUEN'S UNION</div>
        <div class="subtitle">Réseau de transfert d'argent & Services financiers</div>
    </div>

    <div class="amount-card">
        <div class="amount-title">Montant Total</div>
        <div class="amount-value">{{ number_format($transaction->amount + $transaction->fees + $transaction->taxes, 0, ',', ' ') }} {{ $transaction->currency }}</div>
        <div class="status-badge">{{ $transaction->status }}</div>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Référence</td>
            <td class="value">{{ $transaction->reference }}</td>
        </tr>
        <tr>
            <td class="label">Type de service</td>
            <td class="value">{{ $transaction->type }}</td>
        </tr>
        @if($transaction->sender_name)
            <tr>
                <td class="label">Expéditeur</td>
                <td class="value">{{ $transaction->sender_name }} ({{ $transaction->sender_phone }})</td>
            </tr>
        @endif
        @if($transaction->recipient_name)
            <tr>
                <td class="label">Bénéficiaire</td>
                <td class="value">{{ $transaction->recipient_name }} ({{ $transaction->recipient_phone }})</td>
            </tr>
        @endif
        <tr>
            <td class="label">Montant Net</td>
            <td class="value">{{ number_format($transaction->amount, 0, ',', ' ') }} {{ $transaction->currency }}</td>
        </tr>
        <tr>
            <td class="label">Frais</td>
            <td class="value">{{ number_format($transaction->fees, 0, ',', ' ') }} {{ $transaction->currency }}</td>
        </tr>
        <tr>
            <td class="label">Date & Heure</td>
            <td class="value">{{ $transaction->created_at->format('d/m/Y à H:i') }}</td>
        </tr>
    </table>

    @if($transaction->secure_code)
        <div class="secure-code-box">
            <div class="secure-code-title">CODE DE SÉCURITÉ DE RETRAIT</div>
            <div class="secure-code-value">{{ $transaction->secure_code }}</div>
        </div>
    @endif

    <div class="footer">
        <p>Ce document fait office de reçu officiel pour la transaction mentionnée ci-dessus.</p>
        <p><strong>GUEN'S UNION</strong> — Merci pour votre confiance.</p>
    </div>
</div>

</body>
</html>
