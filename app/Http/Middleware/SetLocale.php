<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    private const DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::localeFor($request));

        return $next($request);
    }

    public static function localeFor(Request $request): string
    {
        $locale = $request->header('X-Locale');

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }
}
