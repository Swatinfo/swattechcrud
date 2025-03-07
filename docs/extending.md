Collecting workspace information```markdown
# Extending SwatTech CRUD

This guide provides comprehensive documentation on how to extend and customize the SwatTech CRUD package to fit your specific application needs.

## Extension Points Overview

SwatTech CRUD is designed with extensibility in mind, offering several extension points:

| Extension Point | Purpose | Interface/Base Class |
|----------------|---------|-------------------|
| Generators | Create custom code generation logic | `SwatTech\Crud\Contracts\GeneratorInterface` |
| Analyzers | Add custom database analysis | `SwatTech\Crud\Contracts\AnalyzerInterface` |
| Services | Extend business logic | `SwatTech\Crud\Services\BaseService` |
| Repositories | Customize data access | `SwatTech\Crud\Contracts\RepositoryInterface` |
| Commands | Create custom CLI commands | `Illuminate\Console\Command` |
| Stubs | Customize generated code templates | Located in `src/stubs` |
| Events | Hook into CRUD lifecycle | Various event classes |
| UI Components | Customize the interface | Blade components |

## Creating Custom Generators

Generators are the core of the package and can be extended to customize how code is generated.

### Custom Model Generator Example

```php
namespace App\Generators;

use SwatTech\Crud\Generators\ModelGenerator as BaseModelGenerator;

class CustomModelGenerator extends BaseModelGenerator
{
    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return resource_path('stubs/custom-model.stub');
    }
    
    /**
     * Build the model class with custom modifications.
     *
     * @param string $table Table name
     * @param array $options Generator options
     * @return string
     */
    public function buildClass(string $table, array $options)
    {
        $content = parent::buildClass($table, $options);
        
        // Add a custom trait to all models
        $content = str_replace(
            'use HasFactory;',
            'use HasFactory, HasUuid, Auditable;',
            $content
        );
        
        // Add additional imports
        $content = str_replace(
            "use Illuminate\\Database\\Eloquent\\Model;\n",
            "use Illuminate\\Database\\Eloquent\\Model;\nuse App\\Traits\\HasUuid;\nuse App\\Traits\\Auditable;\n",
            $content
        );
        
        return $content;
    }
    
    /**
     * Add custom methods to the model.
     *
     * @param array $options Generator options
     * @return string
     */
    protected function generateScopes(array $options)
    {
        $scopes = parent::generateScopes($options);
        
        // Add a common scope to all models
        $scopes .= "\n    /**
     * Scope a query to only include active records.
     *
     * @param  \\Illuminate\\Database\\Eloquent\\Builder  \$query
     * @return \\Illuminate\\Database\\Eloquent\\Builder
     */
    public function scopeActive(\$query)
    {
        return \$query->where('is_active', true);
    }\n";
        
        return $scopes;
    }
}
```

### Registering Custom Generators

Register your custom generator in a service provider:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Generators\CustomModelGenerator;
use SwatTech\Crud\Generators\ModelGenerator;

class CrudExtensionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            ModelGenerator::class,
            CustomModelGenerator::class
        );
    }
}
```

Don't forget to add this service provider to your `config/app.php` providers array.

## Component Overriding Instructions

You can override core components of the package by binding your own implementations to the container.

### Repository Override Example

```php
namespace App\Repositories;

use SwatTech\Crud\Repositories\BaseRepository;
use App\Interfaces\AdvancedRepositoryInterface;

class AdvancedRepository extends BaseRepository implements AdvancedRepositoryInterface
{
    /**
     * Find records by multiple columns.
     *
     * @param array $criteria
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findWhere(array $criteria)
    {
        $query = $this->model->newQuery();
        
        foreach ($criteria as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get();
    }
    
    /**
     * Find records with complex conditions.
     *
     * @param callable $callback
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findWhereCustom(callable $callback)
    {
        $query = $this->model->newQuery();
        $callback($query);
        
        return $query->get();
    }
}
```

Then bind this in your service provider:

```php
$this->app->bind(
    \SwatTech\Crud\Contracts\RepositoryInterface::class,
    \App\Repositories\AdvancedRepository::class
);
```

## Custom Template Creation Guidelines

### Publishing Default Stubs

First, publish the existing stubs to customize them:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="stubs"
```

This will copy all stub files to `resources/vendor/swattech/crud/stubs/`.

### Customizing a Stub

For example, to customize the model stub:

1. Edit `resources/vendor/swattech/crud/stubs/model.stub`
2. Modify the template to include your custom code:

```php
<?php

namespace {{namespace}};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;
{{imports}}

class {{class}} extends Model
{
    use HasFactory, Auditable;
    {{traits}}
    
    {{tableDefinition}}
    {{primaryKey}}
    {{timestamps}}
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        {{fillable}}
    ];
    
    /**
     * Boot method runs on model instantiation.
     * 
     * @return void
     */
    protected static function boot()
    {
        parent::boot();
        
        // Custom boot logic here
    }
    
    {{casts}}
    {{accessors}}
    {{mutators}}
    {{scopes}}
    {{relationships}}
    {{events}}
    {{factory}}
}
```

### Creating New Stub Files

1. Create a new stub file in your custom location
2. Create a custom generator that uses your new stub
3. Register the custom generator in your service provider

## Middleware Implementation Examples

You can add custom middleware to the routes generated by the package.

### Creating Custom Middleware

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CrudAuditMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Log all CRUD operations
        if ($request->isMethod('post') || $request->isMethod('put') || 
            $request->isMethod('patch') || $request->isMethod('delete')) {
            logger()->info('CRUD operation', [
                'user' => auth()->id(),
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip()
            ]);
        }
        
        return $next($request);
    }
}
```

### Applying Middleware to Generated Routes

Override the route generator to apply your middleware:

```php
namespace App\Generators;

use SwatTech\Crud\Generators\RouteGenerator as BaseRouteGenerator;

class CustomRouteGenerator extends BaseRouteGenerator
{
    /**
     * Generate resourceful routes with custom middleware.
     *
     * @param string $table Table name
     * @param string $controller Controller class name
     * @return string
     */
    protected function generateResourcefulRoutes(string $table, string $controller)
    {
        $routes = parent::generateResourcefulRoutes($table, $controller);
        
        // Add middleware to routes
        $routes = str_replace(
            "Route::resource('{$table}', {$controller}::class)",
            "Route::resource('{$table}', {$controller}::class)->middleware(['web', 'auth', 'crud.audit'])",
            $routes
        );
        
        return $routes;
    }
}
```

Then register your middleware in `app/Http/Kernel.php`:

```php
protected $routeMiddleware = [
    // ...
    'crud.audit' => \App\Http\Middleware\CrudAuditMiddleware::class,
];
```

## Service Customization Documentation

Services contain the business logic of your application. You can extend the base service to add custom functionality:

```php
namespace App\Services;

use SwatTech\Crud\Services\BaseService;
use App\Notifications\RecordCreated;

class EnhancedProductService extends BaseService
{
    /**
     * Create a new record with notifications.
     *
     * @param array $data
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array $data)
    {
        // Start a transaction
        $this->beginTransaction();
        
        try {
            // Create the record
            $model = parent::create($data);
            
            // Additional business logic
            $this->processInventoryAdjustment($model, $data);
            
            // Notify relevant users
            $this->notifyStakeholders($model);
            
            $this->commitTransaction();
            
            return $model;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }
    
    /**
     * Process inventory adjustment.
     *
     * @param \App\Models\Product $model
     * @param array $data
     * @return void
     */
    protected function processInventoryAdjustment($model, array $data)
    {
        // Custom inventory logic
    }
    
    /**
     * Notify stakeholders about new product.
     *
     * @param \App\Models\Product $model
     * @return void
     */
    protected function notifyStakeholders($model)
    {
        $admins = \App\Models\User::role('admin')->get();
        
        foreach ($admins as $admin) {
            $admin->notify(new RecordCreated($model));
        }
    }
}
```

Register the custom service:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\EnhancedProductService;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->when(\App\Http\Controllers\ProductController::class)
            ->needs(\SwatTech\Crud\Services\BaseService::class)
            ->give(function () {
                return app()->make(EnhancedProductService::class);
            });
    }
}
```

## Event Listener Implementation Examples

SwatTech CRUD dispatches events during CRUD operations that you can listen to.

### Creating Event Listeners

Create a listener for model creation events:

```php
namespace App\Listeners;

use SwatTech\Crud\Events\ModelCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogModelCreation implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  \SwatTech\Crud\Events\ModelCreated  $event
     * @return void
     */
    public function handle(ModelCreated $event)
    {
        $model = $event->model;
        
        // Log the creation
        logger()->info("New {$model->getTable()} record created", [
            'id' => $model->id,
            'user_id' => auth()->id() ?? 'system',
            'timestamp' => now()->toDateTimeString()
        ]);
        
        // Sync with external service
        if ($model->getTable() === 'products') {
            app(\App\Services\ExternalSyncService::class)->syncProduct($model);
        }
    }
}
```

### Registering Event Listeners

Register your listeners in your `EventServiceProvider.php`:

```php
protected $listen = [
    \SwatTech\Crud\Events\ModelCreated::class => [
        \App\Listeners\LogModelCreation::class,
    ],
    \SwatTech\Crud\Events\ModelUpdated::class => [
        \App\Listeners\LogModelUpdate::class,
    ],
    \SwatTech\Crud\Events\ModelDeleted::class => [
        \App\Listeners\LogModelDeletion::class,
    ],
];
```

## New Feature Addition Guidelines

To add new features to the SwatTech CRUD package, follow these steps:

### 1. Plan the Feature

1. Define what the feature will do
2. Identify which parts of the codebase will be affected
3. Consider backward compatibility

### 2. Create the Feature Classes

Create a new feature in the appropriate namespace. For example, to add a new export format:

```php
namespace App\Features\Export;

use SwatTech\Crud\Features\Export\ExportManager;
use Illuminate\Database\Eloquent\Model;

class XMLExportFormat
{
    /**
     * Export data to XML format.
     *
     * @param \Illuminate\Database\Eloquent\Collection $data
     * @param array $options
     * @return string
     */
    public function export($data, array $options = [])
    {
        $xml = new \SimpleXMLElement('<root/>');
        
        foreach ($data as $item) {
            $node = $xml->addChild('item');
            
            foreach ($item->toArray() as $key => $value) {
                $node->addChild($key, htmlspecialchars((string)$value));
            }
        }
        
        return $xml->asXML();
    }
}
```

### 3. Extend Existing Components

Extend the existing components to include your new feature:

```php
namespace App\Extensions;

use SwatTech\Crud\Features\Export\ExportManager as BaseExportManager;
use App\Features\Export\XMLExportFormat;

class ExtendedExportManager extends BaseExportManager
{
    /**
     * Export to XML format.
     *
     * @param array $data
     * @param array $options
     * @return string
     */
    public function exportToXml(array $data, array $options = [])
    {
        $xmlExporter = new XMLExportFormat();
        return $xmlExporter->export(collect($data), $options);
    }
}
```

### 4. Register the Extended Component

Register your extended component in a service provider:

```php
$this->app->bind(
    \SwatTech\Crud\Features\Export\ExportManager::class,
    \App\Extensions\ExtendedExportManager::class
);
```

## Plugin System

SwatTech CRUD supports a plugin system for adding additional functionality without modifying the core codebase.

### Creating a Plugin

1. Create a new Laravel package
2. Implement the plugin interface:

```php
namespace YourVendor\YourPlugin;

use SwatTech\Crud\Contracts\PluginInterface;

class YourPlugin implements PluginInterface
{
    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function boot()
    {
        // Register views, config, routes, etc.
    }
    
    /**
     * Register plugin services.
     *
     * @return void
     */
    public function register()
    {
        // Register bindings, singletons, etc.
    }
    
    /**
     * Get the plugin information.
     *
     * @return array
     */
    public function info()
    {
        return [
            'name' => 'Your Plugin',
            'version' => '1.0.0',
            'description' => 'Adds additional functionality to SwatTech CRUD',
            'author' => 'Your Name',
        ];
    }
}
```

### Registering a Plugin

Register your plugin in a service provider:

```php
namespace YourVendor\YourPlugin;

use Illuminate\Support\ServiceProvider;
use SwatTech\Crud\Facades\Crud;

class YourPluginServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Crud::registerPlugin(new YourPlugin());
    }
}
```

## Theme Customization Instructions

SwatTech CRUD supports customizing the UI theme for generated views.

### Customizing the Vuexy Theme

#### 1. Publish Theme Assets

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="assets"
```

This will publish the theme assets to `public/vendor/swattech/vuexy`.

#### 2. Publish Theme Components

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="views"
```

This will publish Blade components to `resources/views/vendor/swattech/components`.

#### 3. Customize Theme Settings

In your crud.php file:

```php
'theme' => [
    'name' => 'vuexy',
    'assets_path' => 'resources/vendor/vuexy',
    'components' => [
        'layout' => 'vendor.swattech.components.layout',
        'card' => 'vendor.swattech.components.card',
        'form' => 'vendor.swattech.components.form',
        'data-table' => 'vendor.swattech.components.data-table',
        'modal' => 'vendor.swattech.components.modal',
        'filter' => 'vendor.swattech.components.filter',
    ],
    'icons' => 'feather', // Options: feather, fontawesome, bootstrap
    'color_scheme' => 'light', // Options: light, dark, auto
],
```

#### 4. Creating a Custom Theme

To create a complete custom theme:

1. Create a new directory for your theme assets:
   ```
   resources/vendor/my-theme/
   ```

2. Create the necessary Blade components:
   ```
   resources/views/vendor/swattech/my-theme/components/
   ```

3. Update your config:
   ```php
   'theme' => [
       'name' => 'my-theme',
       'assets_path' => 'resources/vendor/my-theme',
       'components' => [
           'layout' => 'vendor.swattech.my-theme.components.layout',
           // Other components...
       ],
   ],
   ```

4. Create a theme service provider:
   ```php
   namespace App\Providers;
   
   use Illuminate\Support\ServiceProvider;
   
   class MyThemeServiceProvider extends ServiceProvider
   {
       public function boot()
       {
           $this->loadViewsFrom(__DIR__.'/../../resources/views/vendor/swattech/my-theme', 'my-theme');
           
           $this->publishes([
               __DIR__.'/../../resources/assets/my-theme' => public_path('vendor/swattech/my-theme'),
           ], 'my-theme-assets');
       }
   }
   ```

### Theme Directory Structure

A custom theme should follow this structure:

```
resources/views/vendor/swattech/my-theme/
├── components/
│   ├── layout.blade.php
│   ├── card.blade.php
│   ├── form.blade.php
│   ├── data-table.blade.php
│   ├── modal.blade.php
│   ├── filter.blade.php
│   └── ...
├── views/
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   ├── show.blade.php
│   └── partials/
│       └── ...
└── layouts/
    └── app.blade.php
```

## Best Practices for Extensions

1. **Follow Laravel conventions** - Name your classes and methods according to Laravel standards
2. **Maintain backward compatibility** - Avoid breaking changes when possible
3. **Write tests** - Test your extensions thoroughly
4. **Document your extensions** - Add PHPDoc blocks to all methods
5. **Keep extensions modular** - Follow single responsibility principle
6. **Use dependency injection** - Avoid static methods where possible
7. **Consider performance** - Cache results when appropriate
8. **Follow security best practices** - Validate all input
9. **Provide configuration options** - Make extensions configurable
10. **Publish to Packagist** - Share your extensions with the community

By following these guidelines, you can extend and customize SwatTech CRUD to fit your specific application needs while maintaining compatibility with core updates.
