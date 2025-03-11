# SwatTech CRUD for Laravel 12

![Version](https://img.shields.io/packagist/v/swattech/crud.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![Laravel](https://img.shields.io/badge/Laravel-12.x-red)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://img.shields.io/github/workflow/status/swattech/crud/tests/main?label=tests)
![Code Quality](https://img.shields.io/github/workflow/status/swattech/crud/quality/main?label=code%20quality)

SwatTech CRUD is an enterprise-grade CRUD generator for Laravel 12 with Vuexy theme integration. It analyzes your database schema and automatically detects relationships, generating feature-rich code with a single command.

## 📋 Comprehensive Features

### Core Generation
- 🚀 **Complete CRUD Operations** - Models, controllers, repositories, services, views, and routes
- 🔄 **Smart Relationship Detection** - Auto-detects and implements all relationship types
- 🧩 **Repository Pattern** - Clean, maintainable code structure with caching implementation
- 📱 **Responsive Views** - Mobile-friendly interfaces with Vuexy theme components
- 🧪 **Test Suite Generation** - Unit, feature, API, and browser tests with realistic data

### Advanced Features
- 📝 **Smart Validation** - Form request classes with context-aware rules based on schema
- 🔍 **Powerful Filtering** - Advanced search, column filtering, and custom scopes
- 📊 **Data Export** - CSV, Excel, PDF export with customizable formatting
- 🔒 **Authorization** - Policy generation with ownership checks and role integration
- 📡 **API Resources** - RESTful API endpoints with proper resources and transformers
- 📚 **Documentation** - Auto-generated API and usage documentation

### Enterprise Features
- 📜 **Activity Logging** - Comprehensive audit trail system for all operations
- 📂 **Media Management** - File upload and management with preview support
- 🔄 **Soft Deletes** - Trash management with restore capabilities
- 🌐 **Internationalization** - Multi-language support with translation management
- 📊 **Batch Operations** - Process records in bulk with progress tracking
- 🔄 **Versioning** - Track changes with version history and comparison

### UI Components
- 📋 **Data Tables** - Sortable, filterable data tables with pagination
- 📑 **Tabs & Cards** - Organized content with tabbed interfaces
- 🔍 **Advanced Filters** - Date ranges, multi-select filters, saved queries
- 📤 **Export Buttons** - One-click data export to multiple formats
- 📝 **Rich Forms** - Date pickers, wysiwyg editors, select2 dropdowns
- 🔔 **Notifications** - User notification system with real-time updates

## 🔧 Installation

### Prerequisites

- PHP 8.1 or higher
- Laravel 12.x
- Composer 2.0+
- Database connection configured in your .env file

### Step 1: Install via Composer

```bash
composer require swattech/crud
```

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="config"
```

### Step 3: Publish Assets (Optional)

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="assets"
```

### Step 4: Run Migrations (Optional)

Only needed if you want to use activity logging, media, etc.

```bash
php artisan migrate
```

## ⚙️ Configuration

The package is highly customizable through the `config/crud.php` file:

```php
// config/crud.php

return [
    // Path configurations for generated files
    'paths' => [
        'models' => 'app/Models',
        'controllers' => [
            'web' => 'app/Http/Controllers',
            'api' => 'app/Http/Controllers/API',
        ],
        'views' => 'resources/views',
        // Additional paths...
    ],
    
    // Namespace configurations
    'namespaces' => [
        'models' => 'App\\Models',
        // Additional namespaces...
    ],
    
    // Model settings
    'models' => [
        'soft_deletes' => true, 
        'timestamps' => true,
        'with_factory' => true,
        // Additional model settings...
    ],
    
    // Theme settings
    'theme' => [
        'name' => 'vuexy',
        'assets' => [
            // Theme assets...
        ],
    ],
    
    // Additional configuration options...
];
```

## 🚀 Usage Examples

### Generate Complete CRUD

Generate all files for a table:

```bash
php artisan crud:generate products
```

This will create:
- Model with relationships
- Repository and Service classes
- Controller with CRUD actions
- Form Request validation classes
- Blade views with Vuexy theme
- Routes in web.php
- Factory and Seeder
- Policy for authorization
- Tests for all components

### Generate API Only

Create API endpoints and resources:

```bash
php artisan crud:api products
```

### Generate with Specific Options

Customize the generation process:

```bash
php artisan crud:generate products --with-api --skip-views --force
```

### Generate for Multiple Tables

Process all tables in your database:

```bash
php artisan crud:generate --all
```

### Generate Only Relationships

Add relationship methods to existing models:

```bash
php artisan crud:relationships products
```

### Generate Documentation

Create comprehensive documentation:

```bash
php artisan crud:docs products
```

## 📚 Command Reference

### crud:generate

```bash
php artisan crud:generate {table?} 
                          {--all : Generate CRUD for all tables}
                          {--connection= : Database connection to use}
                          {--path= : Custom output path}
                          {--namespace= : Custom namespace}
                          {--with-api : Generate API endpoints}
                          {--with-tests : Generate tests}
                          {--model : Generate only model}
                          {--controller : Generate only controller}
                          {--repository : Generate only repository}
                          {--service : Generate only service}
                          {--views : Generate only views}
                          {--factory : Generate only factory}
                          {--migration : Generate only migration}
                          {--seeder : Generate only seeder}
                          {--policy : Generate only policy}
                          {--resource : Generate only API resource}
                          {--request : Generate only form requests}
                          {--observer : Generate only observer}
                          {--event : Generate only events}
                          {--listener : Generate only listeners}
                          {--job : Generate only jobs}
                          {--force : Overwrite existing files}
                          {--dry-run : Run without creating any files}
                          {--theme= : Specify the theme for views (default: vuexy)}
```

### crud:api

Generate API-specific components:

```bash
php artisan crud:api {table?}
                     {--all : Generate API for all tables}
                     {--connection= : Database connection to use}
                     {--controller : Generate only API controller}
                     {--resource : Generate only API resource}
                     {--documentation : Generate only API documentation}
                     {--transformer : Generate only API transformers}
                     {--version= : API version (default: v1)}
                     {--versions= : Multiple API versions separated by comma}
                     {--prefix= : API route prefix}
                     {--middleware= : API middleware to apply}
                     {--auth= : Authentication type (token, sanctum, passport, jwt)}
                     {--format= : Response format (json, jsonapi)}
                     {--collection : Generate resource collection}
                     {--swagger : Generate Swagger/OpenAPI documentation}
                     {--force : Overwrite existing files}
```

### crud:relationships

Analyze and generate relationships:

```bash
php artisan crud:relationships {table?}
                              {--all : Generate relationships for all tables}
                              {--connection= : Database connection to use}
                              {--detect : Auto-detect relationships only}
                              {--inverse : Generate inverse relationships}
                              {--force : Overwrite existing methods}
```

### crud:docs

Generate comprehensive documentation:

```bash
php artisan crud:docs {table?}
                      {--all : Generate documentation for all tables}
                      {--api : Generate only API documentation}
                      {--schema : Generate only database schema documentation}
                      {--relationships : Generate only relationship diagrams}
                      {--crud : Generate only CRUD operations documentation}
                      {--validation : Generate only validation rules documentation}
                      {--ui : Generate only UI user guides}
                      {--format= : Documentation format (markdown, html, pdf)}
                      {--output= : Output directory for documentation files}
                      {--force : Overwrite existing documentation files}
```

### crud:tests

Generate test suite:

```bash
php artisan crud:tests {table?}
                       {--all : Generate tests for all tables}
                       {--connection= : Database connection to use}
                       {--unit : Generate only unit tests}
                       {--feature : Generate only feature tests}
                       {--api : Generate only API tests}
                       {--browser : Generate only browser tests}
                       {--force : Overwrite existing test files}
```

## 🔌 Extending and Customizing

### Custom Generators

Extend the base generator classes to customize the code generation:

```php
namespace App\Generators;

use SwatTech\Crud\Generators\ModelGenerator as BaseModelGenerator;

class CustomModelGenerator extends BaseModelGenerator
{
    public function getStub(string $filename = ""): string
    {
        return resource_path('stubs/custom-model.stub');
    }
    
    public function buildClass(string $table, array $schema, array $relationships): string
    {
        // Custom implementation
        $content = parent::buildClass($table, $schema, $relationships);
        
        // Add your customizations
        $content = str_replace(
            '// Custom traits',
            'use App\\Traits\\CustomTrait;',
            $content
        );
        
        return $content;
    }
}
```

Register your custom generator in a service provider:

```php
// In AppServiceProvider or custom service provider
public function register()
{
    $this->app->bind(
        \SwatTech\Crud\Generators\ModelGenerator::class,
        \App\Generators\CustomModelGenerator::class
    );
}
```

### Custom Stubs

Publish and edit the stub templates:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="stubs"
```

Edit the stubs in `resources/stubs/vendor/swattech/crud/`:

- Controllers: `controller.stub`, `api_controller.stub`
- Models: `model.stub`
- Views: `views/index.blade.stub`, `views/create.blade.stub`, etc.
- And many more...

### Custom Themes

The package comes with Vuexy theme integration by default, but you can create your own theme:

1. Publish the stubs: `php artisan vendor:publish --tag="stubs"`
2. Create a new theme directory: `resources/stubs/vendor/swattech/crud/mytheme/`
3. Add your theme files (layout.stub, views/*, components/*)
4. Update configuration: `'theme' => ['name' => 'mytheme']`

## ❓ Troubleshooting

### Class Not Found Errors

```bash
composer dump-autoload
```

### Permission Issues

```bash
chmod -R 755 app/
chmod -R 755 resources/
```

### Database Connection Issues

Make sure your `.env` file has the correct database configuration:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### JavaScript/CSS Assets Not Found

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="assets" --force
npm install
npm run dev
```

### Relationship Detection Issues

If relationships aren't detected properly:

```bash
php artisan crud:relationships your_table --detect --verbose
```

### Customizing Generated Code

To make minor changes without extending classes:

1. Publish the config: `php artisan vendor:publish --tag="config"`
2. Publish the stubs: `php artisan vendor:publish --tag="stubs"`
3. Edit the appropriate stub files
4. Update the config to use custom stubs: `'stubs' => ['use_custom' => true]`

## 📝 License

The SwatTech CRUD package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 👥 Contributing

We welcome contributions to improve SwatTech CRUD! Please follow these steps:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m 'Add feature'`
4. Push to the branch: `git push origin feature-name`
5. Submit a pull request

Please make sure your code follows our coding standards and includes appropriate tests.

## 📦 Credits

- Developed by [Swat Info System](https://swatinfosystem.com)
- Made with ❤️ for Laravel developers