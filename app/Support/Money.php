<?php

namespace App\Support;

// Two money scales (W7, ADR-011). Prices and costs keep 4 decimals (a box of 1000 for 48.50 is
// 0.0485 a piece). Totals, payments and the ledger are rounded to piasters, so the fraction left
// by a 4-decimal price is dropped at the total and never becomes a credit or a debt.
final class Money
{
    public const PRICE_SCALE = 4;

    public const TOTAL_SCALE = 2;

    // Float sums and differences carry noise (2.885 − 0.92 gives 1.96499…) that flips the round to
    // piasters, so the amount is snapped back to 4 decimals first. Only for amounts with at most
    // 4 real decimals (prices × whole quantities ± a 2-decimal discount); more would round twice.
    public static function total(float $amount): float
    {
        return round(round($amount, self::PRICE_SCALE), self::TOTAL_SCALE);
    }
}
