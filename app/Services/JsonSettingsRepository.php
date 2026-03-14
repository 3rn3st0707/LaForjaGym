<?php

namespace App\Services;

use App\Contracts\SettingsRepository;

/**
 * JSON-backed settings repository (OSS default).
 *
 * Settings are stored under `storage/data/settingsData.json`.
 * Other installations can override this binding to store settings elsewhere.
 */
class JsonSettingsRepository implements SettingsRepository
{
    private const SETTINGS_PATH = 'data/settingsData.json';

    private const EXAMPLE_SETTINGS_PATH = 'data/settingsData.json.example';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $cachedSettings = null;

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
        $this->cachedSettings = null;
    }

    public function get(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        if (static::$testOverride !== null) {
            return $this->cachedSettings = $this->normalize(static::$testOverride);
        }

        if (app()->runningUnitTests()) {
            $exampleFilePath = storage_path(self::EXAMPLE_SETTINGS_PATH);

            if (file_exists($exampleFilePath)) {
                $settings = json_decode((string) file_get_contents($exampleFilePath), true) ?? [];

                return $this->cachedSettings = $this->normalize($settings);
            }

            return $this->cachedSettings = $this->normalize([]);
        }

        $filePath = storage_path(self::SETTINGS_PATH);

        if (! file_exists($filePath)) {
            $this->initializeFile($filePath);
        }

        $settings = json_decode((string) file_get_contents($filePath), true) ?? [];

        return $this->cachedSettings = $this->normalize($settings);
    }

    public function put(array $settings): void
    {
        $normalized = $this->normalize($settings);

        if (app()->runningUnitTests()) {
            static::$testOverride = $normalized;
            $this->cachedSettings = $normalized;

            return;
        }

        $filePath = storage_path(self::SETTINGS_PATH);

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        file_put_contents(
            $filePath,
            json_encode($normalized, JSON_PRETTY_PRINT),
        );

        $this->cachedSettings = $normalized;
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
            'notifications',
        ] as $key) {
            if (! array_key_exists($key, $settings) || ! is_array($settings[$key])) {
                $settings[$key] = [];
            }
        }

        if (
            ! array_key_exists('locale', $settings['general']) ||
            (! is_string($settings['general']['locale']) && $settings['general']['locale'] !== null)
        ) {
            $settings['general']['locale'] = null;
        }

        if (
            ! array_key_exists('email', $settings['notifications']) ||
            ! is_array($settings['notifications']['email'])
        ) {
            $settings['notifications']['email'] = [];
        }

        foreach ([
            'enabled' => false,
            'auto_send_invoice_issued' => false,
            'auto_send_payment_receipt' => false,
            'invoice_subject_template' => 'Invoice {invoice_number} - {status}',
            'receipt_subject_template' => 'Payment received - {invoice_number}',
        ] as $key => $default) {
            if (! array_key_exists($key, $settings['notifications']['email'])) {
                $settings['notifications']['email'][$key] = $default;
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
