<?php

namespace App\Support;

// Money keeps 4 decimals and is rounded only past the 4th (W7, ADR-011). The money columns are
// decimal(12,4) and the request rules allow decimal:0,4 to match.
final class Money
{
    public const SCALE = 4;
}
