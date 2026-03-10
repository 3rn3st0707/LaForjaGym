<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\RelationManagers\SubscriptionsRelationManager;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\InvoiceCalculator;
use App\Support\Billing\PaymentMethod;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class SubscriptionForm
{
    /**
     * Default payment method options for forms.
     *
     * @return array<string, string>
     */
    private static function paymentMethodOptions(): array
    {
        return PaymentMethod::options();
    }

    /**
     * Configure the subscription form schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Group::make()
                    ->columns(6)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('member_id')
                            ->columnSpan(2)
                            ->relationship('member', 'name')
                            ->placeholder('Select a member')
                            ->getOptionLabelFromRecordUsing(fn (Member $record): string => "{$record->code} - {$record->name}")
                            ->hiddenOn([SubscriptionsRelationManager::class, CreateMember::class])
                            ->required(),
                        Select::make('plan_id')
                            ->columnSpan(fn ($livewire) => ($livewire instanceof SubscriptionsRelationManager ||
                                $livewire instanceof CreateMember)
                                ? 4
                                : 2)
                            ->relationship('plan', 'name')
                            ->placeholder('Select a plan')
                            ->searchable(['code', 'name'])
                            ->reactive()
                            ->getOptionLabelFromRecordUsing(fn (Plan $record): string => self::formatPlanOptionLabel($record))
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $plan = Plan::find($get('plan_id'));
                                $fee = (float) ($plan?->amount ?? 0);
                                $taxRate = Helpers::getTaxRate() ?: 0;

                                // grab current invoices array (if any)
                                $invoices = $get('invoices') ?? [];

                                foreach ($invoices as $index => $invoice) {
                                    $discount = (float) ($invoice['discount_amount'] ?? 0);
                                    $paid = (float) ($invoice['paid_amount'] ?? 0);

                                    $summary = InvoiceCalculator::summary(
                                        $fee,
                                        $taxRate,
                                        $discount,
                                        $paid,
                                    );

                                    // set each nested invoice field
                                    $set("invoices.{$index}.subscription_fee", $summary['fee']);
                                    $set("invoices.{$index}.tax", $summary['tax']);
                                    $set("invoices.{$index}.total_amount", $summary['total']);
                                    $set("invoices.{$index}.paid_amount", $summary['paid']);
                                    $set("invoices.{$index}.due_amount", $summary['due']);
                                }

                                $set('end_date', Helpers::calculateSubscriptionEndDate(
                                    $get('start_date'),
                                    $get('plan_id'),
                                ));
                            })
                            ->required(),
                        DatePicker::make('start_date')
                            ->label('Start Date')
                            ->live()
                            ->required()
                            ->default(now())
                            ->before('end_date')
                            ->reactive()                         // <— also reactive
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $set('end_date', Helpers::calculateSubscriptionEndDate(
                                    $get('start_date'),
                                    $get('plan_id'),
                                ));
                            }),
                        DatePicker::make('end_date')
                            ->label('End Date')
                            ->live()
                            ->required()
                            ->after('start_date')
                            ->disabled()
                            ->dehydrated()
                            ->reactive()
                            ->afterStateHydrated(function (Get $get, Set $set) {
                                $set('end_date', Helpers::calculateSubscriptionEndDate(
                                    $get('start_date'),
                                    $get('plan_id'),
                                ));
                            }),
                    ]),
                Section::make('Invoice Details')
                    ->hiddenOn('edit')
                    ->columnSpanFull()
                    ->schema(
                        [
                            Repeater::make('invoices')
                                ->relationship('invoices')
                                ->itemLabel('')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->minItems(1)
                                ->defaultItems(1)
                                ->maxItems(1)
                                ->addable(false)
                                ->deletable(false)
                                ->columns(4)
                                ->extraAttributes(['class' => 'rmv_rept-space'])
                                ->schema([
                                    Group::make()
                                        ->columns(2)
                                        ->columnSpan(3)
                                        ->schema([
                                            TextInput::make('number')
                                                ->label('Invoice No.')
                                                ->required()
                                                ->readOnly()
                                                ->disabled()
                                                ->dehydrated()
                                                ->rule(Rule::unique('invoices', 'number'))
                                                ->default(fn (Get $get) => Helpers::generateLastNumber(
                                                    'invoice',
                                                    Invoice::class,
                                                    $get('date')
                                                )),
                                            DatePicker::make('date')
                                                ->label('Date')
                                                ->required()
                                                ->reactive()
                                                ->default(now()),
                                            DatePicker::make('due_date')
                                                ->label('Due Date')
                                                ->required()
                                                ->reactive(),
                                            Select::make('discount')
                                                ->label('Discount')
                                                ->options(Helpers::getDiscounts())
                                                ->live()
                                                ->reactive()
                                                ->placeholder('Select Discount')
                                                ->afterStateUpdated(
                                                    function (Get $get, Set $set) {
                                                        $fee = $get('subscription_fee') ?: 0;
                                                        $discountPct = (int) $get('discount');
                                                        $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);

                                                        $set('discount_amount', round($discountAmount));
                                                        self::recalculateInvoiceSummary($get, $set);
                                                    }
                                                ),
                                            TextInput::make('discount_amount')
                                                ->label('Discount Amount')
                                                ->numeric()
                                                ->debounce(300)
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol())
                                                ->maxValue(fn (Get $get): float => $get('subscription_fee') ?: 0)
                                                ->afterStateUpdated(
                                                    function (Get $get, Set $set, $livewire, TextInput $component) {
                                                        $livewire->validateOnly($component->getStatePath());

                                                        $fee = $get('subscription_fee') ?: 0;
                                                        $entered = $get('discount_amount') ?: 0;
                                                        $clamped = min(max($entered, 0), $fee);
                                                        $set('discount_amount', $clamped);

                                                        self::recalculateInvoiceSummary($get, $set);
                                                    }
                                                ),
                                            Textarea::make('discount_note')
                                                ->label('Discount Note')
                                                ->placeholder('E.g. introductory offer'),
                                            TextInput::make('paid_amount')
                                                ->label('Paid Amount')
                                                ->numeric()
                                                ->minValue(0)
                                                ->debounce(300)
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol())
                                                ->visible(fn (Get $get): bool => ! PaymentMethod::isOnline((string) ($get('payment_method') ?? null)))
                                                ->afterStateUpdated(function (Get $get, Set $set, $livewire, TextInput $component) {
                                                    $livewire->validateOnly($component->getStatePath());
                                                    self::recalculateInvoiceSummary($get, $set);
                                                }),
                                            Radio::make('payment_method')
                                                ->label('Payment Method')
                                                ->options(self::paymentMethodOptions())
                                                ->default('cash')
                                                ->inline()
                                                ->inlineLabel(false)
                                                ->reactive()
                                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                                    if (PaymentMethod::isOnline($state)) {
                                                        $set('paid_amount', 0);
                                                    }

                                                    self::recalculateInvoiceSummary($get, $set);
                                                })
                                                ->required(),
                                        ]),
                                    Fieldset::make('Summary')
                                        ->columns(1)
                                        ->columnSpan(1)
                                        ->schema([
                                            TextInput::make('subscription_fee')
                                                ->label('Subscription Fee')
                                                ->numeric()
                                                ->readOnly()
                                                ->disabled()
                                                ->dehydrated()
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol())
                                                ->required(),
                                            TextInput::make('tax')
                                                ->label('Tax ('.Helpers::getTaxRate().'%)')
                                                ->numeric()
                                                ->disabled()
                                                ->dehydrated()
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol())
                                                ->readOnly(),
                                            TextInput::make('total_amount')
                                                ->label('Total Amount')
                                                ->numeric()
                                                ->readOnly()
                                                ->disabled()
                                                ->dehydrated()
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol())
                                                ->required(),
                                            TextInput::make('due_amount')
                                                ->label('Due Amount')
                                                ->numeric()
                                                ->readOnly()
                                                ->disabled()
                                                ->dehydrated()
                                                ->default(0)
                                                ->prefix(Helpers::getCurrencySymbol()),
                                        ]),
                                ]),
                        ]
                    ),
            ]);
    }

    public static function renewSchema(Subscription $record): array
    {
        $today = Carbon::today(config('app.timezone'))->toDateString();
        $defaultStartDate = max(
            $today,
            $record->end_date?->copy()->addDay()->toDateString() ?? $today,
        );

        return [
            Group::make()
                ->columns(5)
                ->schema([
                    Select::make('plan_id')
                        ->label('Plan')
                        ->options(fn (): array => Plan::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Plan $plan): array => [
                                $plan->id => self::formatPlanOptionLabel($plan),
                            ])
                            ->all())
                        ->searchable()
                        ->default($record->plan_id)
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('end_date', Helpers::calculateSubscriptionEndDate(
                                $get('start_date'),
                                $get('plan_id'),
                            ));

                            $plan = $get('plan_id') ? Plan::find($get('plan_id')) : null;
                            $fee = round($plan?->amount ?? 0);
                            $discountPct = (int) ($get('discount') ?? 0);
                            $discountAmount = round(Helpers::getDiscountAmount($discountPct, $fee));
                            $set('discount_amount', $discountAmount);

                            self::recalculateRenewInvoiceSummary($get, $set);
                        })
                        ->required()
                        ->columnSpan(3),
                    DatePicker::make('start_date')
                        ->label('Start Date')
                        ->native(false)
                        ->suffixIcon('heroicon-m-calendar-days')
                        ->default($defaultStartDate)
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('end_date', Helpers::calculateSubscriptionEndDate(
                                $get('start_date'),
                                $get('plan_id'),
                            ));

                            self::recalculateRenewInvoiceSummary($get, $set);
                        })
                        ->required(),
                    DatePicker::make('end_date')
                        ->label('End Date')
                        ->native(false)
                        ->suffixIcon('heroicon-m-calendar-days')
                        ->disabled()
                        ->dehydrated()
                        ->default(fn (Get $get): string => Helpers::calculateSubscriptionEndDate(
                            $get('start_date'),
                            $get('plan_id'),
                        ))
                        ->required(),
                ]),
            Section::make('Invoice')
                ->columns(7)
                ->schema([
                    Group::make()
                        ->columns(2)
                        ->schema([
                            TextInput::make('invoice_number')
                                ->label('Invoice No.')
                                ->required()
                                ->readOnly()
                                ->disabled()
                                ->dehydrated()
                                ->rule(Rule::unique('invoices', 'number'))
                                ->default(fn (Get $get) => Helpers::generateLastNumber(
                                    'invoice',
                                    Invoice::class,
                                    $get('invoice_date'),
                                )),
                            DatePicker::make('invoice_date')
                                ->label('Invoice Date')
                                ->native(false)
                                ->suffixIcon('heroicon-m-calendar-days')
                                ->default($today)
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                    $set('invoice_number', Helpers::generateLastNumber(
                                        'invoice',
                                        Invoice::class,
                                        $state,
                                    ));

                                    if (blank($get('invoice_due_date'))) {
                                        $set('invoice_due_date', $state);
                                    }
                                })
                                ->required(),
                            DatePicker::make('invoice_due_date')
                                ->label('Due Date')
                                ->native(false)
                                ->suffixIcon('heroicon-m-calendar-days')
                                ->default($today)
                                ->required(),
                            Select::make('discount')
                                ->label('Discount')
                                ->options(Helpers::getDiscounts())
                                ->live()
                                ->reactive()
                                ->placeholder('Select Discount')
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    $plan = $get('plan_id') ? Plan::find($get('plan_id')) : null;
                                    $fee = round($plan?->amount ?? 0);
                                    $discountPct = (int) ($get('discount') ?? 0);
                                    $discountAmount = round(Helpers::getDiscountAmount($discountPct, $fee));
                                    $set('discount_amount', $discountAmount);

                                    self::recalculateRenewInvoiceSummary($get, $set);
                                }),
                            TextInput::make('discount_amount')
                                ->label('Discount Amount')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(fn (Get $get): float => round(Plan::find($get('plan_id'))?->amount ?? 0))
                                ->debounce(300)
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    self::recalculateRenewInvoiceSummary($get, $set);
                                }),
                            Textarea::make('discount_note')
                                ->label('Discount Note')
                                ->placeholder('E.g. renewal offer'),
                            TextInput::make('paid_amount')
                                ->label('Paid Amount')
                                ->numeric()
                                ->minValue(0)
                                ->debounce(300)
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->visible(fn (Get $get): bool => ! PaymentMethod::isOnline((string) ($get('payment_method') ?? null)))
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    self::recalculateRenewInvoiceSummary($get, $set);
                                }),
                            Radio::make('payment_method')
                                ->label('Payment Method')
                                ->options(self::paymentMethodOptions())
                                ->default('cash')
                                ->inline()
                                ->inlineLabel(false)
                                ->reactive()
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                    if (PaymentMethod::isOnline($state)) {
                                        $set('paid_amount', 0);
                                    }

                                    self::recalculateRenewInvoiceSummary($get, $set);
                                })
                                ->required(),
                        ])->columnSpan(5),
                    Fieldset::make('Summary')
                        ->columns(1)
                        ->columnSpan(2)
                        ->schema([
                            TextInput::make('subscription_fee')
                                ->label('Subscription Fee')
                                ->numeric()
                                ->readOnly()
                                ->disabled()
                                ->dehydrated()
                                ->default(fn (Get $get): float => round(Plan::find($get('plan_id'))?->amount ?? 0))
                                ->prefix(Helpers::getCurrencySymbol()),
                            TextInput::make('tax')
                                ->label('Tax ('.Helpers::getTaxRate().'%)')
                                ->numeric()
                                ->readOnly()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol()),
                            TextInput::make('total_amount')
                                ->label('Total Amount')
                                ->numeric()
                                ->readOnly()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol()),
                            TextInput::make('due_amount')
                                ->label('Due Amount')
                                ->numeric()
                                ->readOnly()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol()),
                        ]),
                ]),
        ];
    }

    /**
     * Handle the subscription renewal process, including creating a new subscription and associated invoice.
     *
     * @param  Subscription  $record  The subscription being renewed
     * @param  array  $data  The form data for the new subscription and invoice
     */
    public static function handleRenew(Subscription $record, array $data): void
    {
        Subscription::query()->getConnection()->transaction(function () use ($record, $data): void {
            $timezone = config('app.timezone');
            $today = Carbon::today($timezone);

            $plan = Plan::findOrFail((int) $data['plan_id']);
            $startDate = Carbon::parse($data['start_date'])->toDateString();
            $endDate = $data['end_date'] ?? Helpers::calculateSubscriptionEndDate($startDate, (int) $plan->id);

            $status = Carbon::parse($startDate)->gt($today)
                ? 'upcoming'
                : 'ongoing';

            $newSubscription = Subscription::create([
                'renewed_from_subscription_id' => $record->id,
                'member_id' => $record->member_id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
            ]);

            if ($record->end_date && $record->end_date->lt($today)) {
                $record->update([
                    'status' => 'renewed',
                ]);
            }

            $fee = round($plan->amount);
            $discountPct = max((int) ($data['discount'] ?? 0), 0);
            $discountAmount = (float) ($data['discount_amount'] ?? 0);
            $discountAmount = min(max($discountAmount, 0), $fee);
            if ($discountPct > 0 && $discountAmount <= 0) {
                $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);
            }

            $paymentMethod = $data['payment_method'] ?? null;
            $paidAmount = max((float) ($data['paid_amount'] ?? 0), 0);
            if (PaymentMethod::isOnline($paymentMethod)) {
                $paidAmount = 0;
            }

            $invoiceDate = Carbon::parse($data['invoice_date'])->toDateString();
            $invoiceDueDate = Carbon::parse($data['invoice_due_date'] ?? $invoiceDate)->toDateString();

            $invoice = Invoice::create([
                'number' => $data['invoice_number'] ?? null,
                'subscription_id' => $newSubscription->id,
                'date' => $invoiceDate,
                'due_date' => $invoiceDueDate,
                'payment_method' => $paymentMethod,
                'discount' => $discountPct ?: null,
                'discount_amount' => $discountAmount ?: null,
                'discount_note' => $data['discount_note'] ?? null,
                'paid_amount' => $paidAmount,
                'subscription_fee' => $fee,
                'status' => 'issued',
            ]);

            Notification::make()
                ->title('Subscription renewed')
                ->body("New subscription created and invoice {$invoice->number} generated.")
                ->success()
                ->send();
        });
    }

    /**
     * Recalculate invoice summary fields (subscription_fee, tax, total_amount, due_amount) based on the selected plan and discount.
     */
    private static function recalculateRenewInvoiceSummary(Get $get, Set $set): void
    {
        $plan = $get('plan_id') ? Plan::find($get('plan_id')) : null;
        $fee = (float) ($plan?->amount ?? 0);
        $taxRate = Helpers::getTaxRate() ?: 0;

        self::recalculateInvoiceSummary($get, $set, $fee, $taxRate);
    }

    /**
     * Recalculate invoice summary fields (subscription_fee, tax, total_amount, due_amount).
     */
    private static function recalculateInvoiceSummary(Get $get, Set $set, ?float $fee = null, ?float $taxRate = null): void
    {
        $fee = $fee ?? (float) ($get('subscription_fee') ?? 0);
        $taxRate = $taxRate ?? (float) (Helpers::getTaxRate() ?: 0);

        $discountAmount = (float) ($get('discount_amount') ?? 0);
        $paid = (float) ($get('paid_amount') ?? 0);

        $paymentMethod = (string) ($get('payment_method') ?? null);
        if (PaymentMethod::isOnline($paymentMethod)) {
            $paid = 0;
        }

        $summary = InvoiceCalculator::summary(
            $fee,
            $taxRate,
            $discountAmount,
            $paid,
        );

        $set('subscription_fee', $summary['fee']);
        $set('tax', $summary['tax']);
        $set('discount_amount', $summary['discount_amount']);
        $set('total_amount', $summary['total']);
        $set('paid_amount', $summary['paid']);
        $set('due_amount', $summary['due']);
    }

    /**
     * Format the plan option label for the select input.
     */
    private static function formatPlanOptionLabel(Plan $plan): string
    {
        return sprintf(
            '%s – %s (%s%s | %d days)',
            $plan->code,
            $plan->name,
            Helpers::getCurrencySymbol(),
            round($plan->amount),
            $plan->days,
        );
    }
}
