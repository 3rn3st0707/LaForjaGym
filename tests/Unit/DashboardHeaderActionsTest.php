<?php

use App\Filament\Pages\Dashboard;

it('does not register quick actions in the dashboard header', function (): void {
    $dashboard = new Dashboard;
    $dashboard->cacheInteractsWithHeaderActions();

    $actions = $dashboard->getCachedHeaderActions();

    expect($actions)->toHaveCount(0);
});
