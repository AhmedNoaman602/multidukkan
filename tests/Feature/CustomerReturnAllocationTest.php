<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Customer returns over cost allocations (D29, D30). The return endpoint and its payload
 * belong to slice 3a and are not designed yet, so these scenarios are recorded as
 * incomplete instead of guessing an API. Stage 1 already covers what returns depend on:
 * one allocation per addition, the returned ≤ sold check, and restoring stock at an
 * allocation's cost (OrderItemCostTest).
 *
 * Shared scenario: one visible line of 4 — A1: 2 @ 10, A2: 2 @ 12 (cost 44), sold at 20 each.
 */
class CustomerReturnAllocationTest extends TestCase
{
    private function slice3a(string $scenario): void
    {
        $this->markTestIncomplete("Slice 3a (return endpoint not designed yet). Scenario: {$scenario}");
    }

    public function test_a_partial_return_takes_the_oldest_allocation_first(): void
    {
        $this->slice3a('return 1 → A1 returned 1; one return row (A1, qty 1, unit_cost 10, original_sale); stock +1 at 10; average recalculated; report: sales −20, cost −10.');
    }

    public function test_returning_the_rest_spans_two_allocations(): void
    {
        $this->slice3a('after returning 1, return 3 → A1 1 @ 10 + A2 2 @ 12 = 34; two return rows, one visible line on the return; total reversed 44 = cost sold; net sales and profit of the order = 0.');
    }

    public function test_returning_more_than_remains_is_refused(): void
    {
        $this->slice3a('after returning 4, return 1 more → 422; no return row, no stock or average change; checked under the allocation row locks (CHECK as backstop).');
    }

    public function test_a_return_of_an_unknown_cost_allocation_follows_d27(): void
    {
        $this->slice3a('allocation with unit_cost null → stock back at today\'s cost_price (average_at_return, average unchanged); the return is left out of profit like the sale.');
    }

    public function test_two_returns_of_one_order_at_once_never_over_return(): void
    {
        $this->slice3a('concurrency: two returns of 3 each on a line of 4 → one succeeds, the other is refused; queued at L0/L1, allocations locked in id order.');
    }
}
