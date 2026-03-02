<?php

namespace App\Services;

use App\Contracts\SettingsRepository;

/**
 * JSON-backed settings repository (OSS default).
 *
 * Settings are stored under `storage/data/settingsData.json`.
 * The platform/tenancy package can override this binding to store settings per-tenant.
 */
class JsonSettingsRepository implements SettingsRepository
{
    private const SETTINGS_PATH = 'data/settingsData.json';

    private const EXAMPLE_SETTINGS_PATH = 'data/settingsData.json.example';

    /**
     * @var array<string, mixed>|null
     */
    protected static ?array $testOverride = null;

    /**
     * @param  array<string, mixed>|null  $override
     */
    public function setTestOverride(?array $override): void
    {
        static::$testOverride = $override;
    }

    public function get(): array
    {
        if (static::$testOverride !== null) {
            return $this->normalize(static::$testOverride);
        }

        $filePath = storage_path(self::SETTINGS_PATH);

        if (! file_exists($filePath)) {
            $this->initializeFile($filePath);
        }

        $settings = json_decode((string) file_get_contents($filePath), true) ?? [];

        return $this->normalize($settings);
    }

    public function put(array $settings): void
    {
        $filePath = storage_path(self::SETTINGS_PATH);

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        file_put_contents(
            $filePath,
            json_encode($this->normalize($settings), JSON_PRETTY_PRINT),
        );
    }

    private function initializeFile(string $filePath): void
    {
        $exampleFilePath = storage_path(self::EXAMPLE_SETTINGS_PATH);

        if (file_exists($exampleFilePath)) {
            copy($exampleFilePath, $filePath);

            return;
        }

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        file_put_contents($filePath, json_encode([
            'general' => [],
            'invoice' => [],
            'member' => [],
            'charges' => [],
            'expenses' => [],
            'subscriptions' => [],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalize(array $settings): array
    {
        foreach ([
            'general',
            'invoice',
            'member',
            'charges',
            'expenses',
            'subscriptions',
            'payments',
        ] as $key) {
            if (! array_key_exists($key, $settings) || ! is_array($settings[$key])) {
                $settings[$key] = [];
            }
        }

        if (
            ! array_key_exists('provider', $settings['payments']) ||
            ! is_string($settings['payments']['provider']) ||
            trim($settings['payments']['provider']) === ''
        ) {
            $settings['payments']['provider'] = 'stripe';
        }

        return $settings;
    }
}
