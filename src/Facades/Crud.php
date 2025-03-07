<?php

namespace SwatTech\Crud\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for SwatTech CRUD functionality
 *
 * This facade provides easy access to the CRUD generator functionality,
 * analyzers, and other utilities provided by the SwatTech CRUD package.
 *
 * @method static array generate(string $table, array $options = []) Generate CRUD files for a table
 * @method static array generateApi(string $table, array $options = []) Generate API CRUD files
 * @method static array generateModel(string $table, array $options = []) Generate model only
 * @method static array generateController(string $table, array $options = []) Generate controller only
 * @method static array generateRepository(string $table, array $options = []) Generate repository only
 * @method static array generateService(string $table, array $options = []) Generate service only
 * @method static array generateViews(string $table, array $options = []) Generate views only
 * @method static array generateRelationships(string $table, array $options = []) Generate relationship methods
 * @method static array generateFactory(string $table, array $options = []) Generate factory only
 * @method static array generateSeeder(string $table, array $options = []) Generate seeder only
 * @method static array generatePolicy(string $table, array $options = []) Generate policy only
 * @method static array generateMigration(string $table, array $options = []) Generate migration only
 * @method static array generateTests(string $table, array $options = []) Generate tests only
 * @method static array analyzeDatabase(string $table, string $connection = null) Analyze database structure
 * @method static array analyzeRelationships(string $table, string $connection = null) Analyze relationships
 * @method static self withConfig(array $config) Set custom configuration for next operation
 * @method static self withTheme(string $theme) Set theme for next operation
 * @method static self withConnection(string $connection) Set database connection for next operation
 * @method static self withNamespace(string $namespace) Set custom namespace for next operation
 * @method static self withPath(string $path) Set custom path for next operation
 * @method static self enableDryRun() Enable dry run mode (no files written)
 * @method static self disableDryRun() Disable dry run mode
 * @method static bool hasGenerator(string $type) Check if a generator type is available
 * @method static bool hasAnalyzer(string $type) Check if an analyzer type is available
 * @method static mixed getGeneratorInstance(string $type) Get a generator instance
 * @method static mixed getAnalyzerInstance(string $type) Get an analyzer instance
 * @method static array getAvailableTables(string $connection = null) Get available database tables
 * @method static array getTableColumns(string $table, string $connection = null) Get table columns
 * @method static self mock() Get a mock instance for testing
 * 
 * @see \SwatTech\Crud\Services\CrudManagerService
 */
class Crud extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'swattech.crud';
    }

    /**
     * Replace the bound instance with a mock for testing.
     *
     * @param array $methods Methods to mock
     * @return \Mockery\MockInterface
     */
    public static function mock(array $methods = [])
    {
        $mock = \Mockery::mock(static::getFacadeAccessor(), $methods);
        static::swap($mock);
        return $mock;
    }

    /**
     * Quick method to generate both web and API CRUD for a model.
     *
     * @param string $table The table name
     * @param array $options Additional options
     * @return array Generated files
     */
    public static function generateFull(string $table, array $options = [])
    {
        $files = static::generate($table, $options);
        $apiFiles = static::generateApi($table, $options);
        
        return array_merge($files, $apiFiles);
    }

    /**
     * Generate CRUD files for all tables in the database.
     *
     * @param array $options Additional options
     * @param array $exclude Tables to exclude
     * @return array Generated files
     */
    public static function generateAll(array $options = [], array $exclude = ['migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'])
    {
        $tables = static::getAvailableTables();
        $generatedFiles = [];
        
        foreach ($tables as $table) {
            if (!in_array($table, $exclude)) {
                $files = static::generate($table, $options);
                $generatedFiles[$table] = $files;
            }
        }
        
        return $generatedFiles;
    }

    /**
     * Analyze and generate relationship methods for all models.
     *
     * @param array $options Additional options
     * @param array $exclude Tables to exclude
     * @return array Generated files
     */
    public static function analyzeAllRelationships(array $options = [], array $exclude = ['migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'])
    {
        $tables = static::getAvailableTables();
        $relationships = [];
        
        foreach ($tables as $table) {
            if (!in_array($table, $exclude)) {
                $relationships[$table] = static::analyzeRelationships($table);
            }
        }
        
        return $relationships;
    }

    /**
     * Reset to default configuration.
     *
     * @return self
     */
    public static function resetConfig()
    {
        return static::withConfig([]);
    }
}