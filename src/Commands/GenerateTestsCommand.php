<?php

namespace SwatTech\Crud\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Generators\ModelGenerator;
use SwatTech\Crud\Generators\ControllerGenerator;
use SwatTech\Crud\Generators\RepositoryGenerator;
use SwatTech\Crud\Generators\ServiceGenerator;
use SwatTech\Crud\Generators\FactoryGenerator;
use SwatTech\Crud\Generators\RequestGenerator;

/**
 * GenerateTestsCommand
 *
 * This command generates test suites for CRUD components including unit tests,
 * feature tests, and browser tests. It can create tests for models, controllers,
 * repositories, services, and API endpoints.
 *
 * @package SwatTech\Crud\Commands
 */
class GenerateTestsCommand extends Command
{
    /**
     * The name and signature of the command.
     *
     * @var string
     */
    protected $signature = 'crud:tests
                            {table? : The name of the database table}
                            {--all : Generate tests for all tables}
                            {--unit : Generate only unit tests}
                            {--feature : Generate only feature tests}
                            {--browser : Generate only browser tests}
                            {--api : Generate only API tests}
                            {--model : Generate only model tests}
                            {--controller : Generate only controller tests}
                            {--repository : Generate only repository tests}
                            {--service : Generate only service tests}
                            {--validation : Generate only validation tests}
                            {--force : Overwrite existing test files}
                            {--connection= : Database connection to use}
                            {--no-interaction : Do not ask any interactive questions}
                            {--namespace= : Custom namespace for tests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test suites for CRUD components';

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
     * Model generator instance.
     *
     * @var ModelGenerator
     */
    protected $modelGenerator;

    /**
     * Controller generator instance.
     *
     * @var ControllerGenerator
     */
    protected $controllerGenerator;

    /**
     * Repository generator instance.
     *
     * @var RepositoryGenerator
     */
    protected $repositoryGenerator;

    /**
     * Service generator instance.
     *
     * @var ServiceGenerator
     */
    protected $serviceGenerator;

    /**
     * Factory generator instance.
     *
     * @var FactoryGenerator
     */
    protected $factoryGenerator;

    /**
     * Request generator instance.
     *
     * @var RequestGenerator
     */
    protected $requestGenerator;

    /**
     * Test configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Generated files list.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Create a new command instance.
     *
     * @param DatabaseAnalyzer $databaseAnalyzer
     * @param RelationshipAnalyzer $relationshipAnalyzer
     * @param ModelGenerator $modelGenerator
     * @param ControllerGenerator $controllerGenerator
     * @param RepositoryGenerator $repositoryGenerator
     * @param ServiceGenerator $serviceGenerator
     * @param FactoryGenerator $factoryGenerator
     * @param RequestGenerator $requestGenerator
     * @return void
     */
    public function __construct(
        DatabaseAnalyzer $databaseAnalyzer,
        RelationshipAnalyzer $relationshipAnalyzer,
        ModelGenerator $modelGenerator,
        ControllerGenerator $controllerGenerator,
        RepositoryGenerator $repositoryGenerator,
        ServiceGenerator $serviceGenerator,
        FactoryGenerator $factoryGenerator,
        RequestGenerator $requestGenerator
    ) {
        parent::__construct();

        $this->databaseAnalyzer = $databaseAnalyzer;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->modelGenerator = $modelGenerator;
        $this->controllerGenerator = $controllerGenerator;
        $this->repositoryGenerator = $repositoryGenerator;
        $this->serviceGenerator = $serviceGenerator;
        $this->factoryGenerator = $factoryGenerator;
        $this->requestGenerator = $requestGenerator;
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

        // Prepare options
        $this->options = [
            'force' => $this->option('force'),
            'namespace' => $this->option('namespace'),
            'unit_tests' => $this->option('unit') || (!$this->option('feature') && !$this->option('browser') && !$this->option('api')),
            'feature_tests' => $this->option('feature') || (!$this->option('unit') && !$this->option('browser') && !$this->option('api')),
            'browser_tests' => $this->option('browser'),
            'api_tests' => $this->option('api'),
            'model_tests' => $this->option('model') || (!$this->hasSpecificComponentOption()),
            'controller_tests' => $this->option('controller') || (!$this->hasSpecificComponentOption()),
            'repository_tests' => $this->option('repository') || (!$this->hasSpecificComponentOption()),
            'service_tests' => $this->option('service') || (!$this->hasSpecificComponentOption()),
            'validation_tests' => $this->option('validation') || (!$this->hasSpecificComponentOption()),
        ];

        // Process all tables if --all option is used
        if ($this->option('all')) {
            return $this->processAllTables();
        }

        // Get the specified table name or ask for it
        $table = $this->argument('table');
        if (!$table) {
            if ($this->option('no-interaction')) {
                $this->error('No table specified. Use the table argument or --all option.');
                return 1;
            }

            $table = $this->askForTable();
            if (!$table) {
                return 1;
            }
        }

        // Check if the table exists
        if (!Schema::hasTable($table)) {
            $this->error("Table '{$table}' does not exist in the database.");
            return 1;
        }

        // Begin test generation process
        $this->info("Generating test files for table '{$table}'...");

        try {
            // Analyze table structure
            $this->info('Analyzing database structure...');
            $databaseAnalysis = $this->databaseAnalyzer->analyze($table);

            // Analyze relationships
            $this->info('Analyzing relationships...');
            $this->relationshipAnalyzer->analyze($table);
            $relationships = $this->relationshipAnalyzer->getResults();

            // Create factory classes if needed
            $this->createFactoryClasses($table, $databaseAnalysis);

            // Generate tests based on options
            $this->generateTests($table, $databaseAnalysis, $relationships);

            // Show summary
            $this->showSummary();

            return 0;
        } catch (\Exception $e) {
            $this->error("Error generating tests: {$e->getMessage()}");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Check if any specific component test options were specified.
     *
     * @return bool
     */
    protected function hasSpecificComponentOption(): bool
    {
        return $this->option('model') || $this->option('controller') ||
            $this->option('repository') || $this->option('service') ||
            $this->option('validation');
    }

    /**
     * Ask user to select a table from the database.
     *
     * @return string|null
     */
    protected function askForTable()
    {
        $tables = Schema::getAllTables();

        if (empty($tables)) {
            $this->error('No tables found in the database.');
            return null;
        }

        // Format table names for display
        $tableNames = array_map(function ($table) {
            return $table->name ?? $table;
        }, $tables);

        // Filter out system tables
        $tableNames = array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations', 'failed_jobs', 'password_resets', 'personal_access_tokens']);
        });

        // Allow user to choose a table
        return $this->choice(
            'Select a database table to generate tests for:',
            $tableNames
        );
    }

    /**
     * Process test generation for all tables.
     *
     * @return int
     */
    protected function processAllTables()
    {
        $tables = Schema::getAllTables();

        // Format table names
        $tableNames = array_map(function ($table) {
            return $table->name ?? $table;
        }, $tables);

        // Filter out system tables
        $tableNames = array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations', 'failed_jobs', 'password_resets', 'personal_access_tokens']);
        });

        if (empty($tableNames)) {
            $this->error('No tables found in the database.');
            return 1;
        }

        // Confirm before proceeding
        if (!$this->option('no-interaction')) {
            if (!$this->confirm("This will generate tests for " . count($tableNames) . " tables. Proceed?", true)) {
                $this->info("Operation cancelled by user.");
                return 0;
            }
        }

        $successCount = 0;
        $errorCount = 0;

        // Process each table
        $progressBar = $this->output->createProgressBar(count($tableNames));
        $progressBar->start();

        foreach ($tableNames as $table) {
            try {
                // Analyze table structure
                $databaseAnalysis = $this->databaseAnalyzer->analyze($table);

                // Analyze relationships
                $this->relationshipAnalyzer->analyze($table);
                $relationships = $this->relationshipAnalyzer->getResults();

                // Create factory classes if needed
                $this->createFactoryClasses($table, $databaseAnalysis);

                // Generate tests
                $this->generateTests($table, $databaseAnalysis, $relationships);

                $successCount++;
            } catch (\Exception $e) {
                $this->error("Error processing table {$table}: {$e->getMessage()}");
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line(''); // Add newline after progress bar

        // Show summary
        $this->info("Batch processing completed: {$successCount} tables processed successfully, {$errorCount} errors.");
        $this->showSummary();

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Generate tests based on options.
     *
     * @param string $table Table name
     * @param array $databaseAnalysis Database analysis results
     * @param array $relationships Relationship analysis results
     * @return void
     */
    protected function generateTests(string $table, array $databaseAnalysis, array $relationships)
    {
        // Model tests
        if ($this->options['model_tests']) {
            $this->generateModelTests($table, $databaseAnalysis, $relationships);
        }

        // Repository tests
        if ($this->options['repository_tests']) {
            $this->generateRepositoryTests($table, $databaseAnalysis);
        }

        // Service tests
        if ($this->options['service_tests']) {
            $this->generateServiceTests($table, $databaseAnalysis);
        }

        // Controller tests
        if ($this->options['controller_tests']) {
            $this->generateControllerTests($table, $databaseAnalysis, $relationships);
        }

        // Validation tests
        if ($this->options['validation_tests']) {
            $this->createValidationRuleTests($table, $databaseAnalysis);
        }

        // Browser tests
        if ($this->options['browser_tests']) {
            $this->setupBrowserTests($table, $databaseAnalysis);
        }

        // API tests
        if ($this->options['api_tests']) {
            $this->setupApiTesting($table, $databaseAnalysis);
        }
    }

    /**
     * Generate model test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @param array $relationships Relationship analysis results
     * @return array Generated files
     */
    protected function generateModelTests(string $table, array $databaseAnalysis, array $relationships)
    {
        $this->info("Generating model tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $testClass = "{$modelClass}Test";
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Unit\\Models';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing test:</comment> {$testClass}");
            return [];
        }

        // Get model columns and relationships for tests
        $columns = $databaseAnalysis['columns'] ?? [];
        $modelRelationships = $relationships['relationships'] ?? [];

        // Generate test content
        $content = $this->buildModelTestContent(
            $table,
            $modelClass,
            $testClass,
            $modelNamespace,
            $testNamespace,
            $columns,
            $modelRelationships
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['model_tests'][] = $testPath;

        $this->line("  <info>Generated model test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build model test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $testClass Test class name
     * @param string $modelNamespace Model namespace
     * @param string $testNamespace Test namespace
     * @param array $columns Database columns
     * @param array $relationships Model relationships
     * @return string Generated test content
     */
    protected function buildModelTestContent(
        string $table,
        string $modelClass,
        string $testClass,
        string $modelNamespace,
        string $testNamespace,
        array $columns,
        array $relationships
    ): string {
        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        // Additional setup\n";
        $content .= "    }\n\n";

        // Factory creation test
        $content .= "    /**\n";
        $content .= "     * Test model factory creates valid instance.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_factory_creates_valid_model()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n";
        $content .= "        \$this->assertInstanceOf({$modelClass}::class, \$model);\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test fillable attributes
        $content .= "    /**\n";
        $content .= "     * Test model fillable attributes.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_fillable_attributes()\n";
        $content .= "    {\n";
        $content .= "        \$model = new {$modelClass}();\n";
        $content .= "        \$fillable = \$model->getFillable();\n";
        $content .= "        \$this->assertIsArray(\$fillable);\n";
        $content .= "    }\n\n";

        // Add relationship tests
        if (!empty($relationships)) {
            $content .= "    /**\n";
            $content .= "     * Test model relationships.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_model_relationships()\n";
            $content .= "    {\n";
            $content .= "        \$model = new {$modelClass}();\n\n";

            foreach ($relationships as $relationship) {
                $method = $relationship['method'] ?? '';
                $type = $relationship['type'] ?? '';

                if ($method && $type) {
                    $relationshipClass = $this->getRelationshipClass($type);
                    if ($relationshipClass) {
                        $content .= "        // Test {$method} relationship\n";
                        $content .= "        \$this->assertInstanceOf({$relationshipClass}::class, \$model->{$method}());\n\n";
                    }
                }
            }

            $content .= "    }\n\n";
        }

        // Add test for timestamps if applicable
        if (in_array('created_at', array_keys($columns)) && in_array('updated_at', array_keys($columns))) {
            $content .= "    /**\n";
            $content .= "     * Test model uses timestamps.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_model_uses_timestamps()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n";
            $content .= "        \$this->assertNotNull(\$model->created_at);\n";
            $content .= "        \$this->assertNotNull(\$model->updated_at);\n";
            $content .= "    }\n\n";
        }

        // Test accessors/mutators if specified in options
        $content .= "    /**\n";
        $content .= "     * Test model attribute casting.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_model_casts()\n";
        $content .= "    {\n";
        $content .= "        \$model = new {$modelClass}();\n";
        $content .= "        \$casts = \$model->getCasts();\n";
        $content .= "        \$this->assertIsArray(\$casts);\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Get the Eloquent relationship class name based on relationship type.
     *
     * @param string $type Relationship type
     * @return string|null Fully qualified class name
     */
    protected function getRelationshipClass(string $type): ?string
    {
        $map = [
            'hasOne' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOne',
            'hasMany' => 'Illuminate\\Database\\Eloquent\\Relations\\HasMany',
            'belongsTo' => 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
            'belongsToMany' => 'Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany',
            'morphTo' => 'Illuminate\\Database\\Eloquent\\Relations\\MorphTo',
            'morphOne' => 'Illuminate\\Database\\Eloquent\\Relations\\MorphOne',
            'morphMany' => 'Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
            'morphToMany' => 'Illuminate\\Database\\Eloquent\\Relations\\MorphToMany',
            'morphedByMany' => 'Illuminate\\Database\\Eloquent\\Relations\\MorphToMany',
        ];

        return $map[$type] ?? null;
    }

    /**
     * Generate service test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function generateServiceTests(string $table, array $databaseAnalysis)
    {
        $this->info("Generating service tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $serviceClass = "{$modelClass}Service";
        $testClass = "{$serviceClass}Test";

        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $serviceNamespace = Config::get('crud.namespaces.services', 'App\\Services');
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Unit\\Services';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildServiceTestContent(
            $table,
            $modelClass,
            $serviceClass,
            $testClass,
            $modelNamespace,
            $serviceNamespace,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['service_tests'][] = $testPath;

        $this->line("  <info>Generated service test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build service test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $serviceClass Service class name
     * @param string $testClass Test class name
     * @param string $modelNamespace Model namespace
     * @param string $serviceNamespace Service namespace
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildServiceTestContent(
        string $table,
        string $modelClass,
        string $serviceClass,
        string $testClass,
        string $modelNamespace,
        string $serviceNamespace,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use {$serviceNamespace}\\{$serviceClass};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n";
        $content .= "use Mockery;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n\n";

        $content .= "    /**\n";
        $content .= "     * @var {$serviceClass}\n";
        $content .= "     */\n";
        $content .= "    protected \$service;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        \$this->service = \$this->app->make({$serviceClass}::class);\n";
        $content .= "    }\n\n";

        // Test all method
        $content .= "    /**\n";
        $content .= "     * Test retrieving all records.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_all_method_returns_collection()\n";
        $content .= "    {\n";
        $content .= "        // Create test records\n";
        $content .= "        {$modelClass}::factory()->count(3)->create();\n\n";
        $content .= "        \$result = \$this->service->all();\n\n";
        $content .= "        \$this->assertCount(3, \$result);\n";
        $content .= "        \$this->assertInstanceOf('Illuminate\\Database\\Eloquent\\Collection', \$result);\n";
        $content .= "    }\n\n";

        // Test find method
        $content .= "    /**\n";
        $content .= "     * Test finding a record by ID.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_find_method_returns_model()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$result = \$this->service->find(\$model->id);\n\n";
        $content .= "        \$this->assertInstanceOf({$modelClass}::class, \$result);\n";
        $content .= "        \$this->assertEquals(\$model->id, \$result->id);\n";
        $content .= "    }\n\n";

        // Test create method
        $content .= "    /**\n";
        $content .= "     * Test creating a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_create_method_stores_record()\n";
        $content .= "    {\n";
        $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
        $content .= "        \$model = \$this->service->create(\$data);\n\n";
        $content .= "        \$this->assertInstanceOf({$modelClass}::class, \$model);\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test update method
        $content .= "    /**\n";
        $content .= "     * Test updating a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_update_method_updates_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n";
        $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
        $content .= "        \$updated = \$this->service->update(\$model->id, \$data);\n\n";
        $content .= "        \$this->assertEquals(\$model->id, \$updated->id);\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test delete method
        $content .= "    /**\n";
        $content .= "     * Test deleting a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_delete_method_removes_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$result = \$this->service->delete(\$model->id);\n\n";
        $content .= "        \$this->assertTrue(\$result);\n";
        $content .= "        \$this->assertDatabaseMissing('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test pagination
        $content .= "    /**\n";
        $content .= "     * Test paginated results.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_paginate_method_returns_paginator()\n";
        $content .= "    {\n";
        $content .= "        // Create test records\n";
        $content .= "        {$modelClass}::factory()->count(15)->create();\n\n";
        $content .= "        \$result = \$this->service->paginate(1, 10);\n\n";
        $content .= "        \$this->assertInstanceOf('Illuminate\\Pagination\\LengthAwarePaginator', \$result);\n";
        $content .= "        \$this->assertEquals(10, count(\$result->items()));\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Generate repository test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function generateRepositoryTests(string $table, array $databaseAnalysis)
    {
        $this->info("Generating repository tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $repositoryClass = "{$modelClass}Repository";
        $testClass = "{$repositoryClass}Test";

        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $repositoryNamespace = Config::get('crud.namespaces.repositories', 'App\\Repositories');
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Unit\\Repositories';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildRepositoryTestContent(
            $table,
            $modelClass,
            $repositoryClass,
            $testClass,
            $modelNamespace,
            $repositoryNamespace,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['repository_tests'][] = $testPath;

        $this->line("  <info>Generated repository test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build repository test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $repositoryClass Repository class name
     * @param string $testClass Test class name
     * @param string $modelNamespace Model namespace
     * @param string $repositoryNamespace Repository namespace
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildRepositoryTestContent(
        string $table,
        string $modelClass,
        string $repositoryClass,
        string $testClass,
        string $modelNamespace,
        string $repositoryNamespace,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use {$repositoryNamespace}\\{$repositoryClass};\n";
        $content .= "use {$repositoryNamespace}\\Interfaces\\{$repositoryClass}Interface;\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n";
        $content .= "use Mockery;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n\n";

        $content .= "    /**\n";
        $content .= "     * @var {$repositoryClass}\n";
        $content .= "     */\n";
        $content .= "    protected \$repository;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        \$this->repository = \$this->app->make({$repositoryClass}::class);\n";
        $content .= "    }\n\n";

        // Test repository instance
        $content .= "    /**\n";
        $content .= "     * Test repository is properly instantiated.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_repository_instance()\n";
        $content .= "    {\n";
        $content .= "        \$this->assertInstanceOf({$repositoryClass}::class, \$this->repository);\n";
        $content .= "        \$this->assertInstanceOf({$repositoryClass}Interface::class, \$this->repository);\n";
        $content .= "    }\n\n";

        // Test all method
        $content .= "    /**\n";
        $content .= "     * Test retrieving all records.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_all_method_returns_collection()\n";
        $content .= "    {\n";
        $content .= "        // Create test records\n";
        $content .= "        {$modelClass}::factory()->count(3)->create();\n\n";
        $content .= "        \$result = \$this->repository->all();\n\n";
        $content .= "        \$this->assertCount(3, \$result);\n";
        $content .= "        \$this->assertInstanceOf('Illuminate\\Database\\Eloquent\\Collection', \$result);\n";
        $content .= "    }\n\n";

        // Test find method
        $content .= "    /**\n";
        $content .= "     * Test finding a record by ID.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_find_method_returns_model()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$result = \$this->repository->find(\$model->id);\n\n";
        $content .= "        \$this->assertInstanceOf({$modelClass}::class, \$result);\n";
        $content .= "        \$this->assertEquals(\$model->id, \$result->id);\n";
        $content .= "    }\n\n";

        // Test create method
        $content .= "    /**\n";
        $content .= "     * Test creating a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_create_method_stores_record()\n";
        $content .= "    {\n";
        $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
        $content .= "        \$model = \$this->repository->create(\$data);\n\n";
        $content .= "        \$this->assertInstanceOf({$modelClass}::class, \$model);\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test update method
        $content .= "    /**\n";
        $content .= "     * Test updating a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_update_method_updates_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n";
        $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
        $content .= "        \$updated = \$this->repository->update(\$model->id, \$data);\n\n";
        $content .= "        \$this->assertEquals(\$model->id, \$updated->id);\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test delete method
        $content .= "    /**\n";
        $content .= "     * Test deleting a record.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_delete_method_removes_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$result = \$this->repository->delete(\$model->id);\n\n";
        $content .= "        \$this->assertTrue(\$result);\n";
        $content .= "        \$this->assertDatabaseMissing('{$table}', ['id' => \$model->id]);\n";
        $content .= "    }\n\n";

        // Test filter method
        $content .= "    /**\n";
        $content .= "     * Test filtering records.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_filtering_records()\n";
        $content .= "    {\n";
        $content .= "        // This test depends on the implementation of your filter method\n";
        $content .= "        // Create test records\n";
        $content .= "        {$modelClass}::factory()->count(5)->create();\n\n";
        $content .= "        // Example of filtering - adjust according to your implementation\n";
        $content .= "        \$filters = ['created_at' => ['operator' => '>', 'value' => now()->subDays(1)]];\n";
        $content .= "        \$result = \$this->repository->all(\$filters);\n\n";
        $content .= "        \$this->assertNotNull(\$result);\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Generate controller test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @param array $relationships Relationship analysis results
     * @return array Generated files
     */
    protected function generateControllerTests(string $table, array $databaseAnalysis, array $relationships)
    {
        $this->info("Generating controller tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $controllerClass = "{$modelClass}Controller";
        $testClass = "{$controllerClass}Test";

        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $controllerNamespace = Config::get('crud.namespaces.controllers', 'App\\Http\\Controllers');

        // Determine if we're generating feature or unit tests
        if ($this->options['feature_tests']) {
            $testNamespace = $this->options['namespace'] ?? 'Tests\\Feature\\Controllers';
            $baseTestClass = 'Tests\\TestCase';
        } else {
            $testNamespace = $this->options['namespace'] ?? 'Tests\\Unit\\Controllers';
            $baseTestClass = 'Tests\\TestCase';
        }

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildControllerTestContent(
            $table,
            $modelClass,
            $controllerClass,
            $testClass,
            $modelNamespace,
            $controllerNamespace,
            $testNamespace,
            $baseTestClass,
            $databaseAnalysis,
            $relationships,
            $this->options['feature_tests']
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['controller_tests'][] = $testPath;

        $this->line("  <info>Generated controller test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build controller test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $controllerClass Controller class name
     * @param string $testClass Test class name
     * @param string $modelNamespace Model namespace
     * @param string $controllerNamespace Controller namespace
     * @param string $testNamespace Test namespace
     * @param string $baseTestClass Base test class
     * @param array $databaseAnalysis Database analysis
     * @param array $relationships Relationship analysis
     * @param bool $isFeatureTest Whether to generate feature test
     * @return string Generated test content
     */
    protected function buildControllerTestContent(
        string $table,
        string $modelClass,
        string $controllerClass,
        string $testClass,
        string $modelNamespace,
        string $controllerNamespace,
        string $testNamespace,
        string $baseTestClass,
        array $databaseAnalysis,
        array $relationships,
        bool $isFeatureTest = true
    ): string {
        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use {$controllerNamespace}\\{$controllerClass};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";

        if ($isFeatureTest) {
            $content .= "use Illuminate\\Foundation\\Testing\\WithFaker;\n";
        } else {
            $content .= "use Mockery;\n";
            $content .= "use Illuminate\\Http\\Request;\n";
        }

        $content .= "use {$baseTestClass};\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n";

        if ($isFeatureTest) {
            $content .= "    use WithFaker;\n";
        }

        $content .= "\n";

        // Add properties for unit tests
        if (!$isFeatureTest) {
            $content .= "    /**\n";
            $content .= "     * @var {$controllerClass}\n";
            $content .= "     */\n";
            $content .= "    protected \$controller;\n\n";
        }

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";

        if (!$isFeatureTest) {
            $content .= "        \$this->controller = \$this->app->make({$controllerClass}::class);\n";
        } else {
            $content .= "        // Setup for feature tests\n";
            $content .= "        \$this->withoutExceptionHandling();\n";
        }

        $content .= "    }\n\n";

        if ($isFeatureTest) {
            // Feature test - index method
            $content .= "    /**\n";
            $content .= "     * Test index method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_index_displays_all_records()\n";
            $content .= "    {\n";
            $content .= "        // Create test records\n";
            $content .= "        {$modelClass}::factory()->count(3)->create();\n\n";
            $content .= "        \$response = \$this->get(route('" . Str::plural(Str::snake($modelClass)) . ".index'));\n\n";
            $content .= "        \$response->assertStatus(200);\n";
            $content .= "        \$response->assertViewIs('" . Str::plural(Str::snake($modelClass)) . ".index');\n";
            $content .= "    }\n\n";

            // Feature test - show method
            $content .= "    /**\n";
            $content .= "     * Test show method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_show_displays_record()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
            $content .= "        \$response = \$this->get(route('" . Str::plural(Str::snake($modelClass)) . ".show', \$model->id));\n\n";
            $content .= "        \$response->assertStatus(200);\n";
            $content .= "        \$response->assertViewIs('" . Str::plural(Str::snake($modelClass)) . ".show');\n";
            $content .= "    }\n\n";

            // Feature test - create method
            $content .= "    /**\n";
            $content .= "     * Test create method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_create_displays_form()\n";
            $content .= "    {\n";
            $content .= "        \$response = \$this->get(route('" . Str::plural(Str::snake($modelClass)) . ".create'));\n\n";
            $content .= "        \$response->assertStatus(200);\n";
            $content .= "        \$response->assertViewIs('" . Str::plural(Str::snake($modelClass)) . ".create');\n";
            $content .= "    }\n\n";

            // Feature test - store method
            $content .= "    /**\n";
            $content .= "     * Test store method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_store_creates_record()\n";
            $content .= "    {\n";
            $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
            $content .= "        \$response = \$this->post(route('" . Str::plural(Str::snake($modelClass)) . ".store'), \$data);\n\n";
            $content .= "        \$response->assertRedirect();\n";
            $content .= "        \$this->assertDatabaseHas('{$table}', [\n";
            $content .= "            // Add key fields to check\n";
            if (isset($databaseAnalysis['columns']) && count($databaseAnalysis['columns']) > 0) {
                foreach (array_slice($databaseAnalysis['columns'], 0, 2) as $column => $details) {
                    if ($column !== 'id' && $column !== 'created_at' && $column !== 'updated_at') {
                        $content .= "            '{$column}' => \$data['{$column}'],\n";
                    }
                }
            }
            $content .= "        ]);\n";
            $content .= "    }\n\n";

            // Feature test - edit method
            $content .= "    /**\n";
            $content .= "     * Test edit method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_edit_displays_form()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
            $content .= "        \$response = \$this->get(route('" . Str::plural(Str::snake($modelClass)) . ".edit', \$model->id));\n\n";
            $content .= "        \$response->assertStatus(200);\n";
            $content .= "        \$response->assertViewIs('" . Str::plural(Str::snake($modelClass)) . ".edit');\n";
            $content .= "    }\n\n";

            // Feature test - update method
            $content .= "    /**\n";
            $content .= "     * Test update method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_update_updates_record()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n";
            $content .= "        \$data = {$modelClass}::factory()->make()->toArray();\n\n";
            $content .= "        \$response = \$this->put(route('" . Str::plural(Str::snake($modelClass)) . ".update', \$model->id), \$data);\n\n";
            $content .= "        \$response->assertRedirect();\n";
            $content .= "        \$this->assertDatabaseHas('{$table}', [\n";
            $content .= "            'id' => \$model->id,\n";
            if (isset($databaseAnalysis['columns']) && count($databaseAnalysis['columns']) > 0) {
                foreach (array_slice($databaseAnalysis['columns'], 0, 2) as $column => $details) {
                    if ($column !== 'id' && $column !== 'created_at' && $column !== 'updated_at') {
                        $content .= "            '{$column}' => \$data['{$column}'],\n";
                    }
                }
            }
            $content .= "        ]);\n";
            $content .= "    }\n\n";

            // Feature test - destroy method
            $content .= "    /**\n";
            $content .= "     * Test destroy method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_destroy_deletes_record()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
            $content .= "        \$response = \$this->delete(route('" . Str::plural(Str::snake($modelClass)) . ".destroy', \$model->id));\n\n";
            $content .= "        \$response->assertRedirect();\n";

            // Check if soft deletes is used
            if (isset($databaseAnalysis['has_soft_deletes']) && $databaseAnalysis['has_soft_deletes']) {
                $content .= "        \$this->assertSoftDeleted('{$table}', ['id' => \$model->id]);\n";
            } else {
                $content .= "        \$this->assertDatabaseMissing('{$table}', ['id' => \$model->id]);\n";
            }

            $content .= "    }\n";
        } else {
            // Unit test - index method
            $content .= "    /**\n";
            $content .= "     * Test index method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_index_returns_view_with_data()\n";
            $content .= "    {\n";
            $content .= "        // Create test records\n";
            $content .= "        {$modelClass}::factory()->count(3)->create();\n\n";
            $content .= "        \$response = \$this->controller->index();\n\n";
            $content .= "        \$this->assertInstanceOf('Illuminate\\View\\View', \$response);\n";
            $content .= "        \$this->assertEquals('" . Str::plural(Str::snake($modelClass)) . ".index', \$response->getName());\n";
            $content .= "        \$this->assertArrayHasKey('" . Str::plural(Str::snake($modelClass)) . "', \$response->getData());\n";
            $content .= "    }\n\n";

            // Unit test methods for controller
            $content .= "    /**\n";
            $content .= "     * Test show method.\n";
            $content .= "     *\n";
            $content .= "     * @return void\n";
            $content .= "     */\n";
            $content .= "    public function test_show_returns_view_with_model()\n";
            $content .= "    {\n";
            $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
            $content .= "        \$response = \$this->controller->show(\$model);\n\n";
            $content .= "        \$this->assertInstanceOf('Illuminate\\View\\View', \$response);\n";
            $content .= "        \$this->assertEquals('" . Str::plural(Str::snake($modelClass)) . ".show', \$response->getName());\n";
            $content .= "        \$this->assertArrayHasKey('" . Str::snake($modelClass) . "', \$response->getData());\n";
            $content .= "    }\n\n";
        }

        $content .= "}\n";

        return $content;
    }

    /**
     * Generate validation rule test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function createValidationRuleTests(string $table, array $databaseAnalysis)
    {
        $this->info("Generating validation rule tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $testClass = "{$modelClass}ValidationTest";
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Feature\\Validation';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildValidationTestContent(
            $table,
            $modelClass,
            $testClass,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['validation_tests'][] = $testPath;

        $this->line("  <info>Generated validation test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build validation test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $testClass Test class name
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildValidationTestContent(
        string $table,
        string $modelClass,
        string $testClass,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $routePrefix = Str::plural(Str::kebab($modelClass));
        $columns = $databaseAnalysis['columns'] ?? [];

        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        // \$this->actingAs(User::factory()->create()); // Uncomment if authentication is required\n";
        $content .= "    }\n\n";

        // Get valid data method
        $content .= "    /**\n";
        $content .= "     * Get valid form data for testing.\n";
        $content .= "     *\n";
        $content .= "     * @return array\n";
        $content .= "     */\n";
        $content .= "    private function getValidData(): array\n";
        $content .= "    {\n";
        $content .= "        return [\n";

        // Generate sample valid data for each column
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $defaultValue = $this->getFakerStatement($column);
            $content .= "            '{$columnName}' => {$defaultValue},\n";
        }

        $content .= "        ];\n";
        $content .= "    }\n\n";

        // Test validation passes with valid data
        $content .= "    /**\n";
        $content .= "     * Test validation passes with valid data.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_validation_passes_with_valid_data()\n";
        $content .= "    {\n";
        $content .= "        \$validData = \$this->getValidData();\n\n";
        $content .= "        \$response = \$this->post(route('{$routePrefix}.store'), \$validData);\n\n";
        $content .= "        \$response->assertSessionHasNoErrors();\n";
        $content .= "    }\n\n";

        // Generate tests for required fields
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Check if column is not nullable
            if (($column['nullable'] ?? true) === false) {
                $content .= "    /**\n";
                $content .= "     * Test validation fails when {$columnName} is missing.\n";
                $content .= "     *\n";
                $content .= "     * @return void\n";
                $content .= "     */\n";
                $content .= "    public function test_validation_fails_when_{$columnName}_is_missing()\n";
                $content .= "    {\n";
                $content .= "        \$validData = \$this->getValidData();\n";
                $content .= "        unset(\$validData['{$columnName}']);\n\n";
                $content .= "        \$response = \$this->post(route('{$routePrefix}.store'), \$validData);\n\n";
                $content .= "        \$response->assertSessionHasErrors('{$columnName}');\n";
                $content .= "    }\n\n";
            }
        }

        $content .= "}\n";

        return $content;
    }

    /**
     * Get a Faker statement for a column based on its type.
     *
     * @param array $column The column definition
     * @return string Faker statement
     */
    protected function getFakerStatement(array $column): string
    {
        $type = $column['type'] ?? 'string';

        switch ($type) {
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return 'fake()->numberBetween(1, 100)';

            case 'string':
            case 'varchar':
            case 'char':
                return 'fake()->word';

            case 'text':
            case 'longtext':
            case 'mediumtext':
                return 'fake()->paragraph';

            case 'boolean':
                return 'fake()->boolean';

            case 'date':
                return 'fake()->date()';

            case 'datetime':
            case 'timestamp':
                return 'fake()->dateTime()->format(\'Y-m-d H:i:s\')';

            case 'decimal':
            case 'float':
            case 'double':
                return 'fake()->randomFloat(2, 0, 100)';

            case 'json':
            case 'jsonb':
                return "json_encode(['key' => 'value'])";

            case 'enum':
                if (!empty($column['values'])) {
                    $values = $column['values'];
                    $randomValue = $values[array_rand($values)];
                    return "'{$randomValue}'";
                }
                return 'fake()->word';

            default:
                return 'fake()->word';
        }
    }

    /**
     * Generate browser test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function setupBrowserTests(string $table, array $databaseAnalysis)
    {
        $this->info("Generating browser tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $testClass = "{$modelClass}BrowserTest";
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Browser';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing browser test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildBrowserTestContent(
            $table,
            $modelClass,
            $testClass,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['browser_tests'][] = $testPath;

        $this->line("  <info>Generated browser test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build browser test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $testClass Test class name
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildBrowserTestContent(
        string $table,
        string $modelClass,
        string $testClass,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $routePrefix = Str::plural(Str::kebab($modelClass));
        $columns = $databaseAnalysis['columns'] ?? [];

        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use Laravel\\Dusk\\Browser;\n";
        $content .= "use Tests\\DuskTestCase;\n";
        $content .= "use Illuminate\\Foundation\\Testing\\DatabaseMigrations;\n\n";

        $content .= "class {$testClass} extends DuskTestCase\n{\n";
        $content .= "    use DatabaseMigrations;\n\n";

        // Test index page
        $content .= "    /**\n";
        $content .= "     * Test index page loads correctly.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_index_page_loads()\n";
        $content .= "    {\n";
        $content .= "        \$this->browse(function (Browser \$browser) {\n";
        $content .= "            \$browser->visit('/{$routePrefix}')\n";
        $content .= "                ->assertSee('" . Str::plural($modelClass) . "')\n";
        $content .= "                ->assertPresent('table');\n";
        $content .= "        });\n";
        $content .= "    }\n\n";

        // Test creation form
        $content .= "    /**\n";
        $content .= "     * Test create form works correctly.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_create_form_works()\n";
        $content .= "    {\n";
        $content .= "        \$this->browse(function (Browser \$browser) {\n";
        $content .= "            \$browser->visit('/{$routePrefix}/create')\n";
        $content .= "                ->assertSee('Create " . $modelClass . "')\n";

        // Add form inputs for each field
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Add field interaction based on type
            $fieldType = $column['type'] ?? 'string';
            if (in_array($fieldType, ['boolean', 'tinyint']) && $column['length'] == 1) {
                $content .= "                ->check('#{$columnName}')\n";
            } else if ($fieldType === 'enum' && !empty($column['values'])) {
                $content .= "                ->select('{$columnName}', '{$column['values'][0]}')\n";
            } else if (in_array($fieldType, ['text', 'longtext', 'mediumtext'])) {
                $content .= "                ->type('{$columnName}', 'Test {$columnName}')\n";
            } else {
                $content .= "                ->type('{$columnName}', 'Test {$columnName}')\n";
            }
        }

        $content .= "                ->press('Submit')\n";
        $content .= "                ->assertPathIs('/{$routePrefix}')\n";
        $content .= "                ->assertSee('created successfully');\n";
        $content .= "        });\n";
        $content .= "    }\n\n";

        // Test edit form
        $content .= "    /**\n";
        $content .= "     * Test edit form works correctly.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_edit_form_works()\n";
        $content .= "    {\n";
        $content .= "        \$this->browse(function (Browser \$browser) {\n";
        $content .= "            // First create a record to edit\n";
        $content .= "            \$browser->visit('/{$routePrefix}/create');\n";

        // Fill in the form
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Add field interaction based on type
            $fieldType = $column['type'] ?? 'string';
            if (in_array($fieldType, ['boolean', 'tinyint']) && $column['length'] == 1) {
                $content .= "            \$browser->check('#{$columnName}');\n";
            } else if ($fieldType === 'enum' && !empty($column['values'])) {
                $content .= "            \$browser->select('{$columnName}', '{$column['values'][0]}');\n";
            } else if (in_array($fieldType, ['text', 'longtext', 'mediumtext'])) {
                $content .= "            \$browser->type('{$columnName}', 'Initial {$columnName}');\n";
            } else {
                $content .= "            \$browser->type('{$columnName}', 'Initial {$columnName}');\n";
            }
        }

        $content .= "            \$browser->press('Submit');\n\n";
        $content .= "            // Now edit the record\n";
        $content .= "            \$browser->clickLink('Edit')\n";

        // Change one field for the test
        $firstField = null;
        foreach ($columns as $columnName => $column) {
            if ($columnName !== 'id' && !in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                $firstField = $columnName;
                break;
            }
        }

        if ($firstField) {
            $content .= "                ->type('{$firstField}', 'Updated {$firstField}')\n";
        }

        $content .= "                ->press('Submit')\n";
        $content .= "                ->assertPathIs('/{$routePrefix}')\n";
        $content .= "                ->assertSee('updated successfully');\n";
        $content .= "        });\n";
        $content .= "    }\n\n";

        // Test deletion
        $content .= "    /**\n";
        $content .= "     * Test record deletion works.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_delete_works()\n";
        $content .= "    {\n";
        $content .= "        \$this->browse(function (Browser \$browser) {\n";
        $content .= "            // First create a record to delete\n";
        $content .= "            \$browser->visit('/{$routePrefix}/create');\n";

        // Fill in the form quickly for a record to delete
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Add field interaction based on type for first few fields
            if (count($columns) > 5) {
                $counter = 0;
                foreach ($columns as $colName => $col) {
                    if ($colName !== 'id' && !in_array($colName, ['created_at', 'updated_at', 'deleted_at'])) {
                        $counter++;
                        if ($counter > 3) {
                            break;
                        }

                        $fieldType = $col['type'] ?? 'string';
                        if (in_array($fieldType, ['boolean', 'tinyint']) && $col['length'] == 1) {
                            $content .= "            \$browser->check('#{$colName}');\n";
                        } else if ($fieldType === 'enum' && !empty($col['values'])) {
                            $content .= "            \$browser->select('{$colName}', '{$col['values'][0]}');\n";
                        } else {
                            $content .= "            \$browser->type('{$colName}', 'Delete Test');\n";
                        }
                    }
                }
            } else {
                $fieldType = $column['type'] ?? 'string';
                if (in_array($fieldType, ['boolean', 'tinyint']) && $column['length'] == 1) {
                    $content .= "            \$browser->check('#{$columnName}');\n";
                } else if ($fieldType === 'enum' && !empty($column['values'])) {
                    $content .= "            \$browser->select('{$columnName}', '{$column['values'][0]}');\n";
                } else {
                    $content .= "            \$browser->type('{$columnName}', 'Delete Test');\n";
                }
            }

            break; // Only need one field filled for deletion test
        }

        $content .= "            \$browser->press('Submit');\n\n";
        $content .= "            // Now delete the record\n";
        $content .= "            \$browser->clickLink('Delete')\n";
        $content .= "                ->acceptDialog()\n"; // For JavaScript confirmation dialogs
        $content .= "                ->assertPathIs('/{$routePrefix}')\n";
        $content .= "                ->assertSee('deleted successfully');\n";
        $content .= "        });\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Generate API test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function setupApiTesting(string $table, array $databaseAnalysis)
    {
        $this->info("Generating API tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $testClass = "{$modelClass}ApiTest";
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Feature\\Api';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing API test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildApiTestContent(
            $table,
            $modelClass,
            $testClass,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['api_tests'][] = $testPath;

        $this->line("  <info>Generated API test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build API test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $testClass Test class name
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildApiTestContent(
        string $table,
        string $modelClass,
        string $testClass,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $routePrefix = Str::plural(Str::kebab($modelClass));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $columns = $databaseAnalysis['columns'] ?? [];

        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n";
        $content .= "use Illuminate\\Foundation\\Testing\\WithFaker;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase, WithFaker;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        // \$this->withHeaders([\n";
        $content .= "        //     'Accept' => 'application/json',\n";
        $content .= "        //     'Authorization' => 'Bearer ' . \$this->createToken(),\n";
        $content .= "        // ]);\n";
        $content .= "    }\n\n";

        // Helper method for creating a valid token
        $content .= "    /**\n";
        $content .= "     * Create a test API token (placeholder).\n";
        $content .= "     *\n";
        $content .= "     * @return string\n";
        $content .= "     */\n";
        $content .= "    protected function createToken(): string\n";
        $content .= "    {\n";
        $content .= "        // Implement based on your authentication system\n";
        $content .= "        return 'test-token';\n";
        $content .= "    }\n\n";

        // Helper method to get valid data
        $content .= "    /**\n";
        $content .= "     * Get valid data for testing.\n";
        $content .= "     *\n";
        $content .= "     * @return array\n";
        $content .= "     */\n";
        $content .= "    protected function getValidData(): array\n";
        $content .= "    {\n";
        $content .= "        return [\n";

        // Generate sample valid data for each column
        foreach ($columns as $columnName => $column) {
            // Skip primary key and timestamps
            if ($columnName === 'id' || in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $defaultValue = $this->getFakerStatement($column);
            $content .= "            '{$columnName}' => {$defaultValue},\n";
        }

        $content .= "        ];\n";
        $content .= "    }\n\n";

        // Test API index endpoint
        $content .= "    /**\n";
        $content .= "     * Test API index endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_index_endpoint_returns_records()\n";
        $content .= "    {\n";
        $content .= "        // Create test records\n";
        $content .= "        {$modelClass}::factory()->count(3)->create();\n\n";
        $content .= "        \$response = \$this->getJson('/api/{$routePrefix}');\n\n";
        $content .= "        \$response->assertStatus(200)\n";
        $content .= "            ->assertJsonStructure([\n";
        $content .= "                'data',\n";
        $content .= "                'meta' => [\n";
        $content .= "                    'current_page',\n";
        $content .= "                    'last_page',\n";
        $content .= "                    'per_page',\n";
        $content .= "                    'total'\n";
        $content .= "                ]\n";
        $content .= "            ]);\n";
        $content .= "    }\n\n";

        // Test API show endpoint
        $content .= "    /**\n";
        $content .= "     * Test API show endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_show_endpoint_returns_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$response = \$this->getJson(\"/api/{$routePrefix}/{\$model->id}\");\n\n";
        $content .= "        \$response->assertStatus(200)\n";
        $content .= "            ->assertJsonStructure([\n";
        $content .= "                'data' => [\n";
        $content .= "                    'id',\n";

        // Add a few key fields to the expected JSON structure
        $counter = 0;
        foreach ($columns as $columnName => $column) {
            if ($columnName !== 'id' && !in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                $content .= "                    '{$columnName}',\n";
                $counter++;
                if ($counter >= 3) break; // Limit to 3 fields for brevity
            }
        }

        $content .= "                ]\n";
        $content .= "            ]);\n";
        $content .= "    }\n\n";

        // Test API store endpoint
        $content .= "    /**\n";
        $content .= "     * Test API store endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_store_endpoint_creates_record()\n";
        $content .= "    {\n";
        $content .= "        \$data = \$this->getValidData();\n\n";
        $content .= "        \$response = \$this->postJson(\"/api/{$routePrefix}\", \$data);\n\n";
        $content .= "        \$response->assertStatus(201)\n";
        $content .= "            ->assertJsonStructure([\n";
        $content .= "                'data' => [\n";
        $content .= "                    'id',\n";

        // Add same fields as in the show test
        $counter = 0;
        foreach ($columns as $columnName => $column) {
            if ($columnName !== 'id' && !in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                $content .= "                    '{$columnName}',\n";
                $counter++;
                if ($counter >= 3) break;
            }
        }

        $content .= "                ]\n";
        $content .= "            ]);\n\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', [\n";

        // Check first field exists in database
        $firstField = null;
        foreach ($columns as $columnName => $column) {
            if ($columnName !== 'id' && !in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                $firstField = $columnName;
                break;
            }
        }

        if ($firstField) {
            $content .= "            '{$firstField}' => \$data['{$firstField}'],\n";
        }

        $content .= "        ]);\n";
        $content .= "    }\n\n";

        // Test API update endpoint
        $content .= "    /**\n";
        $content .= "     * Test API update endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_update_endpoint_updates_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n";
        $content .= "        \$data = \$this->getValidData();\n\n";
        $content .= "        \$response = \$this->putJson(\"/api/{$routePrefix}/{\$model->id}\", \$data);\n\n";
        $content .= "        \$response->assertStatus(200)\n";
        $content .= "            ->assertJson([\n";
        $content .= "                'data' => [\n";
        $content .= "                    'id' => \$model->id,\n";

        // Check first field
        if ($firstField) {
            $content .= "                    '{$firstField}' => \$data['{$firstField}'],\n";
        }

        $content .= "                ]\n";
        $content .= "            ]);\n";
        $content .= "    }\n\n";

        // Test API delete endpoint
        $content .= "    /**\n";
        $content .= "     * Test API delete endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_delete_endpoint_deletes_record()\n";
        $content .= "    {\n";
        $content .= "        \$model = {$modelClass}::factory()->create();\n\n";
        $content .= "        \$response = \$this->deleteJson(\"/api/{$routePrefix}/{\$model->id}\");\n\n";
        $content .= "        \$response->assertStatus(200);\n";

        // Check if soft deletes is used
        if (isset($databaseAnalysis['has_soft_deletes']) && $databaseAnalysis['has_soft_deletes']) {
            $content .= "        \$this->assertSoftDeleted('{$table}', ['id' => \$model->id]);\n";
        } else {
            $content .= "        \$this->assertDatabaseMissing('{$table}', ['id' => \$model->id]);\n";
        }

        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Create factory classes.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function createFactoryClasses(string $table, array $databaseAnalysis)
    {
        $this->info("Checking factory class for '{$table}'...");

        // Only create factories if they don't exist
        try {
            $options = [
                'force' => $this->options['force'],
                'with_testing' => true
            ];

            $files = $this->factoryGenerator->generate($table, $options);

            if (!empty($files)) {
                $this->generatedFiles['factories'] = array_merge(
                    $this->generatedFiles['factories'] ?? [],
                    $files
                );

                foreach ($files as $file) {
                    $this->line("  <info>Generated factory:</info> {$file}");
                }
            }

            return $files;
        } catch (\Exception $e) {
            $this->error("Error generating factory for {$table}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate seeder test files.
     *
     * @param string $table The database table name
     * @param array $databaseAnalysis Database analysis results
     * @return array Generated files
     */
    protected function generateSeederTests(string $table, array $databaseAnalysis)
    {
        $this->info("Generating seeder tests for '{$table}'...");

        $modelClass = Str::studly(Str::singular($table));
        $seederClass = "{$modelClass}Seeder";
        $testClass = "{$seederClass}Test";
        $testNamespace = $this->options['namespace'] ?? 'Tests\\Unit\\Seeders';

        // Create test directory if it doesn't exist
        $testDir = base_path(str_replace('\\', '/', $testNamespace));
        if (!File::isDirectory($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $testPath = "{$testDir}/{$testClass}.php";

        // Skip if file exists and force option is not set
        if (File::exists($testPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing seeder test:</comment> {$testClass}");
            return [];
        }

        // Generate test content
        $content = $this->buildSeederTestContent(
            $table,
            $modelClass,
            $seederClass,
            $testClass,
            $testNamespace,
            $databaseAnalysis
        );

        // Write the file
        File::put($testPath, $content);
        $this->generatedFiles['seeder_tests'][] = $testPath;

        $this->line("  <info>Generated seeder test:</info> {$testClass}");

        return [$testPath];
    }

    /**
     * Build seeder test class content.
     *
     * @param string $table Table name
     * @param string $modelClass Model class name
     * @param string $seederClass Seeder class name
     * @param string $testClass Test class name
     * @param string $testNamespace Test namespace
     * @param array $databaseAnalysis Database analysis
     * @return string Generated test content
     */
    protected function buildSeederTestContent(
        string $table,
        string $modelClass,
        string $seederClass,
        string $testClass,
        string $testNamespace,
        array $databaseAnalysis
    ): string {
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $seederNamespace = Config::get('crud.namespaces.seeders', 'Database\\Seeders');

        $content = "<?php\n\nnamespace {$testNamespace};\n\n";
        $content .= "use {$modelNamespace}\\{$modelClass};\n";
        $content .= "use {$seederNamespace}\\{$seederClass};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n";
        $content .= "use Tests\\TestCase;\n\n";

        $content .= "class {$testClass} extends TestCase\n{\n";
        $content .= "    use RefreshDatabase;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "    }\n\n";

        // Test seeder runs successfully
        $content .= "    /**\n";
        $content .= "     * Test seeder runs successfully.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_seeder_runs_successfully()\n";
        $content .= "    {\n";
        $content .= "        \$this->seed({$seederClass}::class);\n\n";
        $content .= "        // Assert that data was inserted into the database\n";
        $content .= "        \$this->assertDatabaseCount('{$table}', '>', 0);\n";
        $content .= "    }\n\n";

        // Test seeded data is valid
        $content .= "    /**\n";
        $content .= "     * Test seeded data is valid.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_seeded_data_is_valid()\n";
        $content .= "    {\n";
        $content .= "        \$this->seed({$seederClass}::class);\n\n";
        $content .= "        \$models = {$modelClass}::all();\n\n";
        $content .= "        \$this->assertNotEmpty(\$models);\n\n";
        $content .= "        foreach (\$models as \$model) {\n";
        $content .= "            \$this->assertInstanceOf({$modelClass}::class, \$model);\n";

        // Check a few key fields
        foreach ($databaseAnalysis['columns'] ?? [] as $columnName => $column) {
            if ($columnName !== 'id' && !in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                // Only check for non-nullable fields
                if (($column['nullable'] ?? true) === false) {
                    $content .= "            \$this->assertNotNull(\$model->{$columnName});\n";
                }
            }
        }

        $content .= "        }\n";
        $content .= "    }\n\n";

        // Test database state after seeding
        $content .= "    /**\n";
        $content .= "     * Test database state after seeding.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_database_state_after_seeding()\n";
        $content .= "    {\n";
        $content .= "        // Initial state check\n";
        $content .= "        \$initialCount = {$modelClass}::count();\n\n";
        $content .= "        // Run the seeder\n";
        $content .= "        \$this->seed({$seederClass}::class);\n\n";
        $content .= "        // Check that records were added\n";
        $content .= "        \$newCount = {$modelClass}::count();\n";
        $content .= "        \$this->assertGreaterThan(\$initialCount, \$newCount);\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    /**
     * Display a summary of generated files.
     * 
     * @return void
     */
    protected function showSummary(): void
    {
        $this->line("\n<info>Test Generation Summary:</info>");

        $totalFiles = 0;

        foreach ($this->generatedFiles as $type => $files) {
            $count = count($files);
            $totalFiles += $count;
            $this->line("  - <info>{$count}</info> {$type}");
        }

        $this->line("\n<info>{$totalFiles}</info> files generated successfully.");
    }
}
