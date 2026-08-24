<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the viewer's timezone for the current request.
 *
 * Mirrors SetLocale: the client states a preference in a header, we validate it
 * and fall back to a configured default. The resolved zone is stashed on the
 * request so controllers read one value instead of re-parsing the header.
 *
 * This zone answers exactly one question: "what calendar day is it where the
 * viewer is standing?" It is used to convert date-filter boundaries and to
 * resolve "today" during validation. It never changes how an instant is stored
 * (always UTC) or serialized (always ISO-8601 with a Z).
 */
class SetTimezone
{
    public const ATTRIBUTE = 'timezone';

    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $request->header('X-Timezone');

        // timezone_identifiers_list() is the authoritative set of zones PHP can
        // actually resolve — anything else (junk, an offset string, an injection
        // attempt) falls back rather than reaching Carbon.
        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = config('app.display_timezone');
        }

        $request->attributes->set(self::ATTRIBUTE, $timezone);

        return $next($request);
    }
}
