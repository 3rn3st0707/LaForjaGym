<?php

namespace App\Services;

use App\Contracts\SequenceRepository;
use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Support\Data;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JSON-backed sequence generator (OSS default).
 *
 * Reads prefix / last_number from SettingsRepository and also inspects the DB
 * to avoid collisions within the current fiscal span.
 */
class JsonSequenceRepository implements SequenceRepository
{
    public function __construct(
        protected SettingsRepository $settingsRepository,
    ) {}

    /**
     * Generate the next number for a given entity type.
     *
     * @param  class-string  $modelClass
     */
    public function generate(
        string $type,
        string $modelClass,
        ?string $dateString = null,
        ?string $modelColumn = 'number',
    ): string {
        $date = Helpers::parseDate($dateString);
        [$start, $end] = Helpers::getFiscalSpan($date);
        $shouldScopeToFiscalSpan = $date->between($start, $end);
        $settings = $this->settingsRepository->get();

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;
        $table = $model->getTable();

        $dateColumn = Schema::hasColumn($table, 'date')
            ? 'date'
            : 'created_at';

        $rawPrefix = data_get($settings, "{$type}.prefix", '');
        $rawSaved = data_get($settings, "{$type}.last_number", '');

        $prefix = trim(Data::string($rawPrefix), '-');
        $prefix = filled($prefix) ? $prefix : 'GY';
        $separator = $prefix !== '' ? '-' : '';
        $match = $prefix.$separator;

        $query = $modelClass::query()
            ->withoutGlobalScopes();

        // If the model uses SoftDeletes, include trashed rows to avoid reusing numbers
        // that still exist in the table (and may still be protected by unique indexes).
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true) && method_exists($query, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        $lastFromDb = $query
            ->when(
                $shouldScopeToFiscalSpan,
                fn ($q) => $q->whereBetween($dateColumn, [$start->toDateString(), $end->toDateString()]),
            )
            ->pluck($modelColumn ?? 'number')
            ->map(
                fn ($raw) => Str::of(Data::string($raw))
                    ->whenStartsWith($match, fn ($s) => $s->after($match))
                    ->__toString()
            )
            ->map(fn ($v) => is_numeric($v) ? (int) $v : 0)
            ->max() ?: 0;

        $lastFromSettings = Str::of(Data::string($rawSaved))
            ->whenStartsWith($match, fn ($s) => $s->after($match))
            ->__toString();
        $lastFromSettings = is_numeric($lastFromSettings)
            ? (int) $lastFromSettings
            : 0;

        $next = max($lastFromDb, $lastFromSettings) + 1;

        return str($prefix)
            ->when($separator !== '', fn ($s) => $s->append($separator))
            ->append((string) $next)
            ->__toString();
    }

    public function update(
        string $type,
        string $newNumber,
        ?string $date = null,
    ): void {
        $date = Helpers::parseDate($date);
        [$start, $end] = Helpers::getFiscalSpan($date);
        // If the fiscal year configuration creates a gap (date not between start/end),
        // still persist the last_number to prevent reusing identifiers.

        $settings = $this->settingsRepository->get();
        $rawPrefix = data_get($settings, "{$type}.prefix", 'GY');
        $prefix = trim(Data::string($rawPrefix), '-');

        $numericPart = Str::of($newNumber)
            ->match('/(\\d+)$/')
            ->__toString();

        if ($numericPart === '' || ! ctype_digit($numericPart)) {
            return;
        }

        $incoming = (int) $numericPart;
        $rawStored = data_get($settings, "{$type}.last_number", '');
        $storedNumeric = Str::of(Data::string($rawStored))
            ->match('/(\\d+)$/')
            ->__toString();
        $current = ctype_digit($storedNumeric) ? (int) $storedNumeric : 0;

        if ($incoming <= $current) {
            return;
        }

        if (! isset($settings[$type]) || ! is_array($settings[$type])) {
            $settings[$type] = [];
        }

        /** @var array<string, mixed> $typeSettings */
        $typeSettings = $settings[$type];
        $typeSettings['last_number'] = $incoming;
        $typeSettings['prefix'] = $prefix;
        $settings[$type] = $typeSettings;

        $this->settingsRepository->put($settings);
    }
}
