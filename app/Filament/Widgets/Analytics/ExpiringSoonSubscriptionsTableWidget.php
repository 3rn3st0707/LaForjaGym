<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Helpers\Helpers;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard widget listing subscriptions that are expiring soon.
 *
 * This is date-driven (not status-driven) so it stays accurate even if the
 * status sync command hasn't run yet.
 */
class ExpiringSoonSubscriptionsTableWidget extends TableWidget
{
    protected static ?int $sort = -39;

    protected static ?string $heading = 'Expiring Soon';

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = [
        'default' => 1,
        'md' => 3,
    ];

    /**
     * Build the query that powers the expiring soon table.
     */
    protected function getExpiringSoonQuery(): Builder
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $end = $today->addDays(Helpers::getSubscriptionExpiringDays());

        return Subscription::query()
            ->with(['member', 'plan'])
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $end->toDateString())
            ->orderBy('end_date');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getExpiringSoonQuery())
            ->columns([
                TextColumn::make('member.name')
                    ->label('Member')
                    ->description(fn (Subscription $record): string => (string) ($record->member->code ?? ''))
                    ->wrap(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->description(fn (Subscription $record): string => (string) ($record->plan->code ?? ''))
                    ->wrap(),
                TextColumn::make('end_date')
                    ->label('Ends')
                    ->date()
                    ->sortable(),
                TextColumn::make('days_left')
                    ->label('Days Left')
                    ->alignRight()
                    ->state(function (Subscription $record): string {
                        $today = CarbonImmutable::today(config('app.timezone'));
                        $endDate = CarbonImmutable::parse($record->end_date, config('app.timezone'))->startOfDay();

                        $days = max($today->diffInDays($endDate, false), 0);

                        return $days === 1 ? '1 day' : "{$days} days";
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('renew')
                        ->label('Renew')
                        ->icon('heroicon-m-arrow-path')
                        ->color('success')
                        ->modalHeading('Renew Subscription')
                        ->modalSubmitActionLabel('Renew')
                        ->modalWidth('6xl')
                        ->closeModalByClickingAway(false)
                        ->visible(function (Subscription $record): bool {
                            if ($record->renewals()->exists()) {
                                return false;
                            }

                            $today = CarbonImmutable::today(config('app.timezone'))->toDateString();

                            return ! Subscription::query()
                                ->where('member_id', $record->member_id)
                                ->whereDate('start_date', '>', $today)
                                ->exists();
                        })
                        ->schema(fn (Subscription $record): array => SubscriptionForm::renewSchema($record))
                        ->action(function (Subscription $record, array $data): void {
                            SubscriptionForm::handleRenew($record, $data);
                        }),
                    ViewAction::make()
                        ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->emptyStateHeading('No subscriptions expiring soon')
            ->emptyStateDescription('You’re all set — nothing is ending in the next few days.')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50]);
    }
}
