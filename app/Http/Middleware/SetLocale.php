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
        $locale = $request->header('X-Locale');

        App::setLocale(
            in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE
        );

        return $next($request);
    }
}
