Collecting workspace information```markdown
# Advanced Usage

This guide covers advanced features and usage patterns for the SwatTech CRUD package, helping you leverage its full potential for complex applications.

## Complex Usage Scenarios

### Multi-tenant Applications

Configure the CRUD generator for multi-tenant applications:

```php
// In a service provider
\SwatTech\Crud\Facades\Crud::withConfig([
    'database' => [
        'tenant_column' => 'tenant_id',
        'automatically_scope_queries' => true,
    ],
    'paths' => [
        'models' => app_path('Models/Tenant'),
        'controllers' => app_path('Http/Controllers/Tenant'),
    ],
    'namespaces' => [
        'models' => 'App\\Models\\Tenant',
    ]
]);
```

Generate tenant-scoped CRUD:

```bash
php artisan crud:generate orders --tenant
```

### Nested Resources

Generate nested CRUD operations for parent-child relationships:

```bash
php artisan crud:generate comments --parent=posts
```

This will create routes like `/posts/{post}/comments` and controllers with parent context.

### API Versioning

Generate versioned API resources:

```bash
php artisan crud:api products --versions=v1,v2
```

This creates version-specific resources, controllers, and routes.

## Customizing Generators

### Extending Generator Classes

Create custom generators by extending the base classes:

```php
namespace App\Generators;

use SwatTech\Crud\Generators\ModelGenerator as BaseModelGenerator;

class CustomModelGenerator extends BaseModelGenerator
{
    protected function getStub()
    {
        return resource_path('stubs/custom-model.stub');
    }
    
    protected function buildClass(string $table, array $options)
    {
        $content = parent::buildClass($table, $options);
        
        // Add custom behavior to all generated models
        $content = str_replace(
            'class {{class}} extends Model',
            'class {{class}} extends Model implements AuditableInterface',
            $content
        );
        
        return $content;
    }
}
```

Register your custom generator in a service provider:

```php
public function register()
{
    $this->app->bind(
        \SwatTech\Crud\Generators\ModelGenerator::class,
        \App\Generators\CustomModelGenerator::class
    );
}
```

### Custom Stubs

Create custom stub templates by publishing and modifying the package stubs:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="stubs"
```

For example, customize the model stub to always include auditing:

```php
// resources/vendor/swattech/crud/stubs/model.stub
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
    
    // Rest of the stub...
}
```

## Integration with Other Packages

### Laravel Sanctum Authentication

Generate API resources with Sanctum authentication:

```bash
php artisan crud:api users --auth=sanctum
```

This adds the necessary middleware and authentication checks.

### Spatie Permission Integration

Integrate role-based access with Spatie Permission:

```bash
php artisan crud:generate products --with-permissions
```

This generates policies with role checks:

```php
public function viewAny(User $user)
{
    return $user->hasPermissionTo('view products');
}
```

### Laravel Telescope Integration

Configure your repositories to log queries with Telescope:

```php
// In config/crud.php
'repositories' => [
    'debug' => [
        'log_queries' => true,
        'telescope_enabled' => true,
    ],
],
```

## Performance Optimization

### Eager Loading Relationships

Configure default eager loading for your repositories:

```php
// In config/crud.php
'performance' => [
    'eager_load' => [
        'users' => ['profile', 'roles'],
        'products' => ['category', 'tags'],
    ],
],
```

### Query Caching

Enable repository query caching for better performance:

```php
// In config/crud.php
'cache' => [
    'enabled' => true,
    'driver' => 'redis',
    'ttl' => 3600, // 1 hour
    'prefix' => 'crud_',
    'tags_enabled' => true,
],
```

Override caching per operation:

```php
$userRepository->withCachingDisabled()->all();
// or
$userRepository->withCustomCacheTtl(60)->find($id); // 60 seconds
```

### Database Read/Write Separation

Configure separate connections for read and write operations:

```php
// In config/crud.php
'database' => [
    'read_connection' => 'mysql_read',
    'write_connection' => 'mysql_write',
    'use_separate_connections' => true,
],
```

## Bulk Operations

### Batch Creation

Using the service layer for batch operations:

```php
$productService->batchCreate([
    ['name' => 'Product 1', 'price' => 10.99],
    ['name' => 'Product 2', 'price' => 19.99],
    ['name' => 'Product 3', 'price' => 5.99],
]);
```

### Batch Updates

Update multiple records at once:

```php
$productService->batchUpdate([
    ['id' => 1, 'price' => 12.99],
    ['id' => 2, 'price' => 21.99],
    ['id' => 3, 'price' => 7.99],
]);
```

### Batch Deletion

Delete multiple records in a single operation:

```php
$productService->batchDelete([1, 2, 3]);
```

### Processing Large Datasets

For very large datasets, use the batch manager:

```php
use SwatTech\Crud\Facades\BatchManager;

BatchManager::process('create', $largeDataset, [
    'model' => 'Product',
    'chunk_size' => 500,
    'notification' => true,
]);
```

## Event Handling

### Available Events

The package dispatches these events:

```php
// Create events
SwatTech\Crud\Events\ModelCreating::class
SwatTech\Crud\Events\ModelCreated::class

// Update events
SwatTech\Crud\Events\ModelUpdating::class
SwatTech\Crud\Events\ModelUpdated::class

// Delete events
SwatTech\Crud\Events\ModelDeleting::class
SwatTech\Crud\Events\ModelDeleted::class

// Batch events
SwatTech\Crud\Events\BatchOperationStarted::class
SwatTech\Crud\Events\BatchOperationCompleted::class
SwatTech\Crud\Events\BatchOperationFailed::class
```

### Creating Event Listeners

Generate event listeners for CRUD operations:

```bash
php artisan crud:event-listeners products
```

Implement your listeners:

```php
namespace App\Listeners;

use SwatTech\Crud\Events\ModelCreated;

class ProductCreatedListener
{
    public function handle(ModelCreated $event)
    {
        if ($event->model instanceof \App\Models\Product) {
            // Update inventory, notify administrators, etc.
        }
    }
}
```

## Caching Strategies

### Repository Cache Decorator

The package provides a cache decorator that can be applied to any repository:

```php
use SwatTech\Crud\Repositories\CacheDecorator;
use App\Repositories\ProductRepository;

$repository = new ProductRepository($productModel);
$cachedRepository = new CacheDecorator($repository, $cacheManager);
```

### Custom Cache Tags

Configure custom cache tags for specific models:

```php
// In config/crud.php
'cache' => [
    'tags' => [
        'products' => ['products', 'inventory', 'shop'],
        'users' => ['users', 'auth'],
    ],
],
```

### Cache Invalidation

Automatically invalidate cache on model changes:

```php
// In config/crud.php
'cache' => [
    'invalidate_on_create' => true,
    'invalidate_on_update' => true,
    'invalidate_on_delete' => true,
    'invalidate_related' => true,
],
```

## Security Recommendations

### Authorization Policies

Always generate and use policies:

```bash
php artisan crud:generate products --with-policies
```

Enforce policy checks in your controllers:

```php
public function update(ProductUpdateRequest $request, $id)
{
    $product = $this->service->findById($id);
    $this->authorize('update', $product);
    
    // Continue with update...
}
```

### Request Validation

Use strict validation rules by configuring defaults:

```php
// In config/crud.php
'validation_rules' => [
    'email' => 'email|max:255|unique:users,email',
    'password' => 'string|min:10|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\x])(?=.*[!$#%]).*$/',
    'numeric' => 'numeric|between:0,999999.99',
],
```

### Query Scope Protection

Automatically scope queries based on user context:

```php
// In your repository
protected function applyUserScope($query)
{
    if (auth()->check()) {
        if (!auth()->user()->isAdmin()) {
            return $query->where('user_id', auth()->id());
        }
    }
    return $query;
}
```

Enable this for generated repositories:

```php
// In config/crud.php
'security' => [
    'apply_user_scope' => true,
    'scope_column' => 'user_id', // or tenant_id, etc.
    'admin_role' => 'admin',
],
```

## Workflow Implementations

### Approval Workflow

Implement an approval workflow using states:

```php
// In your model
use App\Traits\HasStates;

class Product extends Model
{
    use HasStates;
    
    const STATE_DRAFT = 'draft';
    const STATE_PENDING = 'pending_approval';
    const STATE_APPROVED = 'approved';
    const STATE_REJECTED = 'rejected';
    
    protected $states = [
        self::STATE_DRAFT => [
            'can_transition_to' => [self::STATE_PENDING],
        ],
        self::STATE_PENDING => [
            'can_transition_to' => [self::STATE_APPROVED, self::STATE_REJECTED],
            'requires_permission' => 'approve_products',
        ],
        // Other states...
    ];
}
```

With services:

```php
$productService->changeState($productId, Product::STATE_APPROVED);
```

### Content Publishing Workflow

Generate CRUD with publishing workflow:

```bash
php artisan crud:generate articles --with-publishing
```

This creates additional methods:

```php
// In ArticleService
public function publish($id)
{
    $article = $this->findById($id);
    $article->published_at = now();
    $article->status = 'published';
    $article->save();
    
    event(new ArticlePublished($article));
    
    return $article;
}

public function unpublish($id)
{
    $article = $this->findById($id);
    $article->published_at = null;
    $article->status = 'draft';
    $article->save();
    
    event(new ArticleUnpublished($article));
    
    return $article;
}
```

## Migration Strategies

### Migrating Existing Applications

To integrate SwatTech CRUD into existing applications:

1. Install the package:
```bash
composer require swattech/crud
```

2. Publish the configuration:
```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="config"
```

3. Map existing models to tables:
```php
// In config/crud.php
'existing_models' => [
    'User' => [
        'table' => 'users',
        'namespace' => 'App\\Models',
    ],
    // Other models...
],
```

4. Generate only missing components:
```bash
php artisan crud:generate users --skip-model --skip-migration
```

### Incremental Migration

For large applications, migrate incrementally:

1. Start with API resources:
```bash
php artisan crud:api products --only-resources
```

2. Add repositories and services:
```bash
php artisan crud:generate products --only-repository --only-service
```

3. Replace controllers gradually:
```bash
php artisan crud:generate products --only-controller
```

4. Finally, add UI components:
```bash
php artisan crud:generate products --only-views
```

### Data Migration

When restructuring data models:

```bash
// Generate a migration plan
php artisan crud:migration-plan old_table new_table

// Execute the migration
php artisan crud:migrate-data old_table new_table --map=column_mapping.json
```

Example column mapping:
```json
{
    "old_name": "name",
    "old_description": "description",
    "old_price": "price_cents",
    "old_categories": {
        "type": "relationship",
        "handler": "App\\Handlers\\CategoryMigrationHandler"
    }
}
```

## Advanced Command Chaining

Combine multiple generation commands:

```bash
php artisan crud:workflow generate \
    --model=Product \
    --with-repository \
    --with-service \
    --with-api \
    --with-admin-ui \
    --with-tests \
    --with-docs
```

This creates a complete workflow from model to documentation in one command.