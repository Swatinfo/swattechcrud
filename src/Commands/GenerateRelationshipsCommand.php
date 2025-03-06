<?php

namespace SwatTech\Crud\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Generators\ControllerGenerator;
use SwatTech\Crud\Generators\ModelGenerator;
use SwatTech\Crud\Generators\RequestGenerator;
use SwatTech\Crud\Generators\RouteGenerator;
use SwatTech\Crud\Generators\ViewGenerator;

/**
 * GenerateRelationshipsCommand
 *
 * A command for generating relationship-focused code including model methods,
 * nested controllers, routes, forms, and validation rules based on the
 * database structure's relationships.
 *
 * @package SwatTech\Crud\Commands
 */
class GenerateRelationshipsCommand extends Command
{
    /**
     * The name and signature of the command.
     *
     * @var string
     */
    protected $signature = 'crud:relationships
                            {table? : The name of the database table to analyze}
                            {--all : Generate relationships for all tables}
                            {--belongs-to : Only generate belongsTo relationships}
                            {--has-many : Only generate hasMany relationships}
                            {--has-one : Only generate hasOne relationships}
                            {--many-to-many : Only generate many-to-many relationships}
                            {--polymorphic : Only generate polymorphic relationships}
                            {--controllers : Generate nested resource controllers}
                            {--routes : Generate nested resource routes}
                            {--forms : Generate relationship management forms}
                            {--validation : Generate relationship validation rules}
                            {--force : Overwrite existing files}
                            {--connection= : Database connection to use}
                            {--no-interaction : Do not ask any interactive questions}
                            {--dry-run : Run without creating any files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate relationship-focused code for database tables';

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
     * Route generator instance.
     *
     * @var RouteGenerator
     */
    protected $routeGenerator;

    /**
     * View generator instance.
     *
     * @var ViewGenerator
     */
    protected $viewGenerator;

    /**
     * Request generator instance.
     *
     * @var RequestGenerator
     */
    protected $requestGenerator;

    /**
     * List of generated files.
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
     * Command options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new command instance.
     *
     * @param RelationshipAnalyzer $relationshipAnalyzer
     * @param ModelGenerator $modelGenerator
     * @param ControllerGenerator $controllerGenerator
     * @param RouteGenerator $routeGenerator
     * @param ViewGenerator $viewGenerator
     * @param RequestGenerator $requestGenerator
     * @return void
     */
    public function __construct(
        RelationshipAnalyzer $relationshipAnalyzer,
        ModelGenerator $modelGenerator,
        ControllerGenerator $controllerGenerator,
        RouteGenerator $routeGenerator,
        ViewGenerator $viewGenerator,
        RequestGenerator $requestGenerator
    ) {
        parent::__construct();

        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->modelGenerator = $modelGenerator;
        $this->controllerGenerator = $controllerGenerator;
        $this->routeGenerator = $routeGenerator;
        $this->viewGenerator = $viewGenerator;
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
            if (method_exists($this->relationshipAnalyzer, 'setConnection')) {
                $this->relationshipAnalyzer->setConnection($connection);
            }
        }

        // Prepare options
        $this->options = [
            'force' => $this->option('force'),
            'dry_run' => $this->option('dry-run'),
            'controllers' => $this->option('controllers'),
            'routes' => $this->option('routes'),
            'forms' => $this->option('forms'),
            'validation' => $this->option('validation'),
            'belongsTo' => $this->option('belongs-to'),
            'hasMany' => $this->option('has-many'),
            'hasOne' => $this->option('has-one'),
            'manyToMany' => $this->option('many-to-many'),
            'polymorphic' => $this->option('polymorphic')
        ];

        // Show dry run message if enabled
        if ($this->options['dry_run']) {
            $this->warn('DRY RUN MODE - No files will be written');
        }

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
        }

        // Check if the table exists
        if (!Schema::hasTable($table)) {
            $this->error("Table '{$table}' does not exist in the database.");
            return 1;
        }

        // Process the table
        $this->info("Analyzing relationships for table '{$table}'...");

        try {
            // Analyze table relationships
            $relationships = $this->analyzeTableRelationships($table);

            if (empty($relationships)) {
                $this->warn("No relationships found for table '{$table}'.");
                return 0;
            }

            $this->info("Found " . count($relationships['relationships']) . " relationships.");

            // Generate code based on the relationships
            $this->generateRelationshipCode($table, $relationships);

            // Show summary
            $this->showSummary();

            return 0;
        } catch (\Exception $e) {
            $this->error('Error generating relationships: ' . $e->getMessage());
            if (!$this->option('quiet')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Process all tables in the database.
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

        // Confirm before proceeding with batch generation
        if (!$this->option('no-interaction')) {
            if (!$this->confirm("This will generate relationship code for " . count($tableNames) . " tables. Proceed?", true)) {
                $this->info("Operation cancelled by user.");
                return 0;
            }
        }

        $successCount = 0;
        $errorCount = 0;

        // Set up progress bar
        $progressBar = $this->output->createProgressBar(count($tableNames));
        $progressBar->start();

        foreach ($tableNames as $table) {
            try {
                // Analyze relationships
                $relationships = $this->analyzeTableRelationships($table);

                if (!empty($relationships)) {
                    // Generate code
                    $this->generateRelationshipCode($table, $relationships);
                    $successCount++;
                }
            } catch (\Exception $e) {
                $this->errors[] = "Error processing table {$table}: " . $e->getMessage();
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line(''); // Add a newline after the progress bar

        // Show summary
        $this->info("Batch processing completed: {$successCount} tables processed successfully, {$errorCount} errors.");
        $this->showSummary();

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Ask user to select a table from the database.
     *
     * @return string The selected table name
     */
    protected function askForTable()
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
            return null;
        }

        // Allow user to choose a table
        return $this->choice(
            'Select a database table to generate relationship code for:',
            $tableNames
        );
    }

    /**
     * Analyze relationships for a specific table.
     *
     * @param string $table The database table name
     * @return array The relationship analysis results
     */
    protected function analyzeTableRelationships(string $table)
    {
        $this->relationshipAnalyzer->analyze($table);
        return $this->relationshipAnalyzer->getResults();
    }

    /**
     * Generate code based on detected relationships.
     *
     * @param string $table The database table name
     * @param array $relationships The relationship analysis results
     * @return void
     */
    protected function generateRelationshipCode(string $table, array $relationships)
    {
        // Filter relationships based on options
        $filteredRelationships = $this->filterRelationships($relationships);

        if (empty($filteredRelationships['relationships'])) {
            $this->warn("No relationships matched the specified filters for table '{$table}'.");
            return;
        }

        // Generate model relationship methods
        $this->generateRelationshipMethods($table, $filteredRelationships);

        // Generate nested controllers if requested
        if ($this->options['controllers']) {
            $this->createNestedResourceControllers($table, $filteredRelationships);
        }

        // Generate routes if requested
        if ($this->options['routes']) {
            $this->setupNestedRoutes($table, $filteredRelationships);
        }

        // Generate validation rules if requested
        if ($this->options['validation']) {
            $this->generateRelationshipValidationRules($table, $filteredRelationships);
        }

        // Generate relationship forms if requested
        if ($this->options['forms']) {
            $this->createRelationshipForms($table, $filteredRelationships);
        }

        // Handle polymorphic relationships specially
        if ($this->options['polymorphic'] || !$this->hasTypeFilter()) {
            $this->handlePolymorphicRelationships($table, $filteredRelationships);
        }
    }

    /**
     * Filter relationships based on command options.
     *
     * @param array $relationships The relationship analysis results
     * @return array The filtered relationships
     */
    protected function filterRelationships(array $relationships)
    {
        // If no type filter specified, return all
        if (!$this->hasTypeFilter()) {
            return $relationships;
        }

        $filtered = [
            'table' => $relationships['table'] ?? null,
            'relationships' => [],
            'bidirectional' => $relationships['bidirectional'] ?? [],
            'method_definitions' => [],
        ];

        // Filter relationships by type
        foreach ($relationships['relationships'] as $relationship) {
            $type = $relationship['type'] ?? '';

            if (
                ($this->options['belongsTo'] && $type === 'belongsTo') ||
                ($this->options['hasMany'] && $type === 'hasMany') ||
                ($this->options['hasOne'] && $type === 'hasOne') ||
                ($this->options['manyToMany'] && $type === 'belongsToMany') ||
                ($this->options['polymorphic'] && in_array($type, ['morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany']))
            ) {
                $filtered['relationships'][] = $relationship;
            }
        }

        // Filter method definitions to match filtered relationships
        if (!empty($relationships['method_definitions'])) {
            foreach ($relationships['method_definitions'] as $methodName => $methodDef) {
                foreach ($filtered['relationships'] as $relationship) {
                    if (($relationship['method'] ?? '') === $methodName) {
                        $filtered['method_definitions'][$methodName] = $methodDef;
                    }
                }
            }
        }

        return $filtered;
    }

    /**
     * Check if any relationship type filter is active.
     *
     * @return bool True if any relationship type filter is active
     */
    protected function hasTypeFilter()
    {
        return $this->options['belongsTo'] ||
            $this->options['hasMany'] ||
            $this->options['hasOne'] ||
            $this->options['manyToMany'] ||
            $this->options['polymorphic'];
    }

    /**
     * Generate relationship methods for models.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to generate methods for
     * @return void
     */
    protected function generateRelationshipMethods(string $table, array $relationships)
    {
        if (empty($relationships['method_definitions'])) {
            return;
        }

        $this->info("Generating relationship methods for '{$table}'...");

        $modelClass = $this->modelGenerator->getClassName($table);
        $modelNamespace = $this->modelGenerator->getNamespace();
        $modelPath = $this->modelGenerator->getPath() . '/' . $modelClass . '.php';

        // Skip if model file doesn't exist
        if (!File::exists($modelPath) && !$this->options['dry_run']) {
            $this->warn("Model file does not exist: {$modelPath}. Generate the model first.");
            return;
        }

        if ($this->options['dry_run']) {
            $this->line("<comment>Would generate relationship methods:</comment>");
            foreach ($relationships['method_definitions'] as $methodDef) {
                $this->line("  - {$methodDef['name']} ({$methodDef['type']})");
            }
            return;
        }

        // Get model content
        $modelContent = File::exists($modelPath) ? File::get($modelPath) : '';

        if (empty($modelContent)) {
            $this->warn("Could not read model file: {$modelPath}");
            return;
        }

        // Check for existing methods to avoid duplicates
        $updatedContent = $modelContent;
        $methodsAdded = 0;

        foreach ($relationships['method_definitions'] as $methodDef) {
            $methodName = $methodDef['name'];

            // Skip if method already exists
            if (preg_match('/function\s+' . $methodName . '\s*\(/i', $modelContent)) {
                $this->line("  <comment>Skipping existing method:</comment> {$methodName}");
                continue;
            }

            // Find the insertion point (before the last closing brace)
            $lastBracePos = strrpos($updatedContent, '}');
            if ($lastBracePos !== false) {
                $methodCode = "\n    " . $methodDef['code'] . "\n";
                $updatedContent = substr_replace($updatedContent, $methodCode, $lastBracePos, 0);
                $methodsAdded++;
                $this->line("  <info>Added method:</info> {$methodName}");
            }
        }

        if ($methodsAdded > 0) {
            // Write the updated model file
            File::put($modelPath, $updatedContent);
            $this->generatedFiles['model_methods'][] = $modelPath;
            $this->info("Added {$methodsAdded} relationship methods to {$modelClass}");
        } else {
            $this->info("No new methods needed for {$modelClass}");
        }
    }

    /**
     * Create nested resource controllers for relationships.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to create controllers for
     * @return void
     */
    protected function createNestedResourceControllers(string $table, array $relationships)
    {
        if (empty($relationships['relationships'])) {
            return;
        }

        $this->info("Generating nested controllers for '{$table}' relationships...");

        // Get parent model details
        $parentModel = Str::studly(Str::singular($table));
        $parentVariable = Str::camel($parentModel);

        foreach ($relationships['relationships'] as $relationship) {
            $type = $relationship['type'] ?? '';
            $relatedTable = $relationship['related_table'] ?? $relationship['foreign_table'] ?? null;
            $methodName = $relationship['method'] ?? '';

            if (empty($type) || empty($relatedTable) || empty($methodName)) {
                continue;
            }

            // Skip non-navigable relationships for nested resources
            if (!in_array($type, ['hasMany', 'hasOne', 'belongsToMany', 'morphMany', 'morphToMany'])) {
                continue;
            }

            $relationshipName = Str::snake($methodName);
            $childModel = Str::studly(Str::singular($relatedTable));
            $controllerName = "{$parentModel}{$childModel}Controller";

            if ($this->options['dry_run']) {
                $this->line("<comment>Would generate controller:</comment> {$controllerName}");
                continue;
            }

            // Generate the controller
            $controllerOptions = [
                'force' => $this->options['force'],
                'parent_table' => $table,
                'parent_model' => $parentModel,
                'relationship_type' => $type,
                'relationship_method' => $methodName,
                'nested_resource' => true
            ];

            try {
                $files = $this->controllerGenerator->generate($relatedTable, $controllerOptions);

                if (!empty($files)) {
                    $this->generatedFiles['controllers'] = array_merge(
                        $this->generatedFiles['controllers'] ?? [],
                        $files
                    );

                    $this->line("  <info>Generated controller:</info> {$controllerName}");
                }
            } catch (\Exception $e) {
                $this->errors[] = "Error generating controller for {$relatedTable}: " . $e->getMessage();
                $this->warn("  Failed to generate controller: {$controllerName}");
            }
        }
    }

    /**
     * Set up nested routes for relationships.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to create routes for
     * @return void
     */
    protected function setupNestedRoutes(string $table, array $relationships)
    {
        if (empty($relationships['relationships'])) {
            return;
        }

        $this->info("Setting up nested routes for '{$table}' relationships...");

        // Prepare relationship data in the format expected by the route generator
        $routeRelationships = [];

        foreach ($relationships['relationships'] as $relationship) {
            $type = $relationship['type'] ?? '';
            $relatedTable = $relationship['related_table'] ?? $relationship['foreign_table'] ?? null;
            $methodName = $relationship['method'] ?? '';

            if (empty($type) || empty($relatedTable) || empty($methodName)) {
                continue;
            }

            // Skip non-navigable relationships for nested resources
            if (!in_array($type, ['hasMany', 'hasOne', 'belongsToMany', 'morphMany', 'morphToMany'])) {
                continue;
            }

            $routeRelationships[$methodName] = [
                'type' => $type,
                'table' => $relatedTable,
                'method' => $methodName
            ];
        }

        if (empty($routeRelationships)) {
            $this->line("  <comment>No eligible relationships for nested routes</comment>");
            return;
        }

        if ($this->options['dry_run']) {
            $this->line("<comment>Would generate routes for:</comment>");
            foreach ($routeRelationships as $method => $rel) {
                $this->line("  - {$table} -> {$rel['table']} ({$rel['type']})");
            }
            return;
        }

        // Generate routes
        $routeOptions = [
            'force' => $this->options['force'],
            'relationships' => $routeRelationships,
        ];

        try {
            $files = $this->routeGenerator->generate($table, $routeOptions);

            if (!empty($files)) {
                $this->generatedFiles['routes'] = array_merge(
                    $this->generatedFiles['routes'] ?? [],
                    $files
                );

                foreach ($files as $file) {
                    $this->line("  <info>Generated/Updated routes:</info> {$file}");
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error generating routes for {$table}: " . $e->getMessage();
            $this->warn("  Failed to generate routes");
        }
    }

    /**
     * Generate validation rules for relationships.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to generate validation for
     * @return void
     */
    protected function generateRelationshipValidationRules(string $table, array $relationships)
    {
        if (empty($relationships['relationships'])) {
            return;
        }

        $this->info("Generating validation rules for '{$table}' relationships...");

        // Build validation rules for relationship fields
        $validationRules = [];

        foreach ($relationships['relationships'] as $relationship) {
            $type = $relationship['type'] ?? '';
            $relatedTable = $relationship['related_table'] ?? $relationship['foreign_table'] ?? null;

            if (empty($type) || empty($relatedTable)) {
                continue;
            }

            switch ($type) {
                case 'belongsTo':
                    $foreignKey = $relationship['foreign_key'] ?? Str::snake(Str::singular($relatedTable)) . '_id';
                    $validationRules[$foreignKey] = "required|exists:{$relatedTable},id";
                    break;

                case 'belongsToMany':
                    $relationName = $relationship['method'] ?? Str::camel(Str::plural($relatedTable));
                    $validationRules["{$relationName}.*"] = "exists:{$relatedTable},id";
                    break;

                case 'morphTo':
                    $morphName = $relationship['morph_name'] ?? '';
                    if ($morphName) {
                        $validationRules["{$morphName}_id"] = "required";
                        $validationRules["{$morphName}_type"] = "required|string";
                    }
                    break;
            }
        }

        if (empty($validationRules)) {
            $this->line("  <comment>No validation rules generated</comment>");
            return;
        }

        if ($this->options['dry_run']) {
            $this->line("<comment>Would generate validation rules:</comment>");
            foreach ($validationRules as $field => $rule) {
                $this->line("  - {$field}: {$rule}");
            }
            return;
        }

        // Update or generate request classes
        foreach (['create', 'update'] as $action) {
            try {
                $requestOptions = [
                    'force' => $this->options['force'],
                    'custom_rules' => $validationRules,
                    'action' => $action
                ];

                $files = $this->requestGenerator->generate($table, $requestOptions);

                if (!empty($files)) {
                    $this->generatedFiles['requests'] = array_merge(
                        $this->generatedFiles['requests'] ?? [],
                        $files
                    );

                    foreach ($files as $file) {
                        $this->line("  <info>Generated/Updated request:</info> {$file}");
                    }
                }
            } catch (\Exception $e) {
                $this->errors[] = "Error generating request for {$table}: " . $e->getMessage();
                $this->warn("  Failed to generate {$action} request");
            }
        }
    }

    /**
     * Create forms for managing relationships.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to create forms for
     * @return void
     */
    protected function createRelationshipForms(string $table, array $relationships)
    {
        if (empty($relationships['relationships'])) {
            return;
        }

        $this->info("Generating relationship forms for '{$table}'...");

        // Group relationships by type
        $relationshipsByType = [];
        foreach ($relationships['relationships'] as $relationship) {
            $type = $relationship['type'] ?? '';
            if (!isset($relationshipsByType[$type])) {
                $relationshipsByType[$type] = [];
            }
            $relationshipsByType[$type][] = $relationship;
        }

        if ($this->options['dry_run']) {
            $this->line("<comment>Would generate relationship forms for:</comment>");
            foreach ($relationshipsByType as $type => $rels) {
                $this->line("  - {$type} relationships: " . count($rels));
            }
            return;
        }

        // Generate forms based on relationship types
        try {
            $viewOptions = [
                'force' => $this->options['force'],
                'relationships' => $relationships['relationships'],
                'relationship_forms' => true
            ];

            $files = $this->viewGenerator->generate($table, $viewOptions);

            if (!empty($files)) {
                $this->generatedFiles['views'] = array_merge(
                    $this->generatedFiles['views'] ?? [],
                    $files
                );

                foreach ($files as $file) {
                    $this->line("  <info>Generated relationship form:</info> {$file}");
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error generating relationship forms for {$table}: " . $e->getMessage();
            $this->warn("  Failed to generate relationship forms");
        }
    }

    /**
     * Handle polymorphic relationship generation.
     *
     * @param string $table The database table name
     * @param array $relationships The relationships to process
     * @return void
     */
    protected function handlePolymorphicRelationships(string $table, array $relationships)
    {
        // Filter to only polymorphic relationships
        $polymorphicRelationships = array_filter(
            $relationships['relationships'] ?? [],
            function ($rel) {
                $type = $rel['type'] ?? '';
                return in_array($type, ['morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany']);
            }
        );

        if (empty($polymorphicRelationships)) {
            return;
        }

        $this->info("Handling polymorphic relationships for '{$table}'...");

        if ($this->options['dry_run']) {
            $this->line("<comment>Would handle polymorphic relationships:</comment>");
            foreach ($polymorphicRelationships as $rel) {
                $this->line("  - {$rel['type']}: {$rel['method']}");
            }
            return;
        }

        // Process morphTo relationships (polymorphic belongs to)
        $morphToRelationships = array_filter($polymorphicRelationships, function ($rel) {
            return ($rel['type'] ?? '') === 'morphTo';
        });

        if (!empty($morphToRelationships)) {
            foreach ($morphToRelationships as $morphTo) {
                $morphName = $morphTo['morph_name'] ?? '';
                if (!$morphName) continue;

                $this->line("  <info>Processing morphTo relationship:</info> {$morphName}");

                // Generate model trait with morph map if needed
                $this->generateMorphMapTrait($morphName);
            }
        }

        // Process morphOne/morphMany relationships (polymorphic has one/many)
        $morphOwnerRelationships = array_filter($polymorphicRelationships, function ($rel) {
            return in_array($rel['type'] ?? '', ['morphOne', 'morphMany']);
        });

        if (!empty($morphOwnerRelationships)) {
            foreach ($morphOwnerRelationships as $morphRel) {
                $relatedTable = $morphRel['related_table'] ?? '';
                $morphName = $morphRel['morph_name'] ?? '';
                $type = $morphRel['type'] ?? '';

                if (!$relatedTable || !$morphName) continue;

                $this->line("  <info>Processing {$type} relationship:</info> {$morphName} on {$relatedTable}");

                // Generate controller for polymorphic relationship if needed
                if ($this->options['controllers']) {
                    $this->generatePolymorphicController($table, $relatedTable, $morphName, $type);
                }
            }
        }

        // Process morphToMany/morphedByMany relationships (polymorphic many-to-many)
        $morphManyToManyRelationships = array_filter($polymorphicRelationships, function ($rel) {
            return in_array($rel['type'] ?? '', ['morphToMany', 'morphedByMany']);
        });

        if (!empty($morphManyToManyRelationships)) {
            foreach ($morphManyToManyRelationships as $morphRel) {
                $relatedTable = $morphRel['related_table'] ?? '';
                $morphName = $morphRel['morph_name'] ?? '';
                $pivotTable = $morphRel['pivot_table'] ?? '';
                $type = $morphRel['type'] ?? '';

                if (!$relatedTable || !$morphName) continue;

                $this->line("  <info>Processing {$type} relationship:</info> {$morphName} with {$relatedTable}");

                // Generate validation rules for polymorphic many-to-many
                if ($this->options['validation']) {
                    $this->generatePolymorphicManyToManyValidation($table, $relatedTable, $morphName);
                }

                // Generate forms for polymorphic many-to-many
                if ($this->options['forms']) {
                    $this->generatePolymorphicManyToManyForms($table, $relatedTable, $morphName, $pivotTable);
                }
            }
        }
    }

    /**
     * Generate a morph map trait for polymorphic relationships.
     *
     * @param string $morphName The morph name
     * @return void
     */
    protected function generateMorphMapTrait(string $morphName)
    {
        $traitName = 'MorphMapTrait';
        $namespace = Config::get('crud.namespaces.traits', 'App\\Traits');
        $path = app_path('Traits');

        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        $traitPath = $path . '/' . $traitName . '.php';

        // Skip if trait already exists and we're not forcing
        if (File::exists($traitPath) && !$this->options['force']) {
            $this->line("  <comment>Trait already exists:</comment> {$traitPath}");
            return;
        }

        $content = "<?php\n\nnamespace {$namespace};\n\ntrait {$traitName}\n{\n";
        $content .= "    /**\n";
        $content .= "     * Boot the trait.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public static function bootMorphMapTrait()\n";
        $content .= "    {\n";
        $content .= "        \\Illuminate\\Database\\Eloquent\\Relations\\Relation::morphMap([\n";
        $content .= "            // Define your morph map here\n";
        $content .= "            // '{$morphName}' => App\\Models\\YourModel::class,\n";
        $content .= "        ]);\n";
        $content .= "    }\n";
        $content .= "}\n";

        File::put($traitPath, $content);

        $this->generatedFiles['traits'][] = $traitPath;
        $this->line("  <info>Generated morph map trait:</info> {$traitPath}");
    }

    /**
     * Generate controller for polymorphic relationships.
     *
     * @param string $parentTable The parent table name
     * @param string $relatedTable The related table name
     * @param string $morphName The morph name
     * @param string $type The relationship type
     * @return void
     */
    protected function generatePolymorphicController(string $parentTable, string $relatedTable, string $morphName, string $type)
    {
        $parentModel = Str::studly(Str::singular($parentTable));
        $relatedModel = Str::studly(Str::singular($relatedTable));
        $controllerName = "{$parentModel}{$relatedModel}Controller";

        $controllerOptions = [
            'force' => $this->options['force'],
            'parent_table' => $parentTable,
            'parent_model' => $parentModel,
            'relationship_type' => $type,
            'morph_name' => $morphName,
            'polymorphic' => true
        ];

        try {
            $files = $this->controllerGenerator->generate($relatedTable, $controllerOptions);

            if (!empty($files)) {
                $this->generatedFiles['controllers'] = array_merge(
                    $this->generatedFiles['controllers'] ?? [],
                    $files
                );

                $this->line("  <info>Generated polymorphic controller:</info> {$controllerName}");
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error generating polymorphic controller for {$relatedTable}: " . $e->getMessage();
            $this->warn("  Failed to generate controller: {$controllerName}");
        }
    }

    /**
     * Generate validation rules for polymorphic many-to-many relationships.
     *
     * @param string $table The main table name
     * @param string $relatedTable The related table name
     * @param string $morphName The morph name
     * @return void
     */
    protected function generatePolymorphicManyToManyValidation(string $table, string $relatedTable, string $morphName)
    {
        $validationRules = [
            "{$morphName}.*" => "exists:{$relatedTable},id"
        ];

        try {
            foreach (['create', 'update'] as $action) {
                $requestOptions = [
                    'force' => $this->options['force'],
                    'custom_rules' => $validationRules,
                    'action' => $action
                ];

                $files = $this->requestGenerator->generate($table, $requestOptions);

                if (!empty($files)) {
                    $this->generatedFiles['requests'] = array_merge(
                        $this->generatedFiles['requests'] ?? [],
                        $files
                    );

                    foreach ($files as $file) {
                        $this->line("  <info>Updated request with polymorphic validation:</info> {$file}");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error generating polymorphic validation for {$morphName}: " . $e->getMessage();
            $this->warn("  Failed to generate polymorphic validation");
        }
    }

    /**
     * Generate forms for polymorphic many-to-many relationships.
     *
     * @param string $table The main table name
     * @param string $relatedTable The related table name
     * @param string $morphName The morph name
     * @param string $pivotTable The pivot table name
     * @return void
     */
    protected function generatePolymorphicManyToManyForms(string $table, string $relatedTable, string $morphName, string $pivotTable)
    {
        try {
            $viewOptions = [
                'force' => $this->options['force'],
                'polymorphic_relationship' => [
                    'type' => 'morphToMany',
                    'morph_name' => $morphName,
                    'related_table' => $relatedTable,
                    'pivot_table' => $pivotTable
                ]
            ];

            $files = $this->viewGenerator->generate($table, $viewOptions);

            if (!empty($files)) {
                $this->generatedFiles['views'] = array_merge(
                    $this->generatedFiles['views'] ?? [],
                    $files
                );

                foreach ($files as $file) {
                    $this->line("  <info>Generated polymorphic relationship form:</info> {$file}");
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error generating polymorphic forms for {$morphName}: " . $e->getMessage();
            $this->warn("  Failed to generate polymorphic forms");
        }
    }

    /**
     * Display a summary of generated files and errors.
     *
     * @return void
     */
    protected function showSummary()
    {
        if (empty($this->generatedFiles) && empty($this->errors)) {
            return;
        }

        $this->line('');
        $this->info('Generation Summary:');

        // Show generated files by type
        if (!empty($this->generatedFiles)) {
            foreach ($this->generatedFiles as $type => $files) {
                $this->line('');
                $this->line("<comment>" . ucfirst(str_replace('_', ' ', $type)) . ":</comment>");

                foreach ($files as $file) {
                    $this->line("  - " . $file);
                }
            }
        }

        // Show errors if any
        if (!empty($this->errors)) {
            $this->line('');
            $this->error('Errors:');

            foreach ($this->errors as $error) {
                $this->line("  - " . $error);
            }
        }
    }
}
