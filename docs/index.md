Collecting workspace informationHere's the complete implementation of the index.md main documentation file:

```markdown
# SwatTech CRUD Package for Laravel

![Version](https://img.shields.io/packagist/v/swattech/crud.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![Laravel](https://img.shields.io/badge/Laravel-12.x-red)
![License](https://img.shields.io/packagist/l/swattech/crud.svg)

SwatTech CRUD is a powerful Laravel package that automates the generation of CRUD (Create, Read, Update, Delete) operations for your Laravel applications. It analyzes your database structure and relationships to generate models, controllers, repositories, services, views, and more.

## 📚 Documentation Contents

- [Installation](installation.md)
- [Configuration](configuration.md)
- [Relationship Management](relationships.md)
- [Advanced Usage](advanced-usage.md)
- [Extending the Package](extending.md)

## 🔍 Documentation Search

This documentation supports full-text search capabilities:

1. Use the search bar at the top of the page to find relevant content
2. Search results will show matches across all documentation pages
3. Keywords are highlighted in the search results
4. Use quotes for exact phrase matching (e.g., "relationship detection")

## 🔢 Version Information & Compatibility

| SwatTech CRUD Version | Laravel Version | PHP Version | Status      |
|-----------------------|-----------------|-------------|-------------|
| 1.x                   | 12.x            | 8.1+        | Current     |
| 0.x                   | 11.x            | 8.1+        | Deprecated  |

### Browser Compatibility

The generated UI components are tested and compatible with:

- Chrome/Edge (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)

## 🚀 Quick Start Guide

### Installation

```bash
composer require swattech/crud
```

### Publish Configuration

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="config"
```

### Generate CRUD for a Table

```bash
php artisan crud:generate users
```

See the installation guide for complete setup instructions.

## 📋 Example Usage Scenarios

### Basic CRUD Generation

Generate complete CRUD operations for a table:

```bash
php artisan crud:generate products
```

This will create:
- Model with relationships
- Repository and Service layers
- Controller with RESTful methods
- Request validation classes
- Blade views with Vuexy theme
- API resources and controllers

### API-Only Generation

Generate RESTful API endpoints without UI components:

```bash
php artisan crud:api invoices
```

### Relationship-Focused Generation

Create CRUD with special focus on relationships:

```bash
php artisan crud:relationships products --belongs-to=categories --has-many=variants
```

### Generate Tests

Create comprehensive test suite:

```bash
php artisan crud:tests orders
```

## 🔄 Framework Integration

### Integration with Laravel's Authentication

The generated code works seamlessly with Laravel's built-in authentication:

```php
// Example of authorization in generated controllers
public function edit($id)
{
    $item = $this->service->findById($id);
    $this->authorize('update', $item);  // Uses Laravel Policy
    
    return view('items.edit', compact('item'));
}
```

### Event System Integration

The package dispatches Laravel events for all CRUD operations:

- `ModelCreated`
- `ModelUpdated`
- `ModelDeleted`

### Queue Integration

Long-running operations like imports/exports utilize Laravel's queue system automatically.

## 📸 Screenshots

### Generated Index Page
![Index Page](images/index-screenshot.png)
*The generated index page includes searching, filtering, pagination and bulk actions.*

### Create/Edit Form
![Edit Form](images/edit-screenshot.png)
*Forms include validation, relationship handling and file uploads.*

### Detail View
![Detail View](images/show-screenshot.png)
*Detail views display all fields and related items.*

## ❓ Frequently Asked Questions

### How do I customize the generated code?

You can publish the stubs and customize them:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="stubs"
```

Then edit the stub files in `resources/vendor/swattech/crud/stubs`.

### Does the package support custom relationships?

Yes, the package automatically detects relationships based on database structure but also allows manual configuration through the `--relationships` option.

### Can I use my own theme instead of Vuexy?

Yes. You can publish the view templates and customize them to match your theme. Set `theme.name` in the configuration to your custom theme name.

### Does this work with multi-database setups?

Yes, specify the database connection with `--connection=mysql2` when generating.

### How can I extend the generated controllers?

The best approach is to extend the generated classes. See extending documentation for examples.

## 🤝 Community and Support

- [GitHub Repository](https://github.com/swattech/crud)
- [Issue Tracker](https://github.com/swattech/crud/issues)
- [Discord Community](https://discord.gg/swattech-crud)

### Contributing

Contributions are welcome! Please check out our [contribution guide](https://github.com/swattech/crud/blob/main/CONTRIBUTING.md) for details.

### Support

For premium support, reach out to [support@swattech.com](mailto:support@swattech.com).

### License

The SwatTech CRUD Package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
```

This comprehensive documentation file covers all the requested features including package overview, navigation links, search functionality, version information, usage examples, quick start guide, framework integration, screenshots, FAQ, and community links.This comprehensive documentation file covers all the requested features including package overview, navigation links, search functionality, version information, usage examples, quick start guide, framework integration, screenshots, FAQ, and community links.