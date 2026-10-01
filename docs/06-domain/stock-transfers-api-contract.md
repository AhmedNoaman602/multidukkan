# Part 3 — API Contract Change

A walkthrough of one small change to `POST /stock-transfers`: the request now **rejects** `type`, `status` and `order_id` instead of silently ignoring them. For the transfer domain itself, see [stock-transfers.md](stock-transfers.md).

## 1. What was the old behavior?

`StoreStockTransferRequest` had **no rules at all** for `type`, `status` or `order_id`:

```php
// Old rules() — the three fields simply aren't mentioned
'from_warehouse_id'  => [...],
'to_warehouse_id'    => [...],
'notes'              => 'nullable|string|max:500',
'items'              => 'required|array|min:1|max:50',
'items.*.product_id' => [...],
'items.*.quantity'   => 'required|integer|min:1|max:1000000',
'items.*.unit_type'  => 'nullable|in:base,secondary',
```

Laravel's `$request->validated()` only returns fields that have a rule. So anything else the client sent was **dropped before it reached the service**. The service then set the real values itself:

```php
// StockTransferService::transfer() — unchanged by this work
'type'   => StockTransfer::TYPE_MANUAL,
'status' => StockTransfer::STATUS_COMPLETED,
// order_id is never set → stays null
```

So for a request like `{"type": "replenishment", "status": "PENDING", "order_id": 42, ...valid fields}`:

- **`type`** — dropped. The transfer was saved as `manual`.
- **`status`** — dropped. The transfer was saved as `COMPLETED`.
- **`order_id`** — dropped. The transfer was saved with `order_id = null`.
- The response was **`201 Created`**, and stock moved.

**Why that wasn't ideal.** Nothing unsafe could happen — the client could never actually create a replenishment. But the client asked for one thing and silently got another, with a success response. A frontend bug or a confused API user would see `201` and believe the server accepted what they sent. The real rule ("you can't set these") existed only as an invisible side effect of `validated()`.

## 2. What is the new behavior?

Three rules were added to `StoreStockTransferRequest::rules()`:

```php
'type'     => 'prohibited',
'status'   => 'prohibited',
'order_id' => 'prohibited',
```

and the class comment now says what the endpoint is for:

```php
// This endpoint creates manual transfers only. type, status and order_id are set by the
// server, and replenishment transfers are only ever created by the sale that needs them.
```

**Why `prohibited`.** It's Laravel's built-in rule for "this field must not be present." It states the rule *in the validation layer*, where every other input rule lives — the FormRequest is the gatekeeper (project rule). No new code, no custom rule.

**What happens now** when any of the three is sent with a value:

- Validation fails for that field. If several are sent, each gets its own error.
- The response is **`422`** with the usual Laravel shape, e.g. `{"message": "...", "errors": {"type": [...], "order_id": [...]}}`.

**Why `422`.** 422 means "your request is well-formed but contains invalid input." That's exactly the situation: the JSON is fine, but it includes fields this endpoint doesn't accept. It's also what every other validation failure in the API returns, so the frontend handles it the same way.

**What happens to the transfer and stock.** Nothing. A FormRequest validates **before** the controller method runs. The controller never calls `StockTransferService`, so no transfer header, no lines, no inventory change and no `inventory_transactions` rows are written. There's nothing to roll back because nothing started.

## 3. Old vs New

| | Old | New |
|---|---|---|
| `type` | Silently dropped; saved as `manual`; `201` | `422`, error on `type`; nothing saved |
| `status` | Silently dropped; saved as `COMPLETED`; `201` | `422`, error on `status`; nothing saved |
| `order_id` | Silently dropped; saved as `null`; `201` | `422`, error on `order_id`; nothing saved |

## 4. Why did we make this change?

- **This endpoint does one thing: manual transfers.** Its type is always `manual`, its status is always `COMPLETED` (the MVP has no approval step), and it's never linked to an order.
- **These fields are server-controlled.** A `replenishment` transfer is created by the system *during a sale*, linked to that sale's order, with no approval. If a client could set `type` or `order_id`, it could fake one — a transfer that looks like a sale caused it. The server decides these values; the client has no say.
- **Rejecting is clearer than ignoring.** Silently ignoring says "sure" and does something else. Rejecting says "this field isn't part of this endpoint" at the moment of the mistake, so a bug surfaces in development instead of hiding in production data.
- **The contract is now explicit.** Reading `StoreStockTransferRequest` tells you everything the endpoint accepts *and* what it refuses. Before, the refusal was implicit — you had to know how `validated()` behaves.
- **It's a contract change, not a redesign.** The service, policy, controller, tables and stock movement are untouched. The safety was already there (the service hardcodes the values). The only difference is how the API *responds* to a client that sends these fields: `422` instead of a misleading `201`.

## 5. Tests

Both tests are in `tests/Feature/StockTransferTest.php`. A small helper creates a real order for them:

```php
private function order(): Order { /* Order::factory() in this tenant and store */ }
```

**`test_client_cannot_create_a_replenishment_or_link_an_order`** *(changed)*

- **Sends:** a valid manual transfer (2 boxes, storage → shelf) plus `type: replenishment`, `status: PENDING`, `order_id: <real order>`.
- **Before:** expected `201` and checked the transfer was saved as `manual` / `COMPLETED` / no order.
- **Now:** expects `422` with validation errors on `type`, `status` and `order_id`.
- **Verifies (`assertNothingMoved()`):** 0 `stock_transfers`, 0 `stock_transfer_items`, 0 `inventory_transactions`, shelf still 10, storage still 100.

**`test_each_server_controlled_field_is_rejected_on_its_own`** *(added)*

- **Sends:** the same valid transfer three times, each with just one extra field — `type: manual`, then `status: COMPLETED`, then `order_id: <real order>`.
- **Expects:** `422` with an error on that one field, every time.
- **Why these values:** `manual` and `COMPLETED` are exactly what the server would set anyway. The test proves the rule is "don't send it", not "don't send a *wrong* value".
- **Verifies:** the same `assertNothingMoved()` after all three requests.

**Why check the database after a 422.** A status code only tells you what the server *said*. Checking the tables proves what it *did*. The worst bug here would be a 422 response after stock had already moved — the user thinks it failed, retries, and stock moves twice. Asserting zero rows and unchanged quantities rules that out. (It's also a project test rule: negative tests must verify DB state.)

## 6. Edge case: `order_id: null`

**What happens.** Laravel's `prohibited` fails only when the field is present **and not empty**. `null`, `""` and `[]` count as empty. So a request with `"order_id": null` passes validation, and the transfer is created normally (`201`) — same for `"type": ""`.

**Why it's not an order-linking problem.** `validated()` does pass the empty value through (`['order_id' => null, ...]`), but `StockTransferService::transfer()` never reads `type`, `status` or `order_id` from `$data` — it hardcodes `manual` and `COMPLETED` and never sets an order. And `null` is the value the server stores anyway. There's no way for an empty value to attach a transfer to an order or change its type.

**Leave it or change it?** Leave it. Many clients send `null` for "no value" (forms, generic serializers); rejecting them would add friction and protect nothing. If we ever wanted strict "must not even be present" behavior, a custom rule or `missing` would do it — but that's strictness for its own sake, not safety.

## 7. Bigger picture

Part 3 has layers, each with one job:

1. **`StoreStockTransferRequest`** — what input is allowed (shape, tenant ownership, same store, and now: no server-controlled fields).
2. **`StockTransferPolicy`** — who may do it (admin, or the source store's manager).
3. **`StockTransferService`** — the business operation: create the manual, completed transfer and its lines in one transaction.
4. **`InventoryService::transferStock()`** — the stock movement itself: lock rows in id order, check, move, log `TRANSFER_OUT` + `TRANSFER_IN`.

This change only touched layer 1. It makes the request layer say out loud what layers 3 and 4 already guaranteed: this endpoint creates manual transfers, and only the server decides type, status and order link.

---
**Related documents**: [Stock Transfers](stock-transfers.md). **Last reviewed**: 2026-10-01.
