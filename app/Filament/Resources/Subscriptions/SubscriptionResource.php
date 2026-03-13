<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionInfolist;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionTable;
use App\Models\Subscription;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    public static function getModelLabel(): string
    {
        return __('app.resources.subscriptions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.subscriptions.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    /**
     * Define the form schema for the resource.
     */
    public static function form(Schema $schema): Schema
    {
        return SubscriptionForm::configure($schema);
    }

    /**
     * Define the table for listing records in the resource.
     */
    public static function table(Table $table): Table
    {
        return SubscriptionTable::configure($table);
    }

    /**
     * Define the infolist schema for the resource.
     */
    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionInfolist::configure($schema);
    }

    /**
     * Get the list of relations for this resource.
     *
     * @return array<string, string>
     */
    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
        ];
    }

    /**
     * Get the list of pages for this resource.
     *
     * @return array<string, Page>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'view' => ViewSubscription::route('/{record}'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
