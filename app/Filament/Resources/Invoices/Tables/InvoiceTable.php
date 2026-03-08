<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Subscription;
use App\Services\Email\InvoiceEmailService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class InvoiceTable
{
    /**
     * Configure the invoice table schema.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('number')
                    ->label('Invoice No.')
                    ->sortable(),
                TextColumn::make('subscription.member.name')
                    ->label('Subscription')
                    ->description(fn ($record): string => $record->subscription->member->code),
                TextColumn::make('date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('subscription_fee')
                    ->label('Fee')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state)),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state)),
                TextColumn::make('tax')
                    ->label('Tax')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state)),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state)),
                TextColumn::make('due_amount')
                    ->label('Due')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state)),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        DatePicker::make('date_from'),
                        DatePicker::make('date_to'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    }),
            ])
            ->emptyStateIcon(
                ! Subscription::exists()
                    ? 'heroicon-o-ticket'
                    : 'heroicon-o-document-text'
            )
            ->emptyStateHeading(function ($livewire): string {
                // If no subscription exist
                if (! Subscription::exists()) {
                    return 'No Subscriptions';
                }

                $dates = $livewire->getTableFilterState('date') ?? [];
                [$from, $to] = [$dates['date_from'] ?? null, $dates['date_to'] ?? null];
                $tab = $livewire->activeTab;
                $heading = [
                    'issued' => 'No Issued Invoices',
                    'partial' => 'No Partial Invoices',
                    'overdue' => 'No Overdue Invoices',
                    'paid' => 'No Paid Invoices',
                    'refund' => 'No Refund Invoices',
                    'cancelled' => 'No Cancelled Invoices',
                ][$tab] ?? 'No Invoices';

                if (! $from && ! $to) {
                    return $heading;
                }

                if ($tab === 'all') {
                    return 'No Invoices in Date Range';
                }

                return Subscription::where('status', $tab)->exists()
                    ? ($heading.' in Date Range')
                    : $heading;
            })
            ->emptyStateDescription(function ($livewire): ?string {
                // If no subscriptions exist
                if (! Subscription::exists()) {
                    return 'Create a subscription to get started.';
                }

                $dates = $livewire->getTableFilterState('date') ?? [];
                [$fromRaw, $toRaw] = [$dates['date_from'] ?? null, $dates['date_to'] ?? null];
                $tab = $livewire->activeTab;
                $defaultDescriptions = [
                    'issued' => 'There are no invoices marked as issued.',
                    'partial' => 'There are no invoices marked as partially paid.',
                    'overdue' => 'There are no invoices marked as overdue.',
                    'paid' => 'There are no invoices marked as paid.',
                    'refund' => 'There are no invoices marked as refund.',
                    'cancelled' => 'There are no invoices marked as cancelled.',
                ];

                if (! $fromRaw && ! $toRaw) {
                    return $defaultDescriptions[$tab] ?? 'Create a invoice to get started.';
                }

                $from = $fromRaw ? Carbon::parse($fromRaw)->format('d-m-Y') : 'the beginning';
                $to = $toRaw ? Carbon::parse($toRaw)->format('d-m-Y') : 'today';

                if ($tab === 'all') {
                    return "We found no invoices created between {$from} and {$to}.";
                }

                if (! Invoice::where('status', $tab)->exists()) {
                    return $defaultDescriptions[$tab] ?? 'Create a invoice to get started.';
                }

                return "We found no {$tab} invoices between {$from} and {$to}.";
            })
            ->emptyStateActions([
                Action::make('create_subscription')
                    ->label('New subscription')
                    ->url(fn () => route('filament.admin.resources.subscriptions.create'))
                    ->icon('heroicon-o-plus')
                    ->hidden(fn () => Subscription::exists()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ActionGroup::make([
                        Action::make('heading_status')
                            ->label('Manage Invoice')
                            ->disabled()
                            ->color('gray')
                            ->visible(fn (Invoice $record): bool => ! in_array($record->status->value, ['refund', 'cancelled'], true)),
                        Action::make('add_payment')
                            ->label('Add Payment')
                            ->color('info')
                            ->icon('heroicon-s-banknotes')
                            ->modalWidth('md')
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Amount ('.Helpers::getCurrencyCode().')')
                                    ->required()
                                    ->numeric()
                                    ->reactive()
                                    ->default(fn (Invoice $record): float => (float) ($record->due_amount ?? 0))
                                    ->placeholder('Enter amount')
                                    ->validationAttribute('amount')
                                    ->helperText(fn (Invoice $record): string => 'Due Amount: '.Helpers::formatCurrency($record->due_amount))
                                    ->maxValue(fn (Invoice $record): float => max((float) $record->due_amount, 0))
                                    ->minValue(0.01)
                                    ->afterStateUpdated(function ($livewire, TextInput $component) {
                                        $livewire->validateOnly($component->getStatePath());
                                    }),
                                DateTimePicker::make('occurred_at')
                                    ->label('Paid at')
                                    ->seconds(false)
                                    ->timezone(config('app.timezone'))
                                    ->default(fn (): string => now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                                    ->required(),
                                Select::make('payment_method')
                                    ->label('Payment Method')
                                    ->options([
                                        'cash' => 'Cash',
                                        'cheque' => 'Cheque',
                                    ])
                                    ->default(fn (Invoice $record): ?string => $record->payment_method ?: 'cash')
                                    ->nullable(),
                                Textarea::make('note')
                                    ->label('Note')
                                    ->rows(2)
                                    ->placeholder('Optional note…'),
                            ])
                            ->action(function (Invoice $record, array $data) {
                                $amount = (float) ($data['amount'] ?? 0);
                                $amount = min(max($amount, 0), (float) ($record->due_amount ?? 0));

                                if ($amount <= 0) {
                                    Notification::make()
                                        ->title('Invalid payment amount')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $record->transactions()->create([
                                    'type' => 'payment',
                                    'amount' => $amount,
                                    'occurred_at' => $data['occurred_at'] ?? now()->timezone(config('app.timezone')),
                                    'payment_method' => $data['payment_method'] ?? null,
                                    'note' => $data['note'] ?? null,
                                    'created_by' => auth()->id(),
                                ]);

                                $record->refresh();

                                $paidLabel = Helpers::formatCurrency($record->paid_amount);

                                Notification::make()
                                    ->title($record->status->value === 'paid' ? 'Invoice paid' : 'Payment added')
                                    ->success()
                                    ->body("Invoice #{$record->number} paid total: {$paidLabel}.")
                                    ->send();
                            })
                            ->visible(fn (Invoice $record): bool => in_array($record->status->value, ['issued', 'overdue', 'partial'], true) && (float) $record->due_amount > 0),
                        Action::make('refund')
                            ->label('Refund')
                            ->color('warning')
                            ->icon('heroicon-s-arrow-path')
                            ->modalWidth('md')
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Refund Amount ('.Helpers::getCurrencyCode().')')
                                    ->required()
                                    ->numeric()
                                    ->reactive()
                                    ->placeholder('Enter amount')
                                    ->helperText(fn (Invoice $record): string => 'Refundable: '.Helpers::formatCurrency($record->paid_amount))
                                    ->maxValue(fn (Invoice $record): float => max((float) $record->paid_amount, 0))
                                    ->minValue(0.01)
                                    ->afterStateUpdated(function ($livewire, TextInput $component) {
                                        $livewire->validateOnly($component->getStatePath());
                                    }),
                                DateTimePicker::make('occurred_at')
                                    ->label('Refunded at')
                                    ->seconds(false)
                                    ->timezone(config('app.timezone'))
                                    ->default(fn (): string => now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                                    ->required(),
                                Textarea::make('note')
                                    ->label('Note')
                                    ->rows(2)
                                    ->placeholder('Optional note…'),
                            ])
                            ->action(function (Invoice $record, array $data) {
                                $amount = (float) ($data['amount'] ?? 0);
                                $amount = min(max($amount, 0), (float) ($record->paid_amount ?? 0));

                                if ($amount <= 0) {
                                    Notification::make()
                                        ->title('Invalid refund amount')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $record->transactions()->create([
                                    'type' => 'refund',
                                    'amount' => $amount,
                                    'occurred_at' => $data['occurred_at'] ?? now()->timezone(config('app.timezone')),
                                    'note' => $data['note'] ?? null,
                                    'created_by' => auth()->id(),
                                ]);

                                $record->refresh();

                                Notification::make()
                                    ->title('Invoice refunded')
                                    ->warning()
                                    ->body("Invoice #{$record->number} refunded and closed.")
                                    ->send();
                            })
                            ->visible(fn (Invoice $record): bool => (float) $record->paid_amount > 0 && ! in_array($record->status->value, ['refund', 'cancelled'], true)),
                        Action::make('cancel_invoice')
                            ->label('Cancel')
                            ->color('danger')
                            ->icon('heroicon-s-x-circle')
                            ->action(fn (Invoice $record) => tap($record, function ($record) {
                                if ($record->transactions()->where('type', 'payment')->exists()) {
                                    Notification::make()
                                        ->title('Cannot cancel')
                                        ->danger()
                                        ->body("Invoice #{$record->number} has payments. Use refund instead.")
                                        ->send();

                                    return;
                                }

                                $record->update(['status' => 'cancelled']);
                                Notification::make()
                                    ->title('Invoice Cancelled')
                                    ->danger()
                                    ->body("Invoice #{$record->number} has been cancelled.")
                                    ->send();
                            }))
                            ->visible(fn (Invoice $record): bool => ! in_array($record->status->value, ['cancelled', 'refund'], true) && ! $record->transactions()->where('type', 'payment')->exists()),
                    ])
                        ->dropdown(false),

                    ActionGroup::make([
                        Action::make('heading_actions')
                            ->label('Record Actions')
                            ->disabled()
                            ->color('gray'),
                        Action::make('email_invoice')
                            ->label('Email Invoice')
                            ->icon('heroicon-o-envelope')
                            ->color('info')
                            ->modalWidth('md')
                            ->modalSubmitActionLabel('Send')
                            ->schema([
                                TextInput::make('to_email')
                                    ->label('To')
                                    ->email()
                                    ->required()
                                    ->default(fn (Invoice $record): string => (string) ($record->subscription?->member?->email ?? '')),
                                Textarea::make('note')
                                    ->label('Note')
                                    ->rows(2)
                                    ->placeholder('Optional note…'),
                            ])
                            ->action(function (Invoice $record, array $data): void {
                                app(InvoiceEmailService::class)->queueInvoiceIssuedEmail(
                                    invoiceId: (int) $record->getKey(),
                                    toEmail: (string) $data['to_email'],
                                    note: $data['note'] ?? null,
                                    actorId: auth()->id(),
                                );

                                Notification::make()
                                    ->title('Email queued')
                                    ->body('Invoice email queued to '.$data['to_email'].'.')
                                    ->success()
                                    ->send();
                            })
                            ->disabled(function (Invoice $record): bool {
                                $email = (string) ($record->subscription?->member?->email ?? '');

                                return ! filled($email) || ! filled($record->number) || (float) ($record->total_amount ?? 0) <= 0;
                            })
                            ->tooltip(function (Invoice $record): ?string {
                                if (! filled($record->subscription?->member?->email)) {
                                    return 'Member email is missing';
                                }

                                if (! filled($record->number) || (float) ($record->total_amount ?? 0) <= 0) {
                                    return 'Invoice document is missing required data';
                                }

                                return null;
                            })
                            ->visible(fn (Invoice $record): bool => auth()->user()?->can('update', $record) ?? false),
                        Action::make('email_receipt')
                            ->label('Email Receipt')
                            ->icon('heroicon-o-envelope-open')
                            ->color('gray')
                            ->modalWidth('md')
                            ->modalSubmitActionLabel('Send')
                            ->schema([
                                TextInput::make('to_email')
                                    ->label('To')
                                    ->email()
                                    ->required()
                                    ->default(fn (Invoice $record): string => (string) ($record->subscription?->member?->email ?? '')),
                                Select::make('payment_transaction_id')
                                    ->label('Payment')
                                    ->required()
                                    ->options(function (Invoice $record): array {
                                        return InvoiceTransaction::query()
                                            ->where('invoice_id', $record->getKey())
                                            ->where('type', 'payment')
                                            ->latest('occurred_at')
                                            ->limit(5)
                                            ->get()
                                            ->mapWithKeys(function (InvoiceTransaction $transaction): array {
                                                $occurredAt = $transaction->occurred_at
                                                    ? $transaction->occurred_at->timezone(config('app.timezone'))->format('d/m/Y H:i')
                                                    : '-';

                                                return [
                                                    $transaction->getKey() => "{$occurredAt} - ".Helpers::formatCurrency((float) ($transaction->amount ?? 0)),
                                                ];
                                            })
                                            ->toArray();
                                    })
                                    ->default(function (Invoice $record): ?int {
                                        return InvoiceTransaction::query()
                                            ->where('invoice_id', $record->getKey())
                                            ->where('type', 'payment')
                                            ->latest('occurred_at')
                                            ->value('id');
                                    }),
                                Textarea::make('note')
                                    ->label('Note')
                                    ->rows(2)
                                    ->placeholder('Optional note…'),
                            ])
                            ->action(function (Invoice $record, array $data): void {
                                app(InvoiceEmailService::class)->queuePaymentReceiptEmail(
                                    invoiceId: (int) $record->getKey(),
                                    transactionId: (int) $data['payment_transaction_id'],
                                    toEmail: (string) $data['to_email'],
                                    note: $data['note'] ?? null,
                                    actorId: auth()->id(),
                                );

                                Notification::make()
                                    ->title('Email queued')
                                    ->body('Receipt email queued to '.$data['to_email'].'.')
                                    ->success()
                                    ->send();
                            })
                            ->disabled(function (Invoice $record): bool {
                                $email = (string) ($record->subscription?->member?->email ?? '');

                                return ! filled($email) || ! filled($record->number) || (float) ($record->total_amount ?? 0) <= 0 || (float) ($record->paid_amount ?? 0) <= 0;
                            })
                            ->tooltip(function (Invoice $record): ?string {
                                if (! filled($record->subscription?->member?->email)) {
                                    return 'Member email is missing';
                                }

                                if ((float) ($record->paid_amount ?? 0) <= 0) {
                                    return 'No payments recorded for this invoice';
                                }

                                if (! filled($record->number) || (float) ($record->total_amount ?? 0) <= 0) {
                                    return 'Invoice document is missing required data';
                                }

                                return null;
                            })
                            ->visible(fn (Invoice $record): bool => (auth()->user()?->can('update', $record) ?? false) && (float) ($record->paid_amount ?? 0) > 0),
                        Action::make('preview_invoice')
                            ->label('View PDF')
                            ->icon('heroicon-o-document-text')
                            ->url(fn (Invoice $record): string => route('invoices.preview', $record))
                            ->openUrlInNewTab()
                            ->visible(fn (Invoice $record): bool => auth()->user()?->can('view', $record) ?? false),
                        Action::make('download_invoice')
                            ->label('Download')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->url(fn (Invoice $record): string => route('invoices.download', $record))
                            ->openUrlInNewTab()
                            ->disabled(fn (Invoice $record): bool => ! filled($record->number) || (float) ($record->total_amount ?? 0) <= 0)
                            ->tooltip(function (Invoice $record): ?string {
                                if (! filled($record->number)) {
                                    return 'Invoice number is missing';
                                }

                                if ((float) ($record->total_amount ?? 0) <= 0) {
                                    return 'Invoice total amount is missing';
                                }

                                return null;
                            })
                            ->visible(fn (Invoice $record): bool => auth()->user()?->can('view', $record) ?? false),
                        ViewAction::make()
                            ->url(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record])),
                        EditAction::make()
                            ->hidden(fn ($record) => $record->status->value !== 'issued')
                            ->url(fn ($record) => InvoiceResource::getUrl('edit', ['record' => $record])),
                    ])->dropdown(false),
                ]),
            ])
            ->toolbarActions([]);
    }
}
