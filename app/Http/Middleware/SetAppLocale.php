<?php

namespace App\Http\Middleware;

use App\Contracts\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = config('app.supported_locales', []);
        if (! is_array($supportedLocales) || $supportedLocales === []) {
            $supportedLocales = [config('app.locale', 'en')];
        }

        $supportedLocales = array_values(array_filter(array_map('strval', $supportedLocales)));
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        $queryLocale = $request->query('locale');
        $queryLocale = is_string($queryLocale) ? trim($queryLocale) : null;

        $settingsLocale = null;
        try {
            $settings = app(SettingsRepository::class)->get();
            $candidate = data_get($settings, 'general.locale');
            $settingsLocale = is_string($candidate) ? trim($candidate) : null;
        } catch (\Throwable) {
            $settingsLocale = null;
        }

        $headerLocale = $request->getPreferredLanguage($supportedLocales);
        $headerLocale = is_string($headerLocale) ? trim($headerLocale) : null;

        $locale = $queryLocale ?: ($settingsLocale ?: ($headerLocale ?: (string) config('app.locale', 'en')));

        if (! in_array($locale, $supportedLocales, true)) {
            $locale = in_array($fallbackLocale, $supportedLocales, true) ? $fallbackLocale : $supportedLocales[0];
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
