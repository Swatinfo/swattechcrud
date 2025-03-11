<?php

namespace SwatTech\Crud\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Generators\ControllerGenerator;
use SwatTech\Crud\Generators\EventGenerator;
use SwatTech\Crud\Generators\FactoryGenerator;
use SwatTech\Crud\Generators\JobGenerator;
use SwatTech\Crud\Generators\ListenerGenerator;
use SwatTech\Crud\Generators\MigrationGenerator;
use SwatTech\Crud\Generators\ModelGenerator;
use SwatTech\Crud\Generators\ObserverGenerator;
use SwatTech\Crud\Generators\PolicyGenerator;
use SwatTech\Crud\Generators\RepositoryGenerator;
use SwatTech\Crud\Generators\RequestGenerator;
use SwatTech\Crud\Generators\ResourceGenerator;
use SwatTech\Crud\Generators\RouteGenerator;
use SwatTech\Crud\Generators\SeederGenerator;
use SwatTech\Crud\Generators\ServiceGenerator;
use SwatTech\Crud\Generators\ViewGenerator;

/**
 * GenerateCrudCommand
 *
 * The primary command for generating CRUD functionality for database tables.
 * This command coordinates all generators to create a complete set of files
 * for models, controllers, views, and other components.
 *
 * @package SwatTech\Crud\Commands
 */
class GenerateCrudCommand extends Command
{
    /**
     * The name and signature of the command.
     *
     * @var string
     */
    protected $signature = 'crud:generate 
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
                            {--theme= : Specify the theme for views (default: vuexy)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate CRUD files for specified database table';

    /**
     * Database analyzer instance.
     *
     * @var DatabaseAnalyzer
     */
    protected $databaseAnalyzer;

    /**
     * Relationship analyzer instance.
     *
     * @var RelationshipAnalyzer
     */
    protected $relationshipAnalyzer;

    /**
     * The generator instances.
     *
     * @var array
     */
    protected $generators = [];

    /**
     * Generated files list.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Errors encountered during generation.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Create a new command instance.
     *
     * @param DatabaseAnalyzer $databaseAnalyzer
     * @param RelationshipAnalyzer $relationshipAnalyzer
     * @param ModelGenerator $modelGenerator
     * @param ControllerGenerator $controllerGenerator
     * @param RepositoryGenerator $repositoryGenerator
     * @param ServiceGenerator $serviceGenerator
     * @param ViewGenerator $viewGenerator
     * @param RequestGenerator $requestGenerator
     * @param ResourceGenerator $resourceGenerator
     * @param RouteGenerator $routeGenerator
     * @param MigrationGenerator $migrationGenerator
     * @param FactoryGenerator $factoryGenerator
     * @param SeederGenerator $seederGenerator
     * @param PolicyGenerator $policyGenerator
     * @param ObserverGenerator $observerGenerator
     * @param EventGenerator $eventGenerator
     * @param ListenerGenerator $listenerGenerator
     * @param JobGenerator $jobGenerator
     * @return void
     */
    public function __construct(
        DatabaseAnalyzer $databaseAnalyzer,
        RelationshipAnalyzer $relationshipAnalyzer,
        ModelGenerator $modelGenerator,
        ControllerGenerator $controllerGenerator,
        RepositoryGenerator $repositoryGenerator,
        ServiceGenerator $serviceGenerator,
        ViewGenerator $viewGenerator,
        RequestGenerator $requestGenerator,
        ResourceGenerator $resourceGenerator,
        RouteGenerator $routeGenerator,
        MigrationGenerator $migrationGenerator,
        FactoryGenerator $factoryGenerator,
        SeederGenerator $seederGenerator,
        PolicyGenerator $policyGenerator,
        ObserverGenerator $observerGenerator,
        EventGenerator $eventGenerator,
        ListenerGenerator $listenerGenerator,
        JobGenerator $jobGenerator
    ) {
        parent::__construct();

        $this->databaseAnalyzer = $databaseAnalyzer;
        $this->relationshipAnalyzer = $relationshipAnalyzer;

        // Store generator instances
        $this->generators = [
            'model' => $modelGenerator,
            'controller' => $controllerGenerator,
            'repository' => $repositoryGenerator,
            'service' => $serviceGenerator,
            'view' => $viewGenerator,
            'request' => $requestGenerator,
            'resource' => $resourceGenerator,
            'route' => $routeGenerator,
            'migration' => $migrationGenerator,
            'factory' => $factoryGenerator,
            'seeder' => $seederGenerator,
            'policy' => $policyGenerator,
            'observer' => $observerGenerator,
            'event' => $eventGenerator,
            'listener' => $listenerGenerator,
            'job' => $jobGenerator
        ];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Set the database connection if specified
        $connection = $this->option('connection');
        if ($connection) {
            $this->databaseAnalyzer->setConnection($connection);
            // Also set connection for relationship analyzer
            if (method_exists($this->relationshipAnalyzer, 'setConnection')) {
                $this->relationshipAnalyzer->setConnection($connection);
            }
        }

        // Check if user wants to generate CRUD for all tables
        if ($this->option('all')) {
            return $this->processBatchGeneration(Schema::getAllTables());
        }

        // Get the specified table name
        $table = $this->getTableName();
        if (!$table) {
            $this->error('No table specified. Use the table argument or --all option.');
            return 1;
        }

        // Check if table exists
        if (!Schema::hasTable($table)) {
            $this->error("Table '{$table}' does not exist in the database.");
            return 1;
        }

        // Prepare generation options
        $options = $this->prepareOptions();

        // Check if this is a dry run
        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->implementDryRun(true);
            $this->info("Performing dry run for table '{$table}'...");
        }

        // Begin generation process
        $this->info("Generating CRUD files for table '{$table}'...");

        try {
            // Analyze table structure
            $this->info('Analyzing database structure...');
            $databaseAnalysis = $this->analyzeDatabase($table);

            // Analyze relationships
            $this->info('Analyzing relationships...');
            $relationshipAnalysis = $this->analyzeRelationships($table);

            // Merge analysis results with options
            $options = array_merge($options, [
                'database_analysis' => $databaseAnalysis,
                'relationship_analysis' => $relationshipAnalysis,
                'force' => $this->option('force')
            ]);

            // If interactive mode and not --no-interaction, allow customization
            if (!$this->option('no-interaction')) {
                $options = $this->handleCustomization($options);
            }

            // Coordinate all generators
            $this->coordinateGenerators($table, $options);

            // Show progress and any errors
            $this->showProgressAndErrors();

            $this->info('CRUD generation completed successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error during CRUD generation: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Get the table name from input or interactive selection.
     *
     * @return string|null
     */
    protected function getTableName()
    {
        // If table argument is provided, use it
        $table = $this->argument('table');
        if ($table) {
            return $table;
        }

        // If we're in no-interaction mode, return null
        if ($this->option('no-interaction')) {
            return null;
        }

        // Otherwise, provide an interactive selection
        $tables = Schema::getAllTables();

        if (empty($tables)) {
            $this->error('No tables found in the database.');
            return null;
        }

        // Format table names for display
        $tableNames = array_map(function ($table) {
            return $table->name ?? $table;
        }, $tables);

        // Allow user to choose a table
        $selectedTable = $this->choice(
            'Select a database table to generate CRUD files for:',
            $tableNames
        );

        return $selectedTable;
    }

    /**
     * Analyze database table structure.
     *
     * @param string $table The database table name
     * @return array The analysis results
     */
    protected function analyzeDatabase(string $table)
    {
        $this->databaseAnalyzer->analyze($table);
        return $this->databaseAnalyzer->getResults();
    }

    /**
     * Analyze table relationships.
     *
     * @param string $table The database table name
     * @return array The relationship analysis results
     */
    protected function analyzeRelationships(string $table)
    {
        $this->relationshipAnalyzer->analyze($table);
        return $this->relationshipAnalyzer->getResults();
    }

    /**
     * Coordinate the execution of all generators.
     *
     * @param string $table The database table name
     * @param array $options Generation options
     * @return void
     */
    protected function coordinateGenerators(string $table, array $options)
    {
        // Determine which components to generate based on options
        $componentsToGenerate = $this->getComponentsToGenerate();

        // Skip components as specified
        $componentsToSkip = $this->handleComponentSkipping($options);

        // Start a progress bar if not in quiet mode
        $progressBar = null;
        if (!$this->option('quiet')) {
            $progressBar = $this->output->createProgressBar(count($componentsToGenerate));
            $progressBar->start();
        }

        // Process each selected generator
        foreach ($componentsToGenerate as $component) {
            if (in_array($component, $componentsToSkip)) {
                $this->info("Skipping {$component} generation.");
                if ($progressBar) {
                    $progressBar->advance();
                }
                continue;
            }

            // Check if generator exists
            if (!isset($this->generators[$component])) {
                $this->errors[] = "Generator for '{$component}' not found.";
                if ($progressBar) {
                    $progressBar->advance();
                }
                continue;
            }

            try {
                // Run the generator
                $generator = $this->generators[$component];
                $files = $generator->generate($table, $options);

                // Store generated files
                if (!empty($files)) {
                    $this->generatedFiles[$component] = $files;
                }

                // Advance progress bar
                if ($progressBar) {
                    $progressBar->advance();
                }
            } catch (\Exception $e) {
                $this->errors[] = "Error generating {$component}: " . $e->getMessage();
                if ($progressBar) {
                    $progressBar->advance();
                }
            }
        }

        // Finish progress bar
        if ($progressBar) {
            $progressBar->finish();
            $this->line(''); // Add a newline after progress bar
        }
    }

    /**
     * Set up an interactive CLI interface for customization.
     *
     * @return array User selected options
     */
    protected function createInteractiveCli()
    {
        $userOptions = [];

        // Ask for theme preference if generating views
        if ($this->shouldGenerate('view')) {
            $theme = $this->option('theme') ?? 'vuexy';
            $userOptions['theme'] = $this->choice(
                'Select a theme for the views:',
                ['vuexy', 'bootstrap', 'tailwind', 'none'],
                $theme === 'vuexy' ? 0 : null
            );
        }

        // Ask for API version if generating API
        if ($this->option('with-api') || $this->shouldGenerate('resource')) {
            $userOptions['api_version'] = $this->ask('API version (leave empty for none):', 'v1');
        }

        // Ask if soft deletes should be enabled
        $userOptions['soft_deletes'] = $this->confirm('Enable soft deletes?', true);

        // Ask if timestamps should be enabled
        $userOptions['timestamps'] = $this->confirm('Enable timestamps?', true);

        // Ask if UUID should be used instead of auto-incrementing IDs
        $userOptions['use_uuid'] = $this->confirm('Use UUID instead of auto-incrementing IDs?', false);

        // Ask if factories should generate demo data
        if ($this->shouldGenerate('factory') || $this->shouldGenerate('seeder')) {
            $userOptions['demo_data'] = $this->confirm('Generate demo data in factories/seeders?', true);
        }

        // Ask if translations should be generated
        $userOptions['translations'] = $this->confirm('Generate translation files?', false);

        // Ask about repository cache
        if ($this->shouldGenerate('repository')) {
            $userOptions['cache_repository'] = $this->confirm('Enable repository caching?', true);
        }

        return $userOptions;
    }

    /**
     * Handle custom options specified by the user.
     *
     * @param array $options Current options array
     * @return array Updated options array
     */
    protected function handleCustomization(array $options)
    {
        // If no interaction is requested, return original options
        if ($this->option('no-interaction')) {
            return $options;
        }

        $this->info('Customize CRUD generation options:');

        // Get interactive options
        $interactiveOptions = $this->createInteractiveCli();

        // Merge with existing options, with interactive taking precedence
        return array_merge($options, $interactiveOptions);
    }

    /**
     * Process batch generation for multiple tables.
     *
     * @param array $tables List of tables to process
     * @return int Command exit code
     */
    protected function processBatchGeneration(array $tables)
    {
        $this->info("Batch processing " . count($tables) . " tables...");

        // Confirm before proceeding with batch generation
        if (!$this->option('no-interaction')) {
            if (!$this->confirm("This will generate CRUD files for " . count($tables) . " tables. Proceed?", true)) {
                $this->info("Operation cancelled by user.");
                return 0;
            }
        }

        // Prepare options
        $options = $this->prepareOptions();
        $options['force'] = $this->option('force');

        // Initialize counters
        $successCount = 0;
        $errorCount = 0;

        // Process each table
        $progressBar = $this->output->createProgressBar(count($tables));
        $progressBar->start();

        foreach ($tables as $table) {
            $tableName = is_object($table) ? $table->name : $table;

            // Skip migration/system tables
            if (in_array($tableName, ['migrations', 'failed_jobs', 'password_resets', 'personal_access_tokens'])) {
                $progressBar->advance();
                continue;
            }

            try {
                // Analyze table structure and relationships
                $databaseAnalysis = $this->analyzeDatabase($tableName);
                $relationshipAnalysis = $this->analyzeRelationships($tableName);

                // Merge analysis results with options
                $tableOptions = array_merge($options, [
                    'database_analysis' => $databaseAnalysis,
                    'relationship_analysis' => $relationshipAnalysis,
                ]);

                // Generate CRUD components
                $this->coordinateGenerators($tableName, $tableOptions);
                $successCount++;
            } catch (\Exception $e) {
                $this->errors[] = "Error processing table {$tableName}: " . $e->getMessage();
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line(''); // Add newline after progress bar

        // Show summary
        $this->info("Batch generation completed: {$successCount} tables processed successfully, {$errorCount} errors.");

        // Show errors if any
        if (!empty($this->errors)) {
            $this->showProgressAndErrors();
        }

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Handle component skipping based on options.
     *
     * @param array $options The options array
     * @return array List of components to skip
     */
    protected function handleComponentSkipping(array $options)
    {
        $skipComponents = [];

        // If specific components are requested, skip everything else
        $specificComponents = $this->getComponentsToGenerate();

        if (!empty($specificComponents) && count($specificComponents) < count($this->generators)) {
            $skipComponents = array_keys($this->generators);
            $skipComponents = array_diff($skipComponents, $specificComponents);
        }

        // Check for explicit skip options in $options
        foreach ($this->generators as $component => $generator) {
            if (isset($options["skip_{$component}"]) && $options["skip_{$component}"]) {
                $skipComponents[] = $component;
            }
        }

        return array_unique($skipComponents);
    }

    /**
     * Show progress and report any errors that occurred.
     *
     * @return void
     */
    protected function showProgressAndErrors()
    {
        // Show a summary of generated files
        $this->info("\nGenerated files:");

        foreach ($this->generatedFiles as $component => $files) {
            $this->line("\n<comment>{$component}:</comment>");
            foreach ($files as $file) {
                $this->line("  - " . $file);
            }
        }

        // Show any errors that occurred
        if (!empty($this->errors)) {
            $this->error("\nErrors encountered:");
            foreach ($this->errors as $error) {
                $this->line("  - " . $error);
            }
        }
    }

    /**
     * Implement dry run mode (no files are written).
     *
     * @param bool $dryRun Whether to enable dry run mode
     * @return void
     */
    protected function implementDryRun(bool $dryRun)
    {
        if (!$dryRun) {
            return;
        }

        $this->warn('DRY RUN MODE - No files will be written');

        // Modify each generator to preview instead of writing files
        foreach ($this->generators as $component => $generator) {
            if (method_exists($generator, 'enableDryRun')) {
                $generator->enableDryRun();
            }
        }
    }

    /**
     * Prepare options array based on command options.
     *
     * @return array Options array
     */
    protected function prepareOptions()
    {
        $options = [];

        // Add theme option
        $options['theme'] = $this->option('theme') ?? 'vuexy';

        // Add API option
        $options['with-api'] = $this->option('with-api');

        // Add custom path if provided
        if ($this->option('path')) {
            $options['custom_path'] = $this->option('path');
        }

        // Add custom namespace if provided
        if ($this->option('namespace')) {
            $options['custom_namespace'] = $this->option('namespace');
        }

        return $options;
    }

    /**
     * Determine which components to generate based on command options.
     *
     * @return array Components to generate
     */
    protected function getComponentsToGenerate()
    {
        $components = [];

        // Check each component-specific option
        foreach (array_keys($this->generators) as $component) {
            if ($this->option($component)) {
                $components[] = $component;
            }
        }

        // If API option is specified, include API-specific components
        if ($this->option('with-api')) {
            $components = array_merge($components, ['controller', 'resource', 'request', 'model', 'route']);
        }

        // If no specific components are selected, generate all
        if (empty($components)) {
            $components = array_keys($this->generators);
        }

        return $components;
    }

    /**
     * Check if a specific component should be generated.
     *
     * @param string $component The component type
     * @return bool True if the component should be generated
     */
    protected function shouldGenerate(string $component)
    {
        $componentsToGenerate = $this->getComponentsToGenerate();
        return in_array($component, $componentsToGenerate);
    }
}
