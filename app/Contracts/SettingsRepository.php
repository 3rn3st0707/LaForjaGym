<?php

namespace App\Contracts;

/**
 * Settings persistence abstraction.
 *
 * OSS default uses JSON storage, while the platform/tenancy implementation
 * can override this binding to store settings per-tenant in the database.
 */
interface SettingsRepository
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function put(array $settings): void;
}
