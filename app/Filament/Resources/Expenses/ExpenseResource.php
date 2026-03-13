<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpenseTable;
use App\Models\Expense;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    public static function getModelLabel(): string
    {
        return __('app.resources.expenses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.expenses.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
        ];
    }
}
