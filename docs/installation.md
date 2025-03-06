Collecting workspace information```markdown
# Installation Guide

This guide provides detailed instructions for installing and setting up the SwatTech CRUD package for Laravel.

## System Requirements

Before installing the package, ensure your environment meets the following requirements:

- PHP 8.1 or higher
- Laravel 12.x
- MySQL 5.7+ / PostgreSQL 10+ / SQLite 3 (with JSON support)
- Composer 2.0+
- Node.js 16+ (for frontend assets)
- NPM 8+ or Yarn 1.22+

## Step-by-Step Installation

### 1. Install via Composer

Add the SwatTech CRUD package to your Laravel project:

```bash
composer require swattech/crud
```

### 2. Publish the Configuration File

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="config"
```

This will create a crud.php file in your project.

### 3. Publish Assets (Optional)

To publish the Vuexy theme assets:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="assets"
```

### 4. Publish Stubs (Optional)

If you want to customize the generated code:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="stubs"
```

This will create stub files in `resources/vendor/swattech/crud/stubs`.

### 5. Run the Installer (Optional)

The package includes an installer to help you set up everything:

```bash
php artisan crud:install
```

## Database Setup

The package works with your existing database tables. Ensure your database connection is properly configured in your `.env` file:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Important requirements:

- Tables should follow Laravel naming conventions (singular or plural)
- Foreign keys should follow the pattern `table_id` or `id_table`
- Primary keys should typically be named `id`
- For best results, include timestamps (`created_at`, `updated_at`) in your tables

## Configuration

After publishing the configuration file, you can customize the following options in crud.php:

- **Paths:** Where the generated files will be stored
- **Namespaces:** Define custom namespaces for generated classes
- **Theme:** Customize the UI theme (Vuexy by default)
- **Validation:** Configure default validation rules
- **Relationships:** Set relationship detection settings
- **Features:** Enable/disable specific features

## Verification Steps

To verify that the installation was successful:

1. Run a test generation:

```bash
php artisan crud:generate --help
```

You should see the help output for the command.

2. Generate CRUD for a test table:

```bash
php artisan crud:generate users
```

3. Check that files were generated in your `app` directory according to your configuration.

## Version Compatibility

| SwatTech CRUD | Laravel | PHP       | Status      |
|---------------|---------|-----------|-------------|
| 1.x           | 12.x    | 8.1, 8.2+ | Supported   |
| 0.x           | 11.x    | 8.1, 8.2  | Deprecated  |

## Troubleshooting Common Issues

### Class Not Found Errors

If you encounter "Class not found" errors:

```bash
composer dump-autoload
```

### Permission Issues

If files can't be created due to permissions:

```bash
chmod -R 755 app/
chmod -R 755 resources/
```

### Database Connection Issues

If the package can't connect to your database:

1. Check your `.env` file for correct database credentials
2. Ensure your database server is running
3. Try running `php artisan db:show` to verify connection

### Migration Issues

If you encounter migration errors:

```bash
php artisan migrate:status
```

Ensure all your migrations have run successfully.

### JavaScript/CSS Assets Not Found

If the theme assets are not loading:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="assets" --force
npm install
npm run dev
```

## Upgrade Guide

### Upgrading from 0.x to 1.x

1. Update your composer.json:

```bash
composer require swattech/crud:^1.0
```

2. Publish the updated configuration:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="config" --force
```

3. Publish the updated assets:

```bash
php artisan vendor:publish --provider="SwatTech\Crud\CrudServiceProvider" --tag="assets" --force
```

4. Clear caches:

```bash
php artisan optimize:clear
```

### Breaking Changes in 1.x

- Controller structure has been updated to use invokable actions
- Repository pattern implementation has changed
- Validation rules are stricter by default
- Theme components were updated for Vuexy latest version

## Uninstallation

If you need to remove the package:

1. Remove the package via Composer:

```bash
composer remove swattech/crud
```

2. Remove published configuration (optional):

```bash
rm config/crud.php
```

3. Remove published assets (optional):

```bash
rm -rf public/vendor/swattech
```

4. Remove published stubs (optional):

```bash
rm -rf resources/vendor/swattech
```

Note that the removal of the package will not affect any files already generated by the package.

## Support

If you encounter any issues not covered in this guide, please:

- Check the [documentation](https://github.com/swattech/crud/wiki)
- Open an [issue on GitHub](https://github.com/swattech/crud/issues)
- Join our [Discord community](https://discord.gg/swattech-crud) for live support

## Next Steps

Now that you've installed the package, learn how to configure it and understand relationship handling.
