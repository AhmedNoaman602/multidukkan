<?php

namespace App\Support;

use App\Http\Middleware\SetTimezone;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Converts a viewer's *calendar date* into the UTC instants that bound it.
 *
 * Instants are stored in UTC. A filter like "show me 2026-08-21" is a statement
 * about the viewer's local calendar, so it has to be translated into the pair of
 * UTC moments that day spans before it can be compared against a stored column.
 *
 * For a viewer in Africa/Cairo (UTC+3), "2026-08-21" means
 * 2026-08-20 21:00:00 UTC  ..  2026-08-21 20:59:59.999999 UTC
 *
 * All conversion goes through PHP's timezone database, so DST transitions are
 * handled per-date. No offset is ever hardcoded.
 *
 * Deliberately NOT for use with MySQL `date` columns (order_date, expense_date,
 * last_purchased_at). Those are calendar dates with no instant behind them and
 * must be compared as plain Y-m-d strings, never timezone-converted.
 */
class LocalDateRange
{
    /**
     * The VIEWER's timezone, resolved for this request by SetTimezone middleware.
     *
     * Use for anything the viewer is looking at in their own day — list filters,
     * "today" in validation, the dashboard.
     */
    public static function timezoneFor(Request $request): string
    {
        return $request->attributes->get(SetTimezone::ATTRIBUTE)
            ?? config('app.display_timezone');
    }

    /**
     * The SHOP's timezone. Deliberately ignores the request.
     *
     * Business reports are bounded by the shop's trading day: a daily report must
     * show the same figures whoever opens it and from wherever, so it must not vary
     * with X-Timezone. That is the one place where taking the viewer's zone would
     * be wrong.
     *
     * Single point of change if this ever becomes per-tenant.
     */
    public static function businessTimezone(): string
    {
        return config('app.business_timezone');
    }

    /**
     * First UTC instant of the given local calendar date. Null if no date given,
     * so callers can pass an optional filter straight through.
     */
    public static function startOfDay(?string $date, string $timezone): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date, $timezone)->startOfDay()->utc();
    }

    /**
     * Last UTC instant of the given local calendar date, inclusive to the
     * microsecond so `timestamp(6)` values at 23:59:59.999999 local are not
     * silently dropped by a `<=` comparison.
     */
    public static function endOfDay(?string $date, string $timezone): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date, $timezone)->endOfDay()->utc();
    }

    /**
     * Apply the standard year / month / date_from / date_to / date_exact filters to a
     * query, interpreting every one of them as the viewer's local calendar.
     *
     * Shared by the order and purchase-order list endpoints, which expose an identical
     * filter surface.
     */
    public static function apply($query, Request $request, string $column = 'created_at'): void
    {
        $timezone = self::timezoneFor($request);

        // A local month or year is a contiguous span of UTC instants, so it becomes a
        // range rather than whereYear/whereMonth (which bucket in UTC and would drop a
        // late-evening local record into the previous month).
        if ($request->filled('year') || $request->filled('month')) {
            $year = (int) ($request->input('year') ?: Carbon::now($timezone)->year);

            [$start, $end] = $request->filled('month')
                ? self::monthRange($year, (int) $request->input('month'), $timezone)
                : self::yearRange($year, $timezone);

            $query->whereBetween($column, [$start, $end]);
        }

        if ($from = self::startOfDay($request->input('date_from'), $timezone)) {
            $query->where($column, '>=', $from);
        }

        if ($to = self::endOfDay($request->input('date_to'), $timezone)) {
            $query->where($column, '<=', $to);
        }

        if ($request->filled('date_exact')) {
            $query->whereBetween($column, [
                self::startOfDay($request->input('date_exact'), $timezone),
                self::endOfDay($request->input('date_exact'), $timezone),
            ]);
        }
    }

    /**
     * The same filter surface, applied to a calendar DATE column such as
     * orders.order_date.
     *
     * No timezone conversion happens here, and none should. A DATE column holds a
     * label, not an instant — "2026-08-21" is the same square on the calendar for
     * every viewer, so the request's timezone is irrelevant to the comparison. The
     * viewer's zone is consulted only to decide which year "this month" falls in
     * when a month is given without one.
     *
     * Contrast apply() above, which exists because an instant column needs the
     * opposite treatment.
     */
    public static function applyCalendarDate($query, Request $request, string $column): void
    {
        if ($request->filled('year') || $request->filled('month')) {
            $year = (int) ($request->input('year') ?: Carbon::now(self::timezoneFor($request))->year);

            $query->whereYear($column, $year);

            if ($request->filled('month')) {
                $query->whereMonth($column, (int) $request->input('month'));
            }
        }

        if ($request->filled('date_from')) {
            $query->where($column, '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where($column, '<=', $request->input('date_to'));
        }

        if ($request->filled('date_exact')) {
            $query->where($column, $request->input('date_exact'));
        }
    }

    /**
     * The UTC instants bounding a local calendar year, as [start, end].
     */
    public static function yearRange(int $year, string $timezone): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, $timezone);

        return [$start->copy()->utc(), $start->copy()->endOfYear()->utc()];
    }

    /**
     * The UTC instants bounding a local calendar month, as [start, end].
     */
    public static function monthRange(int $year, int $month, string $timezone): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $timezone);

        return [$start->copy()->utc(), $start->copy()->endOfMonth()->utc()];
    }

    /**
     * "Now" as the viewer's local calendar date (Y-m-d).
     *
     * For comparing against MySQL `date` columns and for resolving "today" in
     * validation rules, where a UTC-based today is wrong for up to 3 hours a day.
     */
    public static function today(string $timezone): CarbonInterface
    {
        return Carbon::now($timezone)->startOfDay();
    }
}
