<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Helpers\Helpers;
use App\Support\Billing\PaymentMethod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Payment History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => Helpers::formatCurrency($state))
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->formatStateUsing(fn (?string $state): string => $state ? PaymentMethod::channelLabel($state) : '-'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->placeholder('-'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
