Collecting workspace information```markdown
# SwatTech CRUD for Laravel

![Version](https://img.shields.io/packagist/v/swattech/crud.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![Laravel](https://img.shields.io/badge/Laravel-12.x-red)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://img.shields.io/github/workflow/status/swattech/crud/tests/main?label=tests)
![Code Quality](https://img.shields.io/github/workflow/status/swattech/crud/quality/main?label=code%20quality)

SwatTech CRUD is an enterprise-grade CRUD generator for Laravel 12 with Vuexy theme integration. It analyzes your database schema and relationships, generating feature-rich models, controllers, services, repositories, views, and more with a single command.

## 📋 Features

- 🚀 **Full CRUD Generation** - Models, controllers, views, and routes
- 🔄 **Smart Relationship Detection** - Auto-detects and implements relationships
- 🧩 **Repository Pattern** - Clean, maintainable code structure
- 🔍 **Advanced Filtering** - Sortable, filterable data tables
- 📤 **Export Options** - CSV, Excel, PDF export functionality
- 🎨 **Vuexy Theme** - Beautiful UI components built for the Vuexy Admin theme
- 🔒 **Authorization** - Policy generation with ownership checks
- ⚡ **API Resources** - RESTful API endpoints with proper resources
- 📝 **Validation** - Form request validation based on your schema
- 🧪 **Test Generation** - Unit, feature, and browser tests

## 🔧 Installation

### Requirements

- PHP 8.1 or higher
- Laravel 12.x
- Composer

### Via Composer

```bash
composer require swattech/crud
```

### Publish Configuration

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="config"
```

## ⚙️ Configuration

Edit the published configuration file at `config/crud.php` to customize paths, namespaces, and behavior:

```php
// config/crud.php

return [
    'paths' => [
        'models' => 'app/Models',
        'controllers' => [
            'web' => 'app/Http/Controllers',
            'api' => 'app/Http/Controllers/API',
        ],
        // Other path configurations...
    ],
    
    // Other configuration options...
];
```

## 🚀 Usage

### Generate Complete CRUD

```bash
php artisan crud:generate products
```

This generates all necessary files for the `products` table.

### Generate API Only

```bash
php artisan crud:api products
```

### Generate With Specific Options

```bash
php artisan crud:generate products --with-api --skip-views --force
```

### Generate Relationships Only

```bash
php artisan crud:relationships products
```

## 📚 Command Reference

### crud:generate

Generate complete CRUD operations for a table

```bash
php artisan crud:generate {table} [options]
```

Options:
- `--connection=` : Database connection to use
- `--path=` : Custom output path
- `--namespace=` : Custom namespace
- `--with-api` : Generate API endpoints
- `--with-tests` : Generate tests
- `--force` : Overwrite existing files
- `--skip-views` : Skip generating views
- `--skip-migration` : Skip generating migrations
- `--skip-factory` : Skip generating factories
- `--theme=` : Theme to use (default: vuexy)

### crud:api

Generate API resources and controllers

```bash
php artisan crud:api {table} [options]
```

### crud:relationships

Generate relationship methods

```bash
php artisan crud:relationships {table} [options]
```

### crud:docs

Generate documentation

```bash
php artisan crud:docs {table} [options]
```

### crud:tests

Generate test suite

```bash
php artisan crud:tests {table} [options]
```

## 🔌 Extending

### Custom Generators

Create your own generators by extending the base generator classes:

```php
namespace App\Generators;

use SwatTech\Crud\Generators\ModelGenerator as BaseModelGenerator;

class CustomModelGenerator extends BaseModelGenerator
{
    protected function getStub()
    {
        return resource_path('stubs/custom-model.stub');
    }
}
```

Register your generator in a service provider:

```php
$this->app->bind(
    \SwatTech\Crud\Generators\ModelGenerator::class,
    \App\Generators\CustomModelGenerator::class
);
```

### Custom Stubs

Publish the stubs to customize the generated code:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="stubs"
```

Edit the stubs in `resources/stubs/vendor/swattech/crud`.

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

Check your `.env` file for correct database credentials.

### JavaScript/CSS Assets Not Found

```bash
php artisan vendor:publish --provider="SwatTech\Crud\SwatTechCrudServiceProvider" --tag="assets" --force
npm install
npm run dev
```

## 📝 License

The SwatTech CRUD package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 👥 Contributing

Thank you for considering contributing to SwatTech CRUD! Please read our Contributing Guide before submitting a pull request.

1. Fork the repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m 'Add feature'`
4. Push to the branch: `git push origin feature-name`
5. Submit a pull request

## 📦 Credits

- Developed by [Swat Info System](https://swatinfosystem.com)
- Made with ❤️ for Laravel developers
```
