<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\{Customer, Wallet, Transaction, TransactionEntry, FeesTable};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    public function searchByReference($reference) {
        $customer = Customer::with('user:id,first_name,last_name,phone_number')->where('reference', $reference)->firstOrFail();
        return response()->json(['data' => $customer]);
    }

    public function calculateFees(Request $request) {
        $amount = (float) $request->query('amount', 0);

        // 1. On cherche la règle (sans faire planter si elle n'existe pas)
        $rule = FeesTable::where('transaction_type', 'transfer')
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->where('is_active', true) // Sécurité : uniquement les grilles actives
            ->first();

        // 2. Si aucune règle n'est trouvée, on applique 0 partout
        if (!$rule) {
            return response()->json([
                'fee' => 0.0,
                'tax' => 0.0,
                'total' => $amount
            ]);
        }

        // 3. Si la règle existe, on fait le calcul classique
        $fee = (float) $rule->fixed_fee + ($amount * ((float) $rule->percentage_fee / 100));
        $tax = ($amount + $fee) * ((float) $rule->tax_percentage / 100);

        return response()->json([
            'fee' => $fee,
            'tax' => $tax,
            'total' => $amount + $fee + $tax
        ]);
    }

    public function execute(Request $request) {
        return DB::transaction(function () use ($request) {
            $data = $request->validate(['sender_id' => 'required', 'recipient_id' => 'required', 'amount' => 'required']);

            // Calcul final frais
            $fees = $this->calculateFees(new Request(['amount' => $data['amount']]))->getData();
            $totalDeduction = $data['amount'] + $fees->fee + $fees->tax;

            $sWallet = Wallet::where('owner_id', $data['sender_id'])->where('type', 'main')->lockForUpdate()->firstOrFail();
            $rWallet = Wallet::where('owner_id', $data['recipient_id'])->where('type', 'main')->lockForUpdate()->firstOrFail();

            if ($sWallet->balance < $totalDeduction) return response()->json(['message' => 'Solde insuffisant'], 422);

            $trx = Transaction::create([
                'uuid' => Str::uuid(), 'reference' => 'TRX-'.strtoupper(Str::random(10)),
                'type' => 'transfer', 'status' => 'completed', 'amount' => $data['amount'],
                'fees' => $fees->fee, 'taxes' => $fees->tax, 'sender_customer_id' => $data['sender_id']
            ]);

            // Mouvements
            $sWallet->decrement('balance', $totalDeduction);
            $rWallet->increment('balance', $data['amount']);
            Wallet::where('type', 'commission')->increment('balance', ($fees->fee + $fees->tax));

            return response()->json(['success' => true]);
        });
    }
}
