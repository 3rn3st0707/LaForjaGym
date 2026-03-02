<?php

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Member;
use App\Models\Subscription;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard demo seeder creates enough data for analytics widgets', function (): void {
    $this->seed(DashboardDemoSeeder::class);

    expect(Member::query()->count())->toBeGreaterThanOrEqual(200);
    expect(Subscription::query()->count())->toBeGreaterThanOrEqual(200);
    expect(Invoice::query()->count())->toBeGreaterThanOrEqual(200);
    expect(InvoiceTransaction::query()->count())->toBeGreaterThan(0);
    expect(Expense::query()->count())->toBeGreaterThanOrEqual(150);
});
