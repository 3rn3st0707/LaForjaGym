<?php

namespace App\Filament\Livewire;

use App\Contracts\SettingsRepository;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LocaleSwitcher extends Component
{
    public string $locale = 'en';

    /**
     * @var array<string, string>
     */
    private const LOCALE_FLAGS = [
        'en' => '🇺🇸',
        'fr' => '🇫🇷',
        'ar' => '🇸🇦',
    ];

    public function mount(): void
    {
        $this->locale = (string) app()->getLocale();
    }

    /**
     * @return array<string, array{label: string, flag: string}>
     */
    public function getOptionsProperty(): array
    {
        $supported = config('app.supported_locales', []);
        if (! is_array($supported) || $supported === []) {
            $supported = [(string) config('app.locale', 'en')];
        }

        $options = [];

        foreach (array_values(array_filter(array_map('strval', $supported))) as $locale) {
            $options[$locale] = [
                'label' => (string) __("app.locales.{$locale}"),
                'flag' => self::LOCALE_FLAGS[$locale] ?? '🏳️',
            ];
        }

        return $options;
    }

    public function getCurrentFlagProperty(): string
    {
        return self::LOCALE_FLAGS[$this->locale] ?? '🏳️';
    }

    public function setLocale(string $locale): mixed
    {
        $options = $this->options;

        if (! array_key_exists($locale, $options)) {
            return null;
        }

        /** @var SettingsRepository $repository */
        $repository = app(SettingsRepository::class);

        $settings = $repository->get();
        data_set($settings, 'general.locale', $locale);
        $repository->put($settings);

        $this->locale = $locale;

        app()->setLocale($locale);

        Notification::make()
            ->title(__('app.notifications.language_updated'))
            ->success()
            ->send();

        $referer = request()->headers->get('referer');
        $redirectTo = is_string($referer) && $referer !== '' ? $referer : url('/');

        return redirect()->to($redirectTo);
    }

    public function render(): View
    {
        return view('filament.components.locale-switcher');
    }
}
