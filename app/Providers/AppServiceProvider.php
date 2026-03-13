<?php

namespace App\Providers;

use App\Contracts\SequenceRepository;
use App\Contracts\SettingsRepository;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Observers\InvoiceObserver;
use App\Observers\InvoiceTransactionObserver;
use App\Services\JsonSequenceRepository;
use App\Services\JsonSettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class, JsonSettingsRepository::class);
        $this->app->singleton(SequenceRepository::class, JsonSequenceRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureApiRateLimiting();
        $this->configureScrambleApiDocs();

        FilamentAsset::register([
            Css::make('gymie-styles', __DIR__.'/../../resources/css/custom.css'),
        ]);

        /**
         * Configure the CreateAction globally to use a specific icon.
         */
        CreateAction::configureUsing(function (CreateAction $action) {
            $action->icon('heroicon-s-plus');
        });

        /**
         * Configure the EditAction and DeleteAction globally to use specific icons.
         */
        EditAction::configureUsing(function (EditAction $action) {
            $action->icon('heroicon-s-pencil-square');
        });

        /**
         * Configure the DeleteAction globally to use a specific icon.
         */
        DeleteAction::configureUsing(function (DeleteAction $action) {
            $action->icon('heroicon-s-trash');
        });

        /**
         * Configure the ViewAction globally to use a specific icon.
         */
        ViewAction::configureUsing(function (ViewAction $action) {
            $action->icon('heroicon-s-eye');
        });

        /**
         * Configure the Table component globally to set default sorting.
         */
        Table::configureUsing(function (Table $table) {
            $table->defaultSort('id', 'desc');
        });

        /**
         * Configure the Select component globally to be searchable, non-native, and preloaded.
         */
        Select::configureUsing(function (Select $select) {
            $select
                ->searchable()
                ->native(false)
                ->preload();
        });

        /**
         * Configure the DatePicker component globally to use a specific format and placeholder.
         */
        DatePicker::configureUsing(function (DatePicker $datePicker) {
            $datePicker
                ->native(false)
                ->placeholder(__('app.placeholders.date_example'))
                ->displayFormat('d/m/Y')
                ->prefixIcon('heroicon-o-calendar-days');
        });

        /**
         * Configure the DateTimePicker component globally to use a specific format and placeholder.
         */
        DateTimePicker::configureUsing(function (DateTimePicker $datePicker) {
            $datePicker
                ->native(false)
                ->placeholder(__('app.placeholders.date_time_example'))
                ->displayFormat('d/m/Y H:i A')
                ->prefixIcon('heroicon-o-calendar-days');
        });

        /**
         * Configure the TextColumn globally to be toggleable and hidden by default.
         */
        TextColumn::configureUsing(function (TextColumn $column) {
            $column->toggleable(isToggledHiddenByDefault: false);
        });

        $this->configureDeletionPrevention();
        $this->registerModelObservers();
    }

    /**
     * Configure Scramble (OpenAPI) generation for the v1 API.
     *
     * This is guarded so the app remains bootable even when Scramble isn't installed yet.
     */
    private function configureScrambleApiDocs(): void
    {
        if (! class_exists(\Dedoc\Scramble\Scramble::class)) {
            return;
        }

        $config = \Dedoc\Scramble\Scramble::configure();

        if (method_exists($config, 'routes')) {
            $config->routes(static function (\Illuminate\Routing\Route $route): bool {
                return str_starts_with($route->uri, 'api/v1/');
            });
        }

        if (method_exists($config, 'withOperationTransformers')) {
            $config->withOperationTransformers([
                \App\Services\Api\Docs\AddIndexQueryParametersTransformer::class,
            ]);
        }

        if (method_exists($config, 'withDocumentTransformers') && class_exists(\Dedoc\Scramble\Support\Generator\SecurityScheme::class)) {
            $config->withDocumentTransformers(static function (mixed $openApi): void {
                if (! is_object($openApi) || ! method_exists($openApi, 'secure')) {
                    return;
                }

                if (! method_exists(\Dedoc\Scramble\Support\Generator\SecurityScheme::class, 'http')) {
                    return;
                }

                $openApi->secure(\Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'));
            });
        }
    }

    /**
     * Configure API rate limiters used by the `api` middleware group.
     *
     * Defining these explicitly prevents "throttle:api" from relying on
     * framework defaults that can vary between versions.
     */
    private function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });

        RateLimiter::for('api-login', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });
    }

    /**
     * Register model observers.
     */
    private function registerModelObservers(): void
    {
        Invoice::observe(InvoiceObserver::class);
        InvoiceTransaction::observe(InvoiceTransactionObserver::class);
    }

    /**
     * Prevent deletion of records that still have related data.
     */
    protected function configureDeletionPrevention(): void
    {
        $map = config('prevent-deletion', []);
        DeleteAction::configureUsing(function (DeleteAction $action) use ($map): DeleteAction {
            return $action
                ->requiresConfirmation(function (Action $action, $record) use ($map) {
                    $class = get_class($record);
                    $action->modalIcon('heroicon-o-trash');
                    if (isset($map[$class])) {
                        foreach ($map[$class] as $relation) {
                            if ($record->$relation()->exists()) {
                                $count = $record->$relation()->count();
                                $moduleName = class_basename($record);
                                $label = Str::kebab($relation);
                                $action
                                    ->modalIcon('heroicon-o-x-mark')
                                    ->modalHeading(__('app.deletion_prevention.cannot_delete_title', ['module' => $moduleName]))
                                    ->modalDescription(__('app.deletion_prevention.cannot_delete_description', ['count' => $count, 'relation' => $label]))
                                    ->modalCancelAction(false)
                                    ->modalSubmitAction(false);
                                break;
                            }
                        }
                    }

                    return $action;
                });
        }, isImportant: true);

        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action) use ($map): DeleteBulkAction {
            return $action
                ->requiresConfirmation(function (DeleteBulkAction $action, Collection $records) use ($map) {
                    foreach ($records as $record) {
                        $class = get_class($record);
                        $action->modalIcon('heroicon-o-trash');
                        if (isset($map[$class])) {
                            foreach ($map[$class] as $relation) {
                                if ($record->$relation()->exists()) {
                                    $count = $record->$relation()->count();
                                    $moduleName = Str::pluralStudly(class_basename($record));
                                    $label = Str::kebab($relation);
                                    $action
                                        ->modalIcon('heroicon-o-x-mark')
                                        ->modalHeading(__('app.deletion_prevention.cannot_delete_title', ['module' => $moduleName]))
                                        ->modalDescription(__('app.deletion_prevention.cannot_delete_bulk_description', ['module' => $moduleName, 'count' => $count, 'relation' => $label]))
                                        ->modalCancelAction(false)
                                        ->modalSubmitAction(false);
                                    break 2;
                                }
                            }
                        }
                    }

                    return $action;
                });
        }, isImportant: true);
    }
}
