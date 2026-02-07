<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Balance;
use Carbon\Carbon;

class BalanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = Customer::all();

        foreach ($customers as $customer) {
            $runningBalance = 0;
            
            // Create 3-7 transactions for each customer
            $numTransactions = rand(3, 7);
            
            for ($i = 0; $i < $numTransactions; $i++) {
                // Alternating between invoice and payment, but 70% chance of invoice
                $type = (rand(1, 100) <= 70) ? 'invoice' : 'payment';
                
                $amount = rand(500, 5000);
                
                if ($type === 'invoice') {
                    $runningBalance += $amount;
                    $description = 'Invoice for order #' . rand(1000, 9999);
                    $reference = Balance::generateReference('invoice');
                } else {
                    // Don't make a payment larger than the current balance (usually)
                    $amount = min($amount, $runningBalance);
                    if ($amount <= 0) continue; // Skip if no balance to pay
                    
                    $runningBalance -= $amount;
                    $description = 'Customer payment';
                    $reference = Balance::generateReference('payment');
                }

                Balance::create([
                    'customer_id' => $customer->id,
                    'type' => $type,
                    'reference' => $reference,
                    'description' => $description,
                    'amount' => $amount,
                    'running_balance' => $runningBalance,
                    'payment_method' => $type === 'payment' ? 'cash' : null,
                    'created_at' => Carbon::now()->subDays(rand(1, 60)),
                ]);
            }
        }
    }
}
