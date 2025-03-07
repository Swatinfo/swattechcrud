<?php

namespace SwatTech\Crud;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use SwatTech\Crud\Commands\GenerateCrudCommand;
use SwatTech\Crud\Commands\GenerateApiCommand;
use SwatTech\Crud\Commands\GenerateRelationshipsCommand;
use SwatTech\Crud\Commands\GenerateTestsCommand;
use SwatTech\Crud\Commands\GenerateDocumentationCommand;
use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Contracts\RepositoryInterface;
use SwatTech\Crud\Services\CrudManagerService;
use SwatTech\Crud\Facades\Crud;

/**
 * SwatTechCrudServiceProvider
 *
 * This service provider bootstraps the entire CRUD package, registering
 * service container bindings, commands, assets, routes, and other
 * components required for the package to function properly.
 *
 * @package SwatTech\Crud
 */
class SwatTechCrudServiceProvider extends ServiceProvider
{
    /**
     * The commands to be registered.
     *
     * @var array
     */
    protected $commands = [
        GenerateCrudCommand::class,
        GenerateApiCommand::class,
        GenerateRelationshipsCommand::class,
        GenerateTestsCommand::class,
        GenerateDocumentationCommand::class,
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
        $this->bindInterfaces();
        $this->registerCrudManager();
        $this->registerFacade();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerCommands();
        $this->publishAssets();
        $this->loadMigrations();
        $this->loadRoutes();
        $this->loadTranslations();
        $this->loadViews();
        $this->setupEventListeners();
    }

    /**
     * Register the package configuration.
     *
     * @return void
     */
    protected function registerConfig()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/crud.php', 'crud'
        );

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/crud.php' => config_path('crud.php'),
        ], 'config');
    }

    /**
     * Bind interfaces to their implementations.
     *
     * @return void
     */
    protected function bindInterfaces()
    {
        // Bind repositories
        $this->app->bind(
            RepositoryInterface::class,
            \SwatTech\Crud\Repositories\BaseRepository::class
        );

        // Bind analyzers
        $this->app->bind(
            AnalyzerInterface::class,
            \SwatTech\Crud\Analyzers\DatabaseAnalyzer::class
        );

        // Bind generators
        $this->app->bind(
            \SwatTech\Crud\Contracts\GeneratorInterface::class,
            \SwatTech\Crud\Generators\ModelGenerator::class
        );

        // Bind helpers
        $this->app->singleton(
            \SwatTech\Crud\Helpers\StringHelper::class,
            function ($app) {
                return new \SwatTech\Crud\Helpers\StringHelper();
            }
        );

        $this->app->singleton(
            \SwatTech\Crud\Helpers\SchemaHelper::class,
            function ($app) {
                return new \SwatTech\Crud\Helpers\SchemaHelper();
            }
        );

        $this->app->singleton(
            \SwatTech\Crud\Helpers\RelationshipHelper::class,
            function ($app) {
                return new \SwatTech\Crud\Helpers\RelationshipHelper(
                    $app->make(\SwatTech\Crud\Helpers\StringHelper::class),
                    $app->make(\SwatTech\Crud\Helpers\SchemaHelper::class)
                );
            }
        );
    }

    /**
     * Register the CRUD manager service.
     *
     * @return void
     */
    protected function registerCrudManager()
    {
        $this->app->singleton('swattech.crud', function ($app) {
            return new CrudManagerService(
                $app->make(\SwatTech\Crud\Analyzers\DatabaseAnalyzer::class),
                $app->make(\SwatTech\Crud\Analyzers\RelationshipAnalyzer::class)
            );
        });
    }

    /**
     * Register the package commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
    }

    /**
     * Publish package assets.
     *
     * @return void
     */
    protected function publishAssets()
    {
        // Publish stubs
        $this->publishes([
            __DIR__ . '/stubs' => resource_path('stubs/vendor/swattech/crud'),
        ], 'stubs');

        // Publish assets (JS, CSS, images)
        $this->publishes([
            __DIR__ . '/../resources/assets' => public_path('vendor/swattech/crud'),
        ], 'assets');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/swattech/crud'),
        ], 'views');
    }

    /**
     * Load package migrations.
     *
     * @return void
     */
    protected function loadMigrations()
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'migrations');
        }
    }

    /**
     * Load package routes.
     *
     * @return void
     */
    protected function loadRoutes()
    {
        if (file_exists(__DIR__ . '/../routes/web.php')) {
            Route::middleware('web')
                ->group(__DIR__ . '/../routes/web.php');
        }

        if (file_exists(__DIR__ . '/../routes/api.php')) {
            Route::prefix('api')
                ->middleware('api')
                ->group(__DIR__ . '/../routes/api.php');
        }
    }

    /**
     * Load package translations.
     *
     * @return void
     */
    protected function loadTranslations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'swattech-crud');
        
        $this->publishes([
            __DIR__ . '/../resources/lang' => resource_path('lang/vendor/swattech-crud'),
        ], 'translations');
    }

    /**
     * Load package views.
     *
     * @return void
     */
    protected function loadViews()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'swattech-crud');
    }

    /**
     * Merge the configurations.
     *
     * @return void
     */
    protected function mergeConfigurations()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/crud.php', 'crud'
        );
    }

    /**
     * Setup the event listeners for the package.
     *
     * @return void
     */
    protected function setupEventListeners()
    {
        $this->app['events']->listen(
            \SwatTech\Crud\Events\ModelCreated::class,
            \SwatTech\Crud\Listeners\LogModelCreation::class
        );

        $this->app['events']->listen(
            \SwatTech\Crud\Events\ModelUpdated::class,
            \SwatTech\Crud\Listeners\LogModelUpdate::class
        );

        $this->app['events']->listen(
            \SwatTech\Crud\Events\ModelDeleted::class,
            \SwatTech\Crud\Listeners\LogModelDeletion::class
        );
    }

    /**
     * Register the Crud Facade.
     *
     * @return void
     */
    protected function registerFacade()
    {
        $this->app->alias('swattech.crud', Crud::class);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['swattech.crud', Crud::class];
    }
}