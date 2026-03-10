<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Resources\Subscriptions\RelationManagers\InvoicesRelationManager;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\Billing\InvoiceCalculator;
use App\Support\Billing\PaymentMethod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class InvoiceForm
{
    /**
     * Configure the follow-up form schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        Group::make()
                            ->columns(3)
                            ->columnSpan(3)
                            ->schema([
                                TextInput::make('number')
                                    ->label('Invoice No.')
                                    ->required()
                                    ->readOnly()
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(fn (Get $get) => Helpers::generateLastNumber(
                                        'invoice',
                                        Invoice::class,
                                        $get('date')
                                    )),
                                Select::make('subscription_id')
                                    ->label('Subscription')
                                    ->reactive()
                                    ->relationship(
                                        name: 'subscription',
                                        titleAttribute: 'id',
                                        modifyQueryUsing: fn (Builder $query) => $query
                                            ->with(['member', 'plan'])
                                            ->orderByDesc('start_date'),
                                    )
                                    ->hiddenOn(InvoicesRelationManager::class)
                                    ->getOptionLabelFromRecordUsing(fn (Subscription $record): string => self::formatSubscriptionOptionLabel($record))
                                    ->searchable()
                                    ->afterStateUpdated(
                                        function (Get $get, Set $set) {
                                            $sub = $get('subscription_id')
                                                ? Subscription::with('plan')->find($get('subscription_id'))
                                                : null;

                                            if ($sub) {
                                                $fee = (float) ($sub->plan->amount ?? 0);
                                                $taxRate = Helpers::getTaxRate() ?: 0;
                                                $discountAmount = (float) ($get('discount_amount') ?: 0);
                                                $paid = (float) ($get('paid_amount') ?: 0);

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
                                            } else {
                                                $set('subscription_fee', 0);
                                                $set('tax', 0);
                                                $set('total_amount', 0);
                                                $set('discount_amount', 0);
                                                $set('due_amount', 0);
                                            }
                                        }
                                    )
                                    ->required(),
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
                                    ->native(false)
                                    ->live()
                                    ->reactive()
                                    ->placeholder('Select Discount')
                                    ->afterStateUpdated(
                                        function (Get $get, Set $set) {
                                            $fee = $get('subscription_fee') ?: 0;
                                            $discountPct = (int) $get('discount');
                                            $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);
                                            $paid = (float) ($get('paid_amount') ?: 0);
                                            $taxRate = Helpers::getTaxRate() ?: 0;

                                            $summary = InvoiceCalculator::summary(
                                                (float) $fee,
                                                $taxRate,
                                                $discountAmount,
                                                $paid,
                                            );

                                            $set('discount_amount', $summary['discount_amount']);
                                            $set('tax', $summary['tax']);
                                            $set('total_amount', $summary['total']);
                                            $set('paid_amount', $summary['paid']);
                                            $set('due_amount', $summary['due']);
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
                                            $paid = (float) ($get('paid_amount') ?: 0);
                                            $taxRate = Helpers::getTaxRate() ?: 0;

                                            $summary = InvoiceCalculator::summary(
                                                (float) $fee,
                                                $taxRate,
                                                (float) $clamped,
                                                $paid,
                                            );

                                            $set('discount_amount', $summary['discount_amount']);
                                            $set('tax', $summary['tax']);
                                            $set('total_amount', $summary['total']);
                                            $set('paid_amount', $summary['paid']);
                                            $set('due_amount', $summary['due']);
                                        }
                                    ),
                                Textarea::make('discount_note')
                                    ->label('Discount Note')
                                    ->placeholder('E.g. introductory offer'),
                                Radio::make('payment_method')
                                    ->label('Payment Method')
                                    ->options(PaymentMethod::options())
                                    ->default('cash')
                                    ->inline()
                                    ->inlineLabel(false)
                                    ->required(),
                            ]),
                        Fieldset::make('Summary')
                            ->columnSpan(1)
                            ->columns(1)
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
                            ]),
                    ]),
            ]);
    }

    /**
     * Format the subscription option label for display in the select input.
     *
     * @param  Subscription  $subscription  The subscription record to format.
     * @return string The formatted label for the subscription option.
     */
    private static function formatSubscriptionOptionLabel(Subscription $subscription): string
    {
        $memberCode = $subscription->member?->code ?? '—';
        $memberName = $subscription->member?->name ?? '—';
        $planCode = $subscription->plan?->code ?? '—';
        $planName = $subscription->plan?->name ?? '—';
        $start = $subscription->start_date?->format('d-m-Y') ?? '—';
        $end = $subscription->end_date?->format('d-m-Y') ?? '—';
        $status = $subscription->status?->getLabel() ?? '—';

        return "#{$subscription->id} — {$memberCode} {$memberName} • {$planCode} {$planName} • {$start} → {$end} • {$status}";
    }
}
