<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\InvoiceTransactionsRelationManager;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Invoices\Tables\InvoiceTable;
use App\Models\Invoice;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    public static function getModelLabel(): string
    {
        return __('app.resources.invoices.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.invoices.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Define the form schema for the resource.
     */
    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    /**
     * Get the Filament table columns for the invoice list view.
     */
    public static function table(Table $table): Table
    {
        return InvoiceTable::configure($table);
    }

    /**
     * Add infolist to the resource.
     */
    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    /**
     * Get the relation managers for the resource.
     */
    public static function getRelations(): array
    {
        return [
            InvoiceTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
