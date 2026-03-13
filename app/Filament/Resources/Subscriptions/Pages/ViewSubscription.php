<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    public function getTitle(): string
    {
        return __('app.titles.record', [
            'resource' => SubscriptionResource::getModelLabel(),
            'name' => $this->record->member->name,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->hidden(fn (): bool => in_array($this->record->status->value, ['expired', 'renewed'])),
            DeleteAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.memberships'),
            SubscriptionResource::getUrl('index') => SubscriptionResource::getNavigationLabel(),
            $this->record->member->name,
        ];
    }
}
