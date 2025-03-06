Collecting workspace information```markdown
# Configuration Guide

This guide covers all the configuration options available in the SwatTech CRUD package, including customization methods and best practices.

## Publishing the Configuration

Before you can customize the configuration, you need to publish it to your application:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="config"
```

This will create a crud.php file in your project.

## Configuration Options

The configuration file contains several sections that control different aspects of the CRUD generation:

### Paths Configuration

These settings control where the generated files will be stored:

```php
'paths' => [
    'models' => app_path('Models'),
    'controllers' => app_path('Http/Controllers'),
    'repositories' => app_path('Repositories'),
    'services' => app_path('Services'),
    'requests' => app_path('Http/Requests'),
    'resources' => app_path('Http/Resources'),
    'views' => resource_path('views'),
    'factories' => database_path('factories'),
    'seeders' => database_path('seeders'),
    'migrations' => database_path('migrations'),
    'tests' => base_path('tests'),
    'policies' => app_path('Policies'),
    'observers' => app_path('Observers'),
    'events' => app_path('Events'),
    'listeners' => app_path('Listeners'),
    'jobs' => app_path('Jobs'),
],
```

### Namespaces Configuration

Define the namespaces for your generated classes:

```php
'namespaces' => [
    'models' => 'App\\Models',
    'controllers' => 'App\\Http\\Controllers',
    'repositories' => 'App\\Repositories',
    'interfaces' => 'App\\Repositories\\Interfaces',
    'services' => 'App\\Services',
    'requests' => 'App\\Http\\Requests',
    'resources' => 'App\\Http\\Resources',
    'factories' => 'Database\\Factories',
    'seeders' => 'Database\\Seeders',
    'policies' => 'App\\Policies',
    'observers' => 'App\\Observers',
    'events' => 'App\\Events',
    'listeners' => 'App\\Listeners',
    'jobs' => 'App\\Jobs',
],
```

### Database Configuration

Control how the package interacts with your database:

```php
'database' => [
    'connection' => env('DB_CONNECTION', 'mysql'),
    'timestamps' => true,
    'soft_deletes' => true,
    'uuid' => false,
    'date_format' => 'Y-m-d H:i:s',
],
```

### Relationship Detection

Configure how relationships are automatically detected:

```php
'relationships' => [
    'detect' => true,
    'foreign_key_pattern' => '{table}_id',
    'polymorphic_pattern' => '{name}able',
    'custom_naming' => [
        // Map custom relationship names
        // 'users' => [
        //     'authored_posts' => 'posts:user_id,author_id',
        // ],
    ],
    'excluded_tables' => [
        'migrations', 'password_reset_tokens', 'failed_jobs',
    ],
],
```

### UI Theme Settings

Configure the UI theme for generated views:

```php
'theme' => [
    'name' => 'vuexy',
    'assets_path' => 'resources/vendor/vuexy',
    'components' => [
        'layout' => 'components.layout',
        'card' => 'components.card',
        'form' => 'components.form',
        'data-table' => 'components.data-table',
        'modal' => 'components.modal',
        'filter' => 'components.filter',
    ],
    'icons' => 'feather', // Options: feather, fontawesome, bootstrap
    'color_scheme' => 'light',
],
```

### API Configuration

Control API generation features:

```php
'api' => [
    'prefix' => 'api',
    'version' => 'v1',
    'domain' => null,
    'rate_limit' => 60,
    'rate_limit_per_minute' => true,
    'include_validation' => true,
    'middleware' => ['api'],
    'response_format' => [
        'data_key' => 'data',
        'meta_key' => 'meta',
        'include_status' => true,
        'include_message' => true,
    ],
],
```

### Authorization Settings

Configure authorization features:

```php
'authorization' => [
    'generate_policies' => true,
    'roles_integration' => false,
    'super_admin_role' => 'super-admin',
    'default_permissions' => [
        'viewAny' => true,
        'view' => true,
        'create' => true,
        'update' => true,
        'delete' => true,
        'restore' => true,
        'forceDelete' => true,
    ],
],
```

### Caching Configuration

Control caching behavior:

```php
'cache' => [
    'enabled' => true,
    'driver' => env('CACHE_DRIVER', 'file'),
    'prefix' => 'crud_',
    'ttl' => 3600, // in seconds (1 hour)
    'tags_enabled' => true,
    'invalidate_on_update' => true,
    'invalidate_on_create' => true,
    'invalidate_on_delete' => true,
],
```

### Custom Field Mappings

Define custom mappings for database to PHP and HTML:

```php
'field_types' => [
    'db_to_php' => [
        'integer' => 'int',
        'bigint' => 'int',
        'float' => 'float',
        'decimal' => 'float',
        'string' => 'string',
        'varchar' => 'string',
        'text' => 'string',
        'boolean' => 'bool',
        'tinyint' => 'bool',
        'date' => '\Carbon\Carbon',
        'datetime' => '\Carbon\Carbon',
        'timestamp' => '\Carbon\Carbon',
        'json' => 'array',
    ],
    'db_to_html' => [
        'integer' => 'number',
        'bigint' => 'number',
        'float' => 'number',
        'decimal' => 'number',
        'string' => 'text',
        'varchar' => 'text',
        'text' => 'textarea',
        'boolean' => 'checkbox',
        'tinyint' => 'checkbox',
        'date' => 'date',
        'datetime' => 'datetime-local',
        'timestamp' => 'datetime-local',
        'json' => 'textarea',
    ],
],
```

### Validation Rules Template

Configure default validation rules for common field types:

```php
'validation_rules' => [
    'string' => 'string|max:255',
    'text' => 'string',
    'integer' => 'integer',
    'float' => 'numeric',
    'boolean' => 'boolean',
    'date' => 'date',
    'datetime' => 'date',
    'email' => 'email|max:255',
    'password' => 'string|min:8',
    'enum' => 'string|in:{{values}}',
    'unique' => 'unique:{{table}},{{column}}',
    'exists' => 'exists:{{table}},{{column}}',
],
```

### Feature Toggles

Control which features are enabled:

```php
'features' => [
    'soft_deletes' => true,
    'translations' => false,
    'api_resources' => true,
    'factories' => true,
    'seeders' => true,
    'observers' => true,
    'events' => true,
    'jobs' => false,
    'notifications' => false,
    'policies' => true,
    'advanced_filtering' => true,
    'export_import' => false,
    'activity_logging' => false,
],
```

### Testing Configuration

Configure test generation options:

```php
'tests' => [
    'generate' => true,
    'unit_tests' => true,
    'feature_tests' => true,
    'browser_tests' => false,
    'api_tests' => true,
    'test_factories' => true,
],
```

### Documentation Generation

Configure documentation generation options:

```php
'documentation' => [
    'generate' => false,
    'format' => 'markdown', // Options: markdown, html
    'output_path' => base_path('docs/generated'),
    'include_api' => true,
    'include_schema' => true,
    'include_relationships' => true,
],
```

## Sample Configurations for Common Scenarios

### API-Only Application

```php
'api' => [
    'prefix' => 'api',
    'version' => 'v1',
    'domain' => null,
    'rate_limit' => 120,
    'middleware' => ['api', 'auth:sanctum'],
],
'theme' => [
    'name' => null, // Disable UI theme
],
'features' => [
    'api_resources' => true,
    'factories' => true,
    'observers' => true,
    'policies' => true,
    'translations' => false,
    'views' => false,
],
```

### Multi-Tenant Application

```php
'database' => [
    'connection' => env('DB_CONNECTION', 'tenant'),
    'timestamps' => true,
    'soft_deletes' => true,
],
'authorization' => [
    'generate_policies' => true,
    'roles_integration' => true,
    'tenant_column' => 'tenant_id',
    'automatically_scope_queries' => true,
],
'paths' => [
    'models' => app_path('Models/Tenant'),
    'controllers' => app_path('Http/Controllers/Tenant'),
    'repositories' => app_path('Repositories/Tenant'),
],
'namespaces' => [
    'models' => 'App\\Models\\Tenant',
    'controllers' => 'App\\Http\\Controllers\\Tenant',
    'repositories' => 'App\\Repositories\\Tenant',
],
```

### High-Traffic Application with Caching

```php
'cache' => [
    'enabled' => true,
    'driver' => env('CACHE_DRIVER', 'redis'),
    'prefix' => 'crud_',
    'ttl' => 1800, // 30 minutes
    'tags_enabled' => true,
    'invalidate_on_update' => true,
],
'database' => [
    'connection' => env('DB_CONNECTION', 'mysql'),
    'read_connection' => env('DB_READ_CONNECTION', 'mysql_read'),
    'write_connection' => env('DB_WRITE_CONNECTION', 'mysql_write'),
    'use_query_caching' => true,
],
```

## Customization Guidelines

### Publishing Stubs

To customize the code generation templates, publish the stubs:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="stubs"
```

This will create stub files in `resources/vendor/swattech/crud/stubs` that you can modify.

### Custom Generators

You can create your own generators by extending the base generator classes:

```php
namespace App\Generators;

use SwatTech\Crud\Generators\ModelGenerator as BaseModelGenerator;

class CustomModelGenerator extends BaseModelGenerator
{
    protected function getStub()
    {
        return resource_path('stubs/custom-model.stub');
    }
    
    // Override other methods as needed
}
```

Then bind your custom generator in a service provider:

```php
$this->app->bind(
    \SwatTech\Crud\Generators\ModelGenerator::class,
    \App\Generators\CustomModelGenerator::class
);
```

## Environment Variables

The following environment variables can be used to configure the package:

| Variable | Description | Default |
|----------|-------------|---------|
| `CRUD_DB_CONNECTION` | Database connection to use | `DB_CONNECTION` |
| `CRUD_THEME` | UI theme name | `vuexy` |
| `CRUD_CACHE_ENABLED` | Enable caching | `true` |
| `CRUD_CACHE_TTL` | Cache lifetime in seconds | `3600` |
| `CRUD_API_PREFIX` | API route prefix | `api` |
| `CRUD_API_VERSION` | API version | `v1` |
| `CRUD_GENERATE_TESTS` | Generate tests | `true` |
| `CRUD_GENERATE_DOCS` | Generate documentation | `false` |

## Configuration Overriding

### Command-Line Overrides

Many configuration options can be overridden via command-line flags:

```bash
php artisan crud:generate users --connection=mysql_read --theme=bootstrap --no-tests
```

### Runtime Configuration

You can override configuration at runtime:

```php
use SwatTech\Crud\Facades\Crud;

// Override config for a single operation
Crud::withConfig(['soft_deletes' => false])
    ->generate('posts');
```

## Default Values

Most configuration options have sensible defaults that work well with standard Laravel applications. Key defaults include:

- Paths follow Laravel's standard directory structure
- Namespaces match Laravel's default namespacing
- Timestamps are enabled (`created_at` and `updated_at`)
- Soft deletes are enabled (adds `deleted_at` column)
- Vuexy theme is used for UI components
- API uses Laravel's API middleware group

## Debugging Configuration

To debug your current configuration, you can use the command:

```bash
php artisan crud:debug-config
```

This will output all your current configuration values and help identify any issues.

Enable detailed logging during generation with:

```php
'debug' => [
    'verbose_output' => true,
    'log_level' => 'debug', // Options: debug, info, notice, warning, error
    'log_to_file' => true,
    'log_file' => storage_path('logs/crud-generator.log'),
],
```

## Performance Tuning

For large databases or complex applications, tune these settings:

```php
'performance' => [
    'chunk_size' => 1000, // For batch operations
    'defer_relationship_loading' => true, // Analyze relationships only when needed
    'skip_unused_components' => true, // Don't generate components you won't use
    'parallel_generation' => false, // Experimental: Generate files in parallel
    'memory_limit' => '512M', // Memory limit for generator processes
],
```

## Security Configuration

Enhance security with these options:

```php
'security' => [
    'force_authorization' => true, // Always generate policies
    'validate_all_inputs' => true, // Generate comprehensive validation
    'sanitize_output' => true, // Escape output in generated views
    'csrf_protection' => true, // Include CSRF protection in forms
    'api_authentication' => 'sanctum', // Options: sanctum, passport, jwt, none
    'rate_limiting' => true, // Apply rate limiting to API endpoints
],
```

## Feature Toggles

Control specific features with feature toggles:

```php
'features' => [
    // Core features
    'soft_deletes' => true,
    'timestamps' => true,
    'uuid_primary_key' => false,
    
    // Components
    'repositories' => true,
    'services' => true,
    'api_resources' => true,
    'factories' => true,
    'seeders' => true,
    'policies' => true,
    
    // Advanced features
    'activity_logging' => false,
    'export_import' => false,
    'internationalization' => false,
    'versioning' => false,
    'batch_operations' => false,
    'notifications' => false,
    
    // UI features
    'modal_forms' => false,
    'bulk_actions' => false,
    'advanced_filtering' => true,
    'charts' => false,
    'file_uploads' => false,
],
```

Each feature can be toggled on or off to customize the generated code to your specific needs.
