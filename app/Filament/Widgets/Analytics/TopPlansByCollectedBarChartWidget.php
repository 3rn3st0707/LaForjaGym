<?php

namespace App\Filament\Widgets\Analytics;

use App\Services\Analytics\AnalyticsService;
use App\Support\Analytics\AnalyticsDateRange;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Bar chart showing the top-performing plans by collected amount.
 */
class TopPlansByCollectedBarChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -37;

    protected ?string $heading = 'Top Plans (Collected)';

    protected ?string $maxHeight = '320px';

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = [
        'default' => 1,
        'md' => 1,
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $range = AnalyticsDateRange::fromFilters($this->pageFilters);

        $rows = app(AnalyticsService::class)
            ->topPlansByCollected($range, 7);

        $labels = $rows->pluck('plan_name')->values()->all();
        $values = $rows->pluck('collected')->values()->all();

        return [
            'datasets' => [
                [
                    'label' => 'Collected',
                    'data' => $values,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.75)',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
