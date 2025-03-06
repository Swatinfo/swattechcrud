<?php

namespace SwatTech\Crud\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Generators\ControllerGenerator;
use SwatTech\Crud\Generators\ModelGenerator;
use SwatTech\Crud\Generators\RequestGenerator;
use SwatTech\Crud\Generators\ResourceGenerator;
use SwatTech\Crud\Generators\RepositoryGenerator;
use SwatTech\Crud\Generators\ServiceGenerator;
use SwatTech\Crud\Generators\ViewGenerator;

/**
 * GenerateDocumentationCommand
 *
 * This command generates comprehensive documentation for database tables
 * including API documentation, database schema documentation, relationship
 * diagrams, CRUD operations documentation, and UI user guides.
 *
 * @package SwatTech\Crud\Commands
 */
class GenerateDocumentationCommand extends Command
{
    /**
     * The name and signature of the command.
     *
     * @var string
     */
    protected $signature = 'crud:docs
                            {table? : The name of the database table}
                            {--all : Generate documentation for all tables}
                            {--api : Generate only API documentation}
                            {--schema : Generate only database schema documentation}
                            {--relationships : Generate only relationship diagrams}
                            {--crud : Generate only CRUD operations documentation}
                            {--validation : Generate only validation rules documentation}
                            {--ui : Generate only UI user guides}
                            {--format= : Documentation format (markdown, html, pdf)}
                            {--output= : Output directory for documentation files}
                            {--connection= : Database connection to use}
                            {--no-interaction : Do not ask any interactive questions}
                            {--force : Overwrite existing documentation files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate comprehensive documentation for database tables';

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
     * Resource generator instance.
     *
     * @var ResourceGenerator
     */
    protected $resourceGenerator;

    /**
     * Documentation configuration options.
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
     * @param ViewGenerator $viewGenerator
     * @param RequestGenerator $requestGenerator
     * @param ResourceGenerator $resourceGenerator
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
        ResourceGenerator $resourceGenerator
    ) {
        parent::__construct();

        $this->databaseAnalyzer = $databaseAnalyzer;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->modelGenerator = $modelGenerator;
        $this->controllerGenerator = $controllerGenerator;
        $this->repositoryGenerator = $repositoryGenerator;
        $this->serviceGenerator = $serviceGenerator;
        $this->viewGenerator = $viewGenerator;
        $this->requestGenerator = $requestGenerator;
        $this->resourceGenerator = $resourceGenerator;
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
            if (method_exists($this->relationshipAnalyzer, 'setConnection')) {
                $this->relationshipAnalyzer->setConnection($connection);
            }
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
            if (!$table) {
                return 1;
            }
        }

        // Check if the table exists
        if (!Schema::hasTable($table)) {
            $this->error("Table '{$table}' does not exist in the database.");
            return 1;
        }

        // Begin documentation generation process
        $this->info("Generating documentation for table '{$table}'...");

        try {
            // Analyze table structure
            $this->info('Analyzing database structure...');
            $databaseAnalysis = $this->databaseAnalyzer->analyze($table);

            // Analyze relationships
            $this->info('Analyzing relationships...');
            $this->relationshipAnalyzer->analyze($table);
            $relationships = $this->relationshipAnalyzer->getResults();

            // Prepare options
            $this->options = [
                'force' => $this->option('force'),
                'format' => $this->option('format') ?? 'markdown',
                'output_path' => $this->option('output') ?? $this->getDefaultOutputPath(),
                'database_analysis' => $databaseAnalysis,
                'relationship_analysis' => $relationships,
            ];

            // Generate the requested documentation
            $this->generateDocumentation($table);

            // Show summary of generated files
            $this->showGeneratedFiles();

            return 0;
        } catch (\Exception $e) {
            $this->error("Error generating documentation: {$e->getMessage()}");
            $this->line($e->getTraceAsString());
            return 1;
        }
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
            'Select a database table to generate documentation for:',
            $tableNames
        );
    }

    /**
     * Process documentation generation for all tables.
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
            if (!$this->confirm("This will generate documentation for " . count($tableNames) . " tables. Proceed?", true)) {
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

                // Prepare options
                $this->options = [
                    'force' => $this->option('force'),
                    'format' => $this->option('format') ?? 'markdown',
                    'output_path' => $this->option('output') ?? $this->getDefaultOutputPath(),
                    'database_analysis' => $databaseAnalysis,
                    'relationship_analysis' => $relationships,
                ];

                // Generate documentation
                $this->generateDocumentation($table);

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
        $this->showGeneratedFiles();

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Generate documentation based on options.
     *
     * @param string $table Table name
     * @return void
     */
    protected function generateDocumentation(string $table)
    {
        $shouldGenerateAll = !$this->option('api') &&
            !$this->option('schema') &&
            !$this->option('relationships') &&
            !$this->option('crud') &&
            !$this->option('validation') &&
            !$this->option('ui');

        if ($shouldGenerateAll || $this->option('api')) {
            $this->createApiDocumentation($table);
        }

        if ($shouldGenerateAll || $this->option('schema')) {
            $this->documentDatabaseSchema($table);
        }

        if ($shouldGenerateAll || $this->option('relationships')) {
            $this->createRelationshipDiagrams($table);
        }

        if ($shouldGenerateAll || $this->option('crud')) {
            $this->documentCrudOperations($table);
        }

        if ($shouldGenerateAll || $this->option('validation')) {
            $this->documentValidationRules($table);
        }

        if ($shouldGenerateAll || $this->option('ui')) {
            $this->createUiUserGuides($table);
        }

        // Generate an index file to link all documentation together
        $this->generateMarkdownDocumentation($table);
    }

    /**
     * Generate main markdown documentation file.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function generateMarkdownDocumentation(string $table)
    {
        $this->info("Generating main documentation index for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}Documentation.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Build documentation content
        $content = "# {$modelClass} Documentation\n\n";
        $content .= "This documentation provides a comprehensive guide to the `{$table}` table and its related components.\n\n";

        // Add table of contents
        $content .= "## Table of Contents\n\n";
        $content .= "1. [Database Schema](#database-schema)\n";
        $content .= "2. [Relationships](#relationships)\n";
        $content .= "3. [CRUD Operations](#crud-operations)\n";
        $content .= "4. [API Reference](#api-reference)\n";
        $content .= "5. [Validation Rules](#validation-rules)\n";
        $content .= "6. [UI Guide](#ui-guide)\n\n";

        // Add links to generated documentation files
        $filesMap = [
            'schema' => "{$modelClass}Schema.md",
            'relationships' => "{$modelClass}Relationships.md",
            'crud' => "{$modelClass}CrudOperations.md",
            'api' => "{$modelClass}ApiReference.md",
            'validation' => "{$modelClass}ValidationRules.md",
            'ui' => "{$modelClass}UiGuide.md"
        ];

        foreach ($filesMap as $type => $filename) {
            if (File::exists("{$outputDir}/{$filename}")) {
                $content .= "See detailed [{$type} documentation]({$filename}).\n";
            }
        }

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['main_documentation'][] = $outputPath;
        return $outputPath;
    }

    /**
     * Create API documentation.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function createApiDocumentation(string $table)
    {
        $this->info("Generating API documentation for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}ApiReference.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing API documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Get table columns for documentation
        $columns = $this->options['database_analysis']['columns'] ?? [];

        // Build documentation content
        $content = "# {$modelClass} API Reference\n\n";
        $content .= "This document outlines the API endpoints available for the `{$table}` resource.\n\n";

        // Add base route information
        $routePrefix = Str::plural(Str::kebab($modelClass));
        $content .= "## Base URL\n\n";
        $content .= "All endpoints are relative to `/api/v1/{$routePrefix}`.\n\n";

        // Document endpoints
        $content .= "## Endpoints\n\n";

        // List endpoint
        $content .= "### List {$modelClass} Records\n\n";
        $content .= "```\nGET /api/v1/{$routePrefix}\n```\n\n";
        $content .= "**Parameters:**\n\n";
        $content .= "| Parameter | Type | Description |\n";
        $content .= "| --------- | ---- | ----------- |\n";
        $content .= "| page | integer | Page number for pagination |\n";
        $content .= "| per_page | integer | Number of records per page |\n";
        $content .= "| sort_by | string | Field to sort by |\n";
        $content .= "| sort_direction | string | Sort direction (asc/desc) |\n\n";

        $content .= "**Response:**\n\n";
        $content .= "```json\n{\n  \"data\": [\n    {\n";
        foreach (array_slice($columns, 0, 3) as $column => $details) {
            $content .= "      \"{$column}\": \"value\",\n";
        }
        $content .= "      ...\n    }\n  ],\n";
        $content .= "  \"meta\": {\n    \"current_page\": 1,\n    \"last_page\": 5,\n    \"per_page\": 15,\n    \"total\": 75\n  }\n}\n```\n\n";

        // Show endpoint
        $content .= "### Get Single {$modelClass}\n\n";
        $content .= "```\nGET /api/v1/{$routePrefix}/{id}\n```\n\n";
        $content .= "**Response:**\n\n";
        $content .= "```json\n{\n  \"data\": {\n";
        foreach (array_slice($columns, 0, 5) as $column => $details) {
            $content .= "    \"{$column}\": \"value\",\n";
        }
        $content .= "    ...\n  }\n}\n```\n\n";

        // Store endpoint
        $content .= "### Create {$modelClass}\n\n";
        $content .= "```\nPOST /api/v1/{$routePrefix}\n```\n\n";
        $content .= "**Request Body:**\n\n";
        $content .= "```json\n{\n";
        foreach ($columns as $column => $details) {
            if ($column !== 'id' && !in_array($column, ['created_at', 'updated_at', 'deleted_at'])) {
                $content .= "  \"{$column}\": \"value\",\n";
            }
        }
        $content .= "}\n```\n\n";

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['api_documentation'][] = $outputPath;
        return $outputPath;
    }

    /**
     * Document database schema.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function documentDatabaseSchema(string $table)
    {
        $this->info("Generating database schema documentation for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}Schema.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing schema documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Get table columns for documentation
        $columns = $this->options['database_analysis']['columns'] ?? [];
        $indices = $this->options['database_analysis']['indices'] ?? [];
        $primaryKey = $this->options['database_analysis']['primary_key'] ?? 'id';
        $hasTimestamps = $this->options['database_analysis']['has_timestamps'] ?? true;
        $hasSoftDeletes = $this->options['database_analysis']['has_soft_deletes'] ?? false;

        // Build documentation content
        $content = "# {$modelClass} Database Schema\n\n";
        $content .= "This document describes the database schema for the `{$table}` table.\n\n";

        // Add table information
        $content .= "## Table Information\n\n";
        $content .= "| Property | Value |\n";
        $content .= "| -------- | ----- |\n";
        $content .= "| Table Name | {$table} |\n";
        $content .= "| Primary Key | {$primaryKey} |\n";
        $content .= "| Timestamps | " . ($hasTimestamps ? "Yes" : "No") . " |\n";
        $content .= "| Soft Deletes | " . ($hasSoftDeletes ? "Yes" : "No") . " |\n\n";

        // Add column details
        $content .= "## Columns\n\n";
        $content .= "| Column | Type | Nullable | Default | Description |\n";
        $content .= "| ------ | ---- | -------- | ------- | ----------- |\n";

        foreach ($columns as $name => $column) {
            $type = $column['type'] ?? 'unknown';
            $nullable = ($column['nullable'] ?? false) ? "Yes" : "No";
            $default = $column['default'] ?? "NULL";
            $description = $name === $primaryKey ? "Primary key" : "";
            if ($name === 'created_at' || $name === 'updated_at') {
                $description = "Timestamp";
            } elseif ($name === 'deleted_at') {
                $description = "Soft delete timestamp";
            }
            $content .= "| {$name} | {$type} | {$nullable} | {$default} | {$description} |\n";
        }

        $content .= "\n";

        // Add indices information if available
        if (!empty($indices)) {
            $content .= "## Indices\n\n";
            $content .= "| Name | Columns | Type |\n";
            $content .= "| ---- | ------- | ---- |\n";

            foreach ($indices as $name => $index) {
                $columns = implode(', ', $index['columns'] ?? []);
                $type = $index['unique'] ? "Unique" : "Index";
                $content .= "| {$name} | {$columns} | {$type} |\n";
            }

            $content .= "\n";
        }

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['schema_documentation'][] = $outputPath;
        return $outputPath;
    }

    /**
     * Create relationship diagrams.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function createRelationshipDiagrams(string $table)
    {
        $this->info("Generating relationship documentation for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}Relationships.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing relationship documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Get relationships for documentation
        $relationships = $this->options['relationship_analysis']['relationships'] ?? [];

        // Build documentation content
        $content = "# {$modelClass} Relationships\n\n";
        $content .= "This document describes the relationships between the `{$table}` table and other tables.\n\n";

        // Add relationship diagrams using Mermaid.js
        $content .= "## Entity Relationship Diagram\n\n";
        $content .= "```mermaid\nerDiagram\n";
        $content .= "    {$modelClass} ||--o{ RELATED_ENTITY : \"relationship\"\n";

        // Add detected relationships to the diagram
        foreach ($relationships as $relationship) {
            $relatedModel = $relationship['related_model'] ?? 'RelatedModel';
            $relationType = $relationship['type'] ?? 'hasMany';
            $relationshipSymbol = $this->getRelationshipSymbol($relationType);
            $method = isset($relationship['method']) ? $relationship['method'] : $relationType;
            $content .= "    {$modelClass} {$relationshipSymbol} {$relatedModel} : \"{$method}\"\n";
        }

        $content .= "```\n\n";

        // Add detailed relationship descriptions
        $content .= "## Relationship Details\n\n";

        if (empty($relationships)) {
            $content .= "No relationships detected for the `{$table}` table.\n\n";
        } else {
            foreach ($relationships as $relationship) {
                $relatedModel = $relationship['related_model'] ?? 'RelatedModel';
                $relationType = $relationship['type'] ?? 'hasMany';
                $methodName = $relationship['method'] ?? '';

                $content .= "### {$relationType}: {$methodName}()\n\n";
                $content .= "- **Related Model:** {$relatedModel}\n";
                $content .= "- **Foreign Key:** " . ($relationship['foreign_key'] ?? 'N/A') . "\n";
                $content .= "- **Local Key:** " . ($relationship['local_key'] ?? 'id') . "\n";

                if ($relationType === 'belongsToMany') {
                    $content .= "- **Pivot Table:** " . ($relationship['pivot_table'] ?? 'N/A') . "\n";
                }

                $content .= "\n";
            }
        }

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['relationship_documentation'][] = $outputPath;
        return $outputPath;
    }


    /**
     * Document CRUD operations.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function documentCrudOperations(string $table)
    {
        $this->info("Generating CRUD operations documentation for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}CrudOperations.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing CRUD documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Build documentation content
        $content = "# {$modelClass} CRUD Operations\n\n";
        $content .= "This document outlines the CRUD (Create, Read, Update, Delete) operations for the `{$table}` resource.\n\n";

        // Document the model class
        $content .= "## Model\n\n";
        $content .= "The `{$modelClass}` model class represents records from the `{$table}` table.\n\n";
        $content .= "```php\n";
        $content .= "<?php\n\n";
        $content .= "namespace App\\Models;\n\n";
        $content .= "use Illuminate\\Database\\Eloquent\\Model;\n";
        $content .= "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n";

        if ($this->options['database_analysis']['has_soft_deletes'] ?? false) {
            $content .= "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n";
        }

        $content .= "\n";
        $content .= "class {$modelClass} extends Model\n";
        $content .= "{\n";
        $content .= "    use HasFactory;\n";

        if ($this->options['database_analysis']['has_soft_deletes'] ?? false) {
            $content .= "    use SoftDeletes;\n";
        }

        $content .= "\n";
        $content .= "    /**\n";
        $content .= "     * The table associated with the model.\n";
        $content .= "     *\n";
        $content .= "     * @var string\n";
        $content .= "     */\n";
        $content .= "    protected \$table = '{$table}';\n";
        $content .= "\n";
        $content .= "    /**\n";
        $content .= "     * The attributes that are mass assignable.\n";
        $content .= "     *\n";
        $content .= "     * @var array\n";
        $content .= "     */\n";
        $content .= "    protected \$fillable = [\n";

        foreach ($this->options['database_analysis']['columns'] ?? [] as $name => $column) {
            if ($name !== 'id' && !in_array($name, ['created_at', 'updated_at', 'deleted_at'])) {
                $content .= "        '{$name}',\n";
            }
        }

        $content .= "    ];\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Document controller methods
        $content .= "## Controller\n\n";
        $content .= "The `{$modelClass}Controller` handles CRUD operations for the `{$modelClass}` resource.\n\n";

        // Index method
        $content .= "### Listing Records\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Display a listing of the resource.\n";
        $content .= " *\n";
        $content .= " * @return \\Illuminate\\View\\View\n";
        $content .= " */\n";
        $content .= "public function index()\n";
        $content .= "{\n";
        $content .= "    \$filters = request()->get('filter', []);\n";
        $content .= "    \$sorts = request()->get('sort', []);\n";
        $content .= "    \$page = request()->get('page', 1);\n";
        $content .= "    \$perPage = request()->get('per_page', 15);\n";
        $content .= "    \n";
        $content .= "    \${$table} = \$this->service->getPaginated(\$page, \$perPage, \$filters, \$sorts);\n";
        $content .= "    \n";
        $content .= "    return view('{$table}.index', [\n";
        $content .= "        '{$table}' => \${$table},\n";
        $content .= "        'filters' => \$filters,\n";
        $content .= "    ]);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Show method
        $content .= "### Viewing a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Display the specified resource.\n";
        $content .= " *\n";
        $content .= " * @param  int  \$id\n";
        $content .= " * @return \\Illuminate\\View\\View\n";
        $content .= " */\n";
        $content .= "public function show(\$id)\n";
        $content .= "{\n";
        $content .= "    \$item = \$this->service->findById(\$id);\n";
        $content .= "    \n";
        $content .= "    \$this->authorizeAction('view', \$item);\n";
        $content .= "    \n";
        $content .= "    return view('{$table}.show', [\n";
        $content .= "        '" . Str::singular($table) . "' => \$item,\n";
        $content .= "    ]);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Create method
        $content .= "### Creating a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Show the form for creating a new resource.\n";
        $content .= " *\n";
        $content .= " * @return \\Illuminate\\View\\View\n";
        $content .= " */\n";
        $content .= "public function create()\n";
        $content .= "{\n";
        $content .= "    \$this->authorizeAction('create', {$modelClass}::class);\n";
        $content .= "    \n";
        $content .= "    return view('{$table}.create');\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Store method
        $content .= "### Storing a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Store a newly created resource in storage.\n";
        $content .= " *\n";
        $content .= " * @param  \\App\\Http\\Requests\\{$modelClass}StoreRequest  \$request\n";
        $content .= " * @return \\Illuminate\\Http\\RedirectResponse\n";
        $content .= " */\n";
        $content .= "public function store({$modelClass}StoreRequest \$request)\n";
        $content .= "{\n";
        $content .= "    \$this->authorizeAction('create', {$modelClass}::class);\n";
        $content .= "    \n";
        $content .= "    \$data = \$this->processInput(\$request);\n";
        $content .= "    \n";
        $content .= "    try {\n";
        $content .= "        \$item = \$this->service->create(\$data);\n";
        $content .= "        \n";
        $content .= "        \$this->flashSuccess('{$modelClass} created successfully.');\n";
        $content .= "        \n";
        $content .= "        return \$this->redirectToShow(\$item->id);\n";
        $content .= "    } catch (\\Exception \$e) {\n";
        $content .= "        return \$this->handleError(\$e, 'Failed to create {$modelClass}.');\n";
        $content .= "    }\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Edit method
        $content .= "### Editing a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Show the form for editing the specified resource.\n";
        $content .= " *\n";
        $content .= " * @param  int  \$id\n";
        $content .= " * @return \\Illuminate\\View\\View\n";
        $content .= " */\n";
        $content .= "public function edit(\$id)\n";
        $content .= "{\n";
        $content .= "    \$item = \$this->service->findById(\$id);\n";
        $content .= "    \n";
        $content .= "    \$this->authorizeAction('update', \$item);\n";
        $content .= "    \n";
        $content .= "    return view('{$table}.edit', [\n";
        $content .= "        '" . Str::singular($table) . "' => \$item,\n";
        $content .= "    ]);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Update method
        $content .= "### Updating a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Update the specified resource in storage.\n";
        $content .= " *\n";
        $content .= " * @param  \\App\\Http\\Requests\\{$modelClass}UpdateRequest  \$request\n";
        $content .= " * @param  int  \$id\n";
        $content .= " * @return \\Illuminate\\Http\\RedirectResponse\n";
        $content .= " */\n";
        $content .= "public function update({$modelClass}UpdateRequest \$request, \$id)\n";
        $content .= "{\n";
        $content .= "    \$item = \$this->service->findById(\$id);\n";
        $content .= "    \n";
        $content .= "    \$this->authorizeAction('update', \$item);\n";
        $content .= "    \n";
        $content .= "    \$data = \$this->processInput(\$request);\n";
        $content .= "    \n";
        $content .= "    try {\n";
        $content .= "        \$this->service->update(\$id, \$data);\n";
        $content .= "        \n";
        $content .= "        \$this->flashSuccess('{$modelClass} updated successfully.');\n";
        $content .= "        \n";
        $content .= "        return \$this->redirectToShow(\$id);\n";
        $content .= "    } catch (\\Exception \$e) {\n";
        $content .= "        return \$this->handleError(\$e, 'Failed to update {$modelClass}.');\n";
        $content .= "    }\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Destroy method
        $content .= "### Deleting a Record\n\n";
        $content .= "```php\n";
        $content .= "/**\n";
        $content .= " * Remove the specified resource from storage.\n";
        $content .= " *\n";
        $content .= " * @param  int  \$id\n";
        $content .= " * @return \\Illuminate\\Http\\RedirectResponse\n";
        $content .= " */\n";
        $content .= "public function destroy(\$id)\n";
        $content .= "{\n";
        $content .= "    \$item = \$this->service->findById(\$id);\n";
        $content .= "    \n";
        $content .= "    \$this->authorizeAction('delete', \$item);\n";
        $content .= "    \n";
        $content .= "    try {\n";
        $content .= "        \$this->service->delete(\$id);\n";
        $content .= "        \n";
        $content .= "        \$this->flashSuccess('{$modelClass} deleted successfully.');\n";
        $content .= "        \n";
        $content .= "        return \$this->redirectToIndex();\n";
        $content .= "    } catch (\\Exception \$e) {\n";
        $content .= "        return \$this->handleError(\$e, 'Failed to delete {$modelClass}.');\n";
        $content .= "    }\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Document repository pattern
        $content .= "## Repository Layer\n\n";
        $content .= "The repository pattern is used to abstract the data access logic from the rest of the application.\n\n";
        $content .= "### Repository Interface\n\n";
        $content .= "```php\n";
        $content .= "<?php\n\n";
        $content .= "namespace App\\Repositories\\Interfaces;\n\n";
        $content .= "interface {$modelClass}RepositoryInterface\n";
        $content .= "{\n";
        $content .= "    /**\n";
        $content .= "     * Get all records with optional filtering and sorting\n";
        $content .= "     *\n";
        $content .= "     * @param array \$filters\n";
        $content .= "     * @param array \$sorts\n";
        $content .= "     * @return \\Illuminate\\Database\\Eloquent\\Collection\n";
        $content .= "     */\n";
        $content .= "    public function all(array \$filters = [], array \$sorts = []);\n\n";
        $content .= "    /**\n";
        $content .= "     * Get paginated records\n";
        $content .= "     *\n";
        $content .= "     * @param int \$page\n";
        $content .= "     * @param int \$perPage\n";
        $content .= "     * @param array \$filters\n";
        $content .= "     * @param array \$sorts\n";
        $content .= "     * @return \\Illuminate\\Pagination\\LengthAwarePaginator\n";
        $content .= "     */\n";
        $content .= "    public function paginate(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = []);\n\n";
        $content .= "    /**\n";
        $content .= "     * Find record by ID\n";
        $content .= "     *\n";
        $content .= "     * @param int \$id\n";
        $content .= "     * @return \\App\\Models\\{$modelClass}|null\n";
        $content .= "     */\n";
        $content .= "    public function find(int \$id);\n\n";
        $content .= "    /**\n";
        $content .= "     * Create new record\n";
        $content .= "     *\n";
        $content .= "     * @param array \$data\n";
        $content .= "     * @return \\App\\Models\\{$modelClass}\n";
        $content .= "     */\n";
        $content .= "    public function create(array \$data);\n\n";
        $content .= "    /**\n";
        $content .= "     * Update existing record\n";
        $content .= "     *\n";
        $content .= "     * @param int \$id\n";
        $content .= "     * @param array \$data\n";
        $content .= "     * @return \\App\\Models\\{$modelClass}\n";
        $content .= "     */\n";
        $content .= "    public function update(int \$id, array \$data);\n\n";
        $content .= "    /**\n";
        $content .= "     * Delete a record\n";
        $content .= "     *\n";
        $content .= "     * @param int \$id\n";
        $content .= "     * @return bool\n";
        $content .= "     */\n";
        $content .= "    public function delete(int \$id);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Document service layer
        $content .= "## Service Layer\n\n";
        $content .= "The service layer contains the business logic for the application.\n\n";
        $content .= "### {$modelClass} Service\n\n";
        $content .= "```php\n";
        $content .= "<?php\n\n";
        $content .= "namespace App\\Services;\n\n";
        $content .= "use App\\Repositories\\Interfaces\\{$modelClass}RepositoryInterface;\n";
        $content .= "use Illuminate\\Database\\Eloquent\\Model;\n";
        $content .= "use Illuminate\\Pagination\\LengthAwarePaginator;\n";
        $content .= "use Illuminate\\Validation\\ValidationException;\n\n";
        $content .= "class {$modelClass}Service extends BaseService\n";
        $content .= "{\n";
        $content .= "    /**\n";
        $content .= "     * Create a new service instance.\n";
        $content .= "     *\n";
        $content .= "     * @param  {$modelClass}RepositoryInterface  \$repository\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function __construct({$modelClass}RepositoryInterface \$repository)\n";
        $content .= "    {\n";
        $content .= "        \$this->repository = \$repository;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get all records with filtering and sorting.\n";
        $content .= "     *\n";
        $content .= "     * @param array \$filters\n";
        $content .= "     * @param array \$sorts\n";
        $content .= "     * @return \\Illuminate\\Database\\Eloquent\\Collection\n";
        $content .= "     */\n";
        $content .= "    public function getAll(array \$filters = [], array \$sorts = [])\n";
        $content .= "    {\n";
        $content .= "        return \$this->repository->all(\$filters, \$sorts);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get paginated records.\n";
        $content .= "     *\n";
        $content .= "     * @param int \$page\n";
        $content .= "     * @param int \$perPage\n";
        $content .= "     * @param array \$filters\n";
        $content .= "     * @param array \$sorts\n";
        $content .= "     * @return LengthAwarePaginator\n";
        $content .= "     */\n";
        $content .= "    public function getPaginated(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = []): LengthAwarePaginator\n";
        $content .= "    {\n";
        $content .= "        return \$this->repository->paginate(\$page, \$perPage, \$filters, \$sorts);\n";
        $content .= "    }\n";
        $content .= "}\n";
        $content .= "```\n\n";

        // Usage examples
        $content .= "## Usage Examples\n\n";
        $content .= "### Creating a New {$modelClass}\n\n";
        $content .= "```php\n";
        $content .= "// In a controller method\n";
        $content .= "public function store({$modelClass}StoreRequest \$request)\n";
        $content .= "{\n";
        $content .= "    \$data = \$request->validated();\n";
        $content .= "    \$item = \$this->service->create(\$data);\n";
        $content .= "    return redirect()->route('{$table}.show', \$item->id);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        $content .= "### Updating an Existing {$modelClass}\n\n";
        $content .= "```php\n";
        $content .= "// In a controller method\n";
        $content .= "public function update({$modelClass}UpdateRequest \$request, \$id)\n";
        $content .= "{\n";
        $content .= "    \$data = \$request->validated();\n";
        $content .= "    \$this->service->update(\$id, \$data);\n";
        $content .= "    return redirect()->route('{$table}.show', \$id);\n";
        $content .= "}\n";
        $content .= "```\n\n";

        $content .= "### Filtering and Sorting\n\n";
        $content .= "```php\n";
        $content .= "// In a controller method\n";
        $content .= "public function index(Request \$request)\n";
        $content .= "{\n";
        $content .= "    \$filters = [\n";
        $content .= "        'name' => ['operator' => 'like', 'value' => '%' . \$request->input('name') . '%'],\n";
        $content .= "        'status' => ['operator' => '=', 'value' => \$request->input('status')],\n";
        $content .= "    ];\n\n";
        $content .= "    \$sorts = [\n";
        $content .= "        'created_at' => 'desc',\n";
        $content .= "    ];\n\n";
        $content .= "    \$results = \$this->service->getPaginated(1, 15, \$filters, \$sorts);\n";
        $content .= "}\n";
        $content .= "```\n";

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['crud_documentation'][] = $outputPath;
        return $outputPath;
    }



    /**
     * Document validation rules.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function documentValidationRules(string $table)
    {
        $this->info("Generating validation rules documentation for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}ValidationRules.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing validation documentation:</comment> {$outputPath}");
            return $outputPath;
        }

        // Get table columns for documentation
        $columns = $this->options['database_analysis']['columns'] ?? [];

        // Build documentation content
        $content = "# {$modelClass} Validation Rules\n\n";
        $content .= "This document outlines the validation rules for the `{$table}` resource.\n\n";

        // Create store/update validation rules based on column types
        $content .= "## Create Validation Rules\n\n";
        $content .= "The following validation rules apply when creating a new `{$modelClass}` resource:\n\n";
        $content .= "```php\n";
        $content .= "[\n";

        foreach ($columns as $name => $column) {
            // Skip primary key and timestamp columns
            if ($name === 'id' || in_array($name, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $rules = $this->generateValidationRules($name, $column);
            $content .= "    '{$name}' => '{$rules}',\n";
        }

        $content .= "]\n";
        $content .= "```\n\n";

        // Update validation rules
        $content .= "## Update Validation Rules\n\n";
        $content .= "The following validation rules apply when updating an existing `{$modelClass}` resource:\n\n";
        $content .= "```php\n";
        $content .= "[\n";

        foreach ($columns as $name => $column) {
            // Skip primary key and timestamp columns
            if ($name === 'id' || in_array($name, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Make fields nullable for updates
            $rules = $this->generateValidationRules($name, $column, true);
            $content .= "    '{$name}' => '{$rules}',\n";
        }

        $content .= "]\n";
        $content .= "```\n\n";

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['validation_documentation'][] = $outputPath;
        return $outputPath;
    }

    /**
     * Create UI user guides.
     *
     * @param string $table Table name
     * @return string Path to generated file
     */
    protected function createUiUserGuides(string $table)
    {
        $this->info("Generating UI user guides for '{$table}'...");

        // Prepare output directory
        $outputDir = $this->options['output_path'];
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $modelClass = Str::studly(Str::singular($table));
        $outputPath = "{$outputDir}/{$modelClass}UiGuide.md";

        // Skip if file exists and force option is not set
        if (File::exists($outputPath) && !$this->options['force']) {
            $this->line("  <comment>Skipping existing UI guide:</comment> {$outputPath}");
            return $outputPath;
        }

        // Build documentation content
        $content = "# {$modelClass} User Interface Guide\n\n";
        $content .= "This document provides a guide to the user interface for managing `{$table}`.\n\n";

        // List View
        $content .= "## List View\n\n";
        $content .= "The list view displays all {$table} records in a paginated table.\n\n";
        $content .= "![{$modelClass} List View](images/{$table}_list.png)\n\n";
        $content .= "### Features\n\n";
        $content .= "- **Search**: Use the search box to filter records by keyword\n";
        $content .= "- **Sorting**: Click column headers to sort by that column\n";
        $content .= "- **Pagination**: Navigate between pages using the pagination controls\n";
        $content .= "- **Actions**:\n";
        $content .= "  - **View**: Click the eye icon to view details\n";
        $content .= "  - **Edit**: Click the pencil icon to edit a record\n";
        $content .= "  - **Delete**: Click the trash icon to delete a record\n\n";

        // Create View
        $content .= "## Create New Record\n\n";
        $content .= "To create a new {$modelClass} record, click the 'Create New' button and fill out the form.\n\n";
        $content .= "![{$modelClass} Create Form](images/{$table}_create.png)\n\n";

        // Form Fields
        $content .= "### Form Fields\n\n";
        $content .= "| Field | Description | Validation |\n";
        $content .= "| ----- | ----------- | ---------- |\n";

        foreach ($this->options['database_analysis']['columns'] ?? [] as $name => $column) {
            if ($name !== 'id' && !in_array($name, ['created_at', 'updated_at', 'deleted_at'])) {
                $rules = $this->generateValidationRules($name, $column);
                $content .= "| {$name} | Enter the {$name} | {$rules} |\n";
            }
        }
        $content .= "\n";

        // Edit View
        $content .= "## Edit Record\n\n";
        $content .= "The edit form allows you to update an existing record.\n\n";
        $content .= "![{$modelClass} Edit Form](images/{$table}_edit.png)\n\n";

        // Detail View
        $content .= "## View Details\n\n";
        $content .= "The detail view shows all information about a specific record.\n\n";
        $content .= "![{$modelClass} Detail View](images/{$table}_detail.png)\n\n";

        // Write the content to file
        File::put($outputPath, $content);

        $this->generatedFiles['ui_guides'][] = $outputPath;
        return $outputPath;
    }

    /**
     * Generate validation rules based on column definition.
     *
     * @param string $name Column name
     * @param array $column Column definition
     * @param bool $isUpdate Whether generating rules for update
     * @return string
     */
    protected function generateValidationRules($name, $column, $isUpdate = false)
    {
        $rules = [];

        // Add required rule if not nullable (unless update)
        if (!($column['nullable'] ?? false) && !$isUpdate) {
            $rules[] = 'required';
        } elseif ($isUpdate) {
            $rules[] = 'nullable';
        }

        // Add type rules based on column type
        $type = $column['type'] ?? '';

        switch ($type) {
            case 'integer':
            case 'int':
            case 'bigint':
            case 'smallint':
                $rules[] = 'integer';
                break;
            case 'decimal':
            case 'float':
            case 'double':
                $rules[] = 'numeric';
                break;
            case 'boolean':
            case 'bool':
                $rules[] = 'boolean';
                break;
            case 'date':
                $rules[] = 'date';
                break;
            case 'datetime':
                $rules[] = 'date_format:Y-m-d H:i:s';
                break;
            case 'string':
            case 'varchar':
            case 'text':
                $rules[] = 'string';
                // Add max length if available
                if (isset($column['length']) && $column['length'] > 0) {
                    $rules[] = "max:{$column['length']}";
                }
                break;
            case 'email':
                $rules[] = 'email';
                break;
        }

        // Add unique rule for potential unique columns
        if (isset($column['unique']) && $column['unique']) {
            $uniqueRule = "unique:{$column['table']},{$name}";
            if ($isUpdate) {
                $uniqueRule .= ",\$id";
            }
            $rules[] = $uniqueRule;
        }

        return implode('|', $rules);
    }

    /**
     * Get the default output path for documentation.
     *
     * @return string
     */
    protected function getDefaultOutputPath()
    {
        return base_path('docs/crud');
    }

    /**
     * Get relationship symbol for ER diagrams.
     *
     * @param string $relationType
     * @return string
     */
    protected function getRelationshipSymbol($relationType)
    {
        switch ($relationType) {
            case 'hasOne':
                return '||--o|';
            case 'hasMany':
                return '||--o{';
            case 'belongsTo':
                return '}o--||';
            case 'belongsToMany':
                return '}o--o{';
            case 'hasOneThrough':
                return '||--o||--o|';
            case 'hasManyThrough':
                return '||--o||--o{';
            default:
                return '||--o{';
        }
    }

    /**
     * Show summary of generated files.
     *
     * @return void
     */
    protected function showGeneratedFiles()
    {
        $this->info("\nGenerated Documentation Files:");

        $totalFiles = 0;

        foreach ($this->generatedFiles as $type => $files) {
            $fileCount = count($files);
            $totalFiles += $fileCount;
            $typeLabel = str_replace('_', ' ', ucfirst($type));

            $this->line("\n<comment>{$typeLabel}:</comment> ({$fileCount})");

            foreach ($files as $file) {
                $this->line("  - " . basename($file));
            }
        }

        $this->info("\n<info>Total files generated:</info> {$totalFiles}");
    }
}
