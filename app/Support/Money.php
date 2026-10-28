<?php

namespace App\Support;

// Two money scales (W7, ADR-011). Prices and costs keep 4 decimals (a box of 1000 for 48.50 is
// 0.0485 a piece). Totals, payments and the ledger are rounded to piasters, so the fraction left
// by a 4-decimal price is dropped at the total and never becomes a credit or a debt.
final class Money
{
    public const PRICE_SCALE = 4;

    public const TOTAL_SCALE = 2;
}
