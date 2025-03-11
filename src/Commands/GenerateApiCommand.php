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
use SwatTech\Crud\Generators\ResourceGenerator;
use SwatTech\Crud\Generators\RouteGenerator;
use SwatTech\Crud\Generators\RequestGenerator;
use SwatTech\Crud\Utilities\ResponseBuilder;

/**
 * GenerateApiCommand
 *
 * This command generates API-specific components for a database table,
 * including controllers, resources, routes, documentation, and other
 * API-related functionality.
 *
 * @package SwatTech\Crud\Commands
 */
class GenerateApiCommand extends Command
{
    /**
     * The name and signature of the command.
     *
     * @var string
     */
    protected $signature = 'crud:api
                            {table? : The name of the database table}
                            {--all : Generate API components for all tables}
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
                            {--rate-limit= : Apply rate limiting (format: max,period)}
                            {--cache= : Enable response caching (format: ttl_minutes)}
                            {--swagger : Generate Swagger/OpenAPI documentation}
                            {--force : Overwrite existing files}
                            {--no-routes : Skip route generation}
                            {--domain= : Custom domain for API endpoints}
                            {--namespace= : Custom namespace for API classes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate API components for database table';

    /**
     * Resource generator instance.
     *
     * @var ResourceGenerator
     */
    protected $resourceGenerator;

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
     * Request generator instance.
     *
     * @var RequestGenerator
     */
    protected $requestGenerator;

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
     * API configuration options.
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
     * @param ResourceGenerator $resourceGenerator
     * @param ControllerGenerator $controllerGenerator
     * @param RouteGenerator $routeGenerator
     * @param RequestGenerator $requestGenerator
     * @param DatabaseAnalyzer $databaseAnalyzer
     * @param RelationshipAnalyzer $relationshipAnalyzer
     * @return void
     */
    public function __construct(
        ResourceGenerator $resourceGenerator,
        ControllerGenerator $controllerGenerator,
        RouteGenerator $routeGenerator,
        RequestGenerator $requestGenerator,
        DatabaseAnalyzer $databaseAnalyzer,
        RelationshipAnalyzer $relationshipAnalyzer
    ) {
        parent::__construct();

        $this->resourceGenerator = $resourceGenerator;
        $this->controllerGenerator = $controllerGenerator;
        $this->routeGenerator = $routeGenerator;
        $this->requestGenerator = $requestGenerator;
        $this->databaseAnalyzer = $databaseAnalyzer;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
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

        // Check if user wants to generate API for all tables
        if ($this->option('all')) {
            return $this->processMultipleTables(Schema::getAllTables());
        }

        // Get the specified table name
        $table = $this->argument('table');
        if (!$table) {
            $table = $this->askForTable();

            if (!$table) {
                $this->error('No table specified. Use the table argument or --all option.');
                return 1;
            }
        }

        // Check if table exists
        if (!Schema::hasTable($table)) {
            $this->error("Table '{$table}' does not exist in the database.");
            return 1;
        }

        // Prepare API options
        $this->options = $this->prepareApiOptions();

        // Begin API generation process
        $this->info("Generating API components for table '{$table}'...");

        try {
            // Analyze table structure and relationships
            $this->info('Analyzing database structure...');
            $databaseAnalysis = $this->databaseAnalyzer->analyze($table);
            $databaseAnalysis = $this->databaseAnalyzer->getResults();


            $this->info('Analyzing relationships...');
            $this->relationshipAnalyzer->analyze($table);
            $relationships = $this->relationshipAnalyzer->getResults();

            // Merge analysis results with options
            $this->options = array_merge($this->options, [
                'database_analysis' => $databaseAnalysis,
                'relationship_analysis' => $relationships,
                'force' => $this->option('force'),
            ]);

            // Handle API versioning setup
            if ($this->option('versions')) {
                $versions = explode(',', $this->option('versions'));
                $this->handleMultipleVersions($versions);
            } else {
                // Generate API components for single version
                $this->generateApiComponents($table);
            }

            // Show summary of generated files
            $this->showGeneratedFiles();

            $this->info('API generation completed successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error during API generation: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Generate API controller for the specified table.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function generateApiController(string $table, string $version = 'v1')
    {
        $this->info("Generating API controller for '{$table}'...");

        $options = array_merge($this->options, [
            'generate_api_controller' => true,
            'api_version' => $version,
            'is_api' => true,
            'controller_type' => 'api',
            'include_documentation' => $this->option('swagger'),
            'response_format' => $this->option('format') ?? 'json',
        ]);

        // Add namespace prefix if specified
        if ($this->option('namespace')) {
            $options['controller_namespace'] = $this->option('namespace') . '\\Controllers\\Api';
        }

        $files = $this->controllerGenerator->generate($table, $options);

        foreach ($files as $file) {
            $this->generatedFiles['controllers'][] = $file;
        }

        return $files;
    }

    /**
     * Generate API resource for the specified table.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function generateApiResource(string $table, string $version = 'v1')
    {
        $this->info("Generating API resource for '{$table}'...");

        $options = array_merge($this->options, [
            'api_version' => $version,
            'include_meta' => true,
            'include_links' => true,
            'versioned' => !empty($version),
            'generate_collection' => $this->option('collection'),
            'response_format' => $this->option('format') ?? 'json',
        ]);

        // Add namespace prefix if specified
        if ($this->option('namespace')) {
            $options['resource_namespace'] = $this->option('namespace') . '\\Resources';
        }

        $files = $this->resourceGenerator->generate($table, $options);

        foreach ($files as $file) {
            $this->generatedFiles['resources'][] = $file;
        }

        return $files;
    }

    /**
     * Generate API documentation for the specified table.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function generateApiDocumentation(string $table, string $version = 'v1')
    {
        if (!$this->option('swagger') && !$this->option('documentation')) {
            return [];
        }

        $this->info("Generating API documentation for '{$table}'...");

        // Determine documentation output path
        $docsPath = config('crud.paths.documentation', base_path('docs/api'));

        // Create documentation directory if it doesn't exist
        if (!File::isDirectory($docsPath)) {
            File::makeDirectory($docsPath, 0755, true);
        }

        // Generate basic OpenAPI spec for this resource
        $modelName = Str::studly(Str::singular($table));
        $modelNamePlural = Str::plural($modelName);
        $routeName = Str::kebab($modelNamePlural);

        $apiPrefix = $this->option('prefix') ?? 'api';
        $version = $version ?? $this->option('version') ?? 'v1';

        $apiPath = "{$apiPrefix}/{$version}/{$routeName}";

        // Get table columns for documentation
        $columns = $this->databaseAnalyzer->getResults()['columns'] ?? [];

        // Create basic OpenAPI spec
        $docContent = $this->generateOpenApiSpec($table, $apiPath, $columns, $version);

        $docFile = "{$docsPath}/{$version}_{$routeName}.yaml";
        File::put($docFile, $docContent);

        $this->generatedFiles['documentation'][] = $docFile;

        return [$docFile];
    }

    /**
     * Set up API versioning options.
     *
     * @param array $options API options
     * @return array Updated options
     */
    protected function setupApiVersioning(array $options)
    {
        $version = $this->option('version') ?? 'v1';

        $apiVersioningOptions = [
            'api_version' => $version,
            'api_prefix' => $this->option('prefix') ?? 'api',
            'api_domain' => $this->option('domain') ?? null,
        ];

        // Configure route grouping for versioning
        if (!empty($version)) {
            $apiVersioningOptions['route_groups'] = [
                'prefix' => $apiVersioningOptions['api_prefix'] . '/' . $version,
                'middleware' => $this->option('middleware') ?? 'api',
            ];

            if (!empty($apiVersioningOptions['api_domain'])) {
                $apiVersioningOptions['route_groups']['domain'] = $apiVersioningOptions['api_domain'];
            }
        }

        return array_merge($options, $apiVersioningOptions);
    }

    /**
     * Configure authentication for API endpoints.
     *
     * @param array $options API options
     * @return array Updated options
     */
    protected function configureAuthentication(array $options)
    {
        $auth = $this->option('auth');

        if (empty($auth)) {
            return $options;
        }

        $authOptions = [
            'auth_type' => $auth,
            'middleware' => []
        ];

        switch ($auth) {
            case 'token':
                $authOptions['middleware'][] = 'auth:api';
                break;

            case 'sanctum':
                $authOptions['middleware'][] = 'auth:sanctum';
                break;

            case 'passport':
                $authOptions['middleware'][] = 'auth:api';
                // Add OAuth scopes if needed
                break;

            case 'jwt':
                $authOptions['middleware'][] = 'auth:api';
                // Add JWT specific configuration
                break;

            default:
                $this->warn("Unknown authentication type: {$auth}. Defaulting to token.");
                $authOptions['middleware'][] = 'auth:api';
        }

        // Add existing middleware if specified
        if ($this->option('middleware')) {
            $existingMiddleware = explode(',', $this->option('middleware'));
            $authOptions['middleware'] = array_merge($authOptions['middleware'], $existingMiddleware);
        }

        return array_merge($options, $authOptions);
    }

    /**
     * Set up rate limiting and caching for API endpoints.
     *
     * @param array $options API options
     * @return array Updated options
     */
    protected function setupRateLimitingAndCaching(array $options)
    {
        // Configure rate limiting if specified
        if ($this->option('rate-limit')) {
            $rateLimitParts = explode(',', $this->option('rate-limit'));

            if (count($rateLimitParts) == 2) {
                $maxRequests = (int)$rateLimitParts[0];
                $decayMinutes = (int)$rateLimitParts[1];

                $options['rate_limiting'] = [
                    'enabled' => true,
                    'max_requests' => $maxRequests,
                    'decay_minutes' => $decayMinutes
                ];

                // Add middleware for rate limiting
                $options['middleware'][] = "throttle:{$maxRequests},{$decayMinutes}";
            } else {
                $this->warn('Invalid rate limit format. Use: --rate-limit=60,1 (60 requests per 1 minute)');
            }
        }

        // Configure response caching if specified
        if ($this->option('cache')) {
            $cacheTtl = (int)$this->option('cache');

            $options['caching'] = [
                'enabled' => true,
                'ttl' => $cacheTtl
            ];
        }

        return $options;
    }

    /**
     * Generate API validation request classes.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function generateApiValidation(string $table, string $version = 'v1')
    {
        $this->info("Generating API validation requests for '{$table}'...");

        $options = array_merge($this->options, [
            'actions' => ['store', 'update'],
            'is_api' => true,
            'api_version' => $version,
            'validation_format' => $this->option('format') ?? 'json'
        ]);

        // Add namespace prefix if specified
        if ($this->option('namespace')) {
            $options['request_namespace'] = $this->option('namespace') . '\\Requests';
        }

        $files = $this->requestGenerator->generate($table, $options);

        foreach ($files as $file) {
            $this->generatedFiles['requests'][] = $file;
        }

        return $files;
    }

    /**
     * Create API response transformers.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function createApiResponseTransformers(string $table, string $version = 'v1')
    {
        if (!$this->option('transformer')) {
            return [];
        }

        $this->info("Generating API transformer for '{$table}'...");

        // Create a transformer directory if it doesn't exist
        $transformerPath = base_path(config('crud.paths.transformers', 'app/Transformers'));
        if (!File::isDirectory($transformerPath)) {
            File::makeDirectory($transformerPath, 0755, true);
        }

        // Generate a basic transformer
        $modelName = Str::studly(Str::singular($table));
        $transformerClass = "{$modelName}Transformer";
        $namespace = $this->option('namespace')
            ? $this->option('namespace') . '\\Transformers'
            : config('crud.namespaces.transformers', 'App\\Transformers');

        // Get columns for transformer
        $columns = $this->databaseAnalyzer->getResults()['columns'] ?? [];

        // Create transformer content
        $transformerContent = $this->generateTransformerContent($namespace, $transformerClass, $modelName, $columns);

        // Write the file
        $filePath = "{$transformerPath}/{$transformerClass}.php";
        File::put($filePath, $transformerContent);

        $this->generatedFiles['transformers'][] = $filePath;

        return [$filePath];
    }

    /**
     * Handle generating components for multiple API versions.
     *
     * @param array $versions List of API versions
     * @return void
     */
    protected function handleMultipleVersions(array $versions)
    {
        $table = $this->argument('table');

        foreach ($versions as $version) {
            $this->info("Generating API components for version: {$version}");

            // Store the current version in options
            $this->options['api_version'] = $version;

            // Generate components for this version
            $this->generateApiComponents($table, $version);
        }
    }

    /**
     * Generate all API components for a table.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return void
     */
    protected function generateApiComponents(string $table, string $version = null)
    {
        $version = $version ?? $this->option('version') ?? 'v1';

        // Update options with versioning setup
        $this->options = $this->setupApiVersioning($this->options);

        // Configure authentication
        $this->options = $this->configureAuthentication($this->options);

        // Configure rate limiting and caching
        $this->options = $this->setupRateLimitingAndCaching($this->options);

        // Generate only what's requested, or all components if no specific components requested
        $onlySpecific = $this->option('controller') || $this->option('resource') ||
            $this->option('documentation') || $this->option('transformer');

        if ($onlySpecific) {
            if ($this->option('controller')) {
                $this->generateApiController($table, $version);
            }

            if ($this->option('resource')) {
                $this->generateApiResource($table, $version);
            }

            if ($this->option('documentation') || $this->option('swagger')) {
                $this->generateApiDocumentation($table, $version);
            }

            if ($this->option('transformer')) {
                $this->createApiResponseTransformers($table, $version);
            }
        } else {
            // Generate all components
            $this->generateApiController($table, $version);
            $this->generateApiResource($table, $version);
            $this->generateApiValidation($table, $version);

            if ($this->option('swagger')) {
                $this->generateApiDocumentation($table, $version);
            }

            if (!$this->option('no-routes')) {
                // Generate API routes
                $routeOptions = array_merge($this->options, [
                    'generate_web_routes' => false,
                    'generate_api_routes' => true,
                    'route_type' => 'api',
                ]);

                $this->info("Generating API routes for '{$table}'...");
                $routeFiles = $this->routeGenerator->generate($table, $routeOptions);
                foreach ($routeFiles as $file) {
                    $this->generatedFiles['routes'][] = $file;
                }
            }
        }
    }

    /**
     * Ask the user to select a table from the database.
     *
     * @return string|null The selected table name
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

        // Filter out Laravel system tables
        $tableNames = array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations', 'failed_jobs', 'password_resets', 'personal_access_tokens']);
        });

        // Allow user to choose a table
        return $this->choice(
            'Select a database table to generate API for:',
            $tableNames
        );
    }

    /**
     * Prepare API options from command options.
     *
     * @return array API options
     */
    protected function prepareApiOptions()
    {
        $options = [];

        // Configure API versioning
        $options['api_version'] = $this->option('version') ?? 'v1';

        // Configure prefix
        $options['api_prefix'] = $this->option('prefix') ?? 'api';

        // Configure domain
        if ($this->option('domain')) {
            $options['api_domain'] = $this->option('domain');
        }

        // Configure response format
        $options['response_format'] = $this->option('format') ?? 'json';

        // Configure namespace
        if ($this->option('namespace')) {
            $options['namespace_prefix'] = $this->option('namespace');
        }

        // Configure force option
        $options['force'] = $this->option('force') ?? false;

        return $options;
    }

    /**
     * Process multiple tables for API generation.
     *
     * @param array $tables List of tables to process
     * @return int Command exit code
     */
    protected function processMultipleTables(array $tables)
    {
        $this->info("Batch processing " . count($tables) . " tables for API generation...");

        // Confirm before proceeding
        if (!$this->confirm("This will generate API components for " . count($tables) . " tables. Proceed?", true)) {
            $this->info("Operation cancelled.");
            return 0;
        }

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
                // Analyze table and generate components
                $this->databaseAnalyzer->analyze($tableName);
                $this->relationshipAnalyzer->analyze($tableName);

                // Generate API components
                $this->generateApiComponents($tableName);

                $successCount++;
            } catch (\Exception $e) {
                $this->error("\nError processing table {$tableName}: " . $e->getMessage());
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line(''); // Add newline after progress bar

        // Show summary
        $this->info("API generation completed: {$successCount} tables processed successfully, {$errorCount} errors.");

        // Show generated files
        $this->showGeneratedFiles();

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Generate OpenAPI specification content.
     *
     * @param string $table Table name
     * @param string $apiPath API path
     * @param array $columns Table columns
     * @param string $version API version
     * @return string OpenAPI spec content
     */
    protected function generateOpenApiSpec(string $table, string $apiPath, array $columns, string $version)
    {
        $modelName = Str::studly(Str::singular($table));

        // Start OpenAPI spec
        $spec = "openapi: 3.0.0\n";
        $spec .= "info:\n";
        $spec .= "  title: {$modelName} API\n";
        $spec .= "  version: {$version}\n";
        $spec .= "  description: API endpoints for {$modelName} resource\n\n";

        // Add paths
        $spec .= "paths:\n";

        // Collection endpoints
        $spec .= "  /{$apiPath}:\n";

        // GET (index)
        $spec .= "    get:\n";
        $spec .= "      summary: Get all {$modelName} resources\n";
        $spec .= "      tags:\n";
        $spec .= "        - {$modelName}\n";
        $spec .= "      parameters:\n";
        $spec .= "        - name: page\n";
        $spec .= "          in: query\n";
        $spec .= "          schema:\n";
        $spec .= "            type: integer\n";
        $spec .= "        - name: per_page\n";
        $spec .= "          in: query\n";
        $spec .= "          schema:\n";
        $spec .= "            type: integer\n";
        $spec .= "      responses:\n";
        $spec .= "        200:\n";
        $spec .= "          description: A list of {$modelName} resources\n";
        $spec .= "          content:\n";
        $spec .= "            application/json:\n";
        $spec .= "              schema:\n";
        $spec .= "                type: object\n";
        $spec .= "                properties:\n";
        $spec .= "                  data:\n";
        $spec .= "                    type: array\n";
        $spec .= "                    items:\n";
        $spec .= "                      \$ref: '#/components/schemas/{$modelName}'\n";

        // POST (store)
        $spec .= "    post:\n";
        $spec .= "      summary: Create a new {$modelName} resource\n";
        $spec .= "      tags:\n";
        $spec .= "        - {$modelName}\n";
        $spec .= "      requestBody:\n";
        $spec .= "        required: true\n";
        $spec .= "        content:\n";
        $spec .= "          application/json:\n";
        $spec .= "            schema:\n";
        $spec .= "              \$ref: '#/components/schemas/{$modelName}Input'\n";
        $spec .= "      responses:\n";
        $spec .= "        201:\n";
        $spec .= "          description: {$modelName} created successfully\n";
        $spec .= "          content:\n";
        $spec .= "            application/json:\n";
        $spec .= "              schema:\n";
        $spec .= "                type: object\n";
        $spec .= "                properties:\n";
        $spec .= "                  data:\n";
        $spec .= "                    \$ref: '#/components/schemas/{$modelName}'\n";

        // Individual resource endpoints
        $spec .= "  /{$apiPath}/{id}:\n";
        $spec .= "    parameters:\n";
        $spec .= "      - name: id\n";
        $spec .= "        in: path\n";
        $spec .= "        required: true\n";
        $spec .= "        schema:\n";
        $spec .= "          type: integer\n";

        // GET (show)
        $spec .= "    get:\n";
        $spec .= "      summary: Get a specific {$modelName} resource\n";
        $spec .= "      tags:\n";
        $spec .= "        - {$modelName}\n";
        $spec .= "      responses:\n";
        $spec .= "        200:\n";
        $spec .= "          description: {$modelName} resource\n";
        $spec .= "          content:\n";
        $spec .= "            application/json:\n";
        $spec .= "              schema:\n";
        $spec .= "                type: object\n";
        $spec .= "                properties:\n";
        $spec .= "                  data:\n";
        $spec .= "                    \$ref: '#/components/schemas/{$modelName}'\n";

        // PUT (update)
        $spec .= "    put:\n";
        $spec .= "      summary: Update a {$modelName} resource\n";
        $spec .= "      tags:\n";
        $spec .= "        - {$modelName}\n";
        $spec .= "      requestBody:\n";
        $spec .= "        required: true\n";
        $spec .= "        content:\n";
        $spec .= "          application/json:\n";
        $spec .= "            schema:\n";
        $spec .= "              \$ref: '#/components/schemas/{$modelName}Input'\n";
        $spec .= "      responses:\n";
        $spec .= "        200:\n";
        $spec .= "          description: {$modelName} updated successfully\n";
        $spec .= "          content:\n";
        $spec .= "            application/json:\n";
        $spec .= "              schema:\n";
        $spec .= "                type: object\n";
        $spec .= "                properties:\n";
        $spec .= "                  data:\n";
        $spec .= "                    \$ref: '#/components/schemas/{$modelName}'\n";

        // DELETE
        $spec .= "    delete:\n";
        $spec .= "      summary: Delete a {$modelName} resource\n";
        $spec .= "      tags:\n";
        $spec .= "        - {$modelName}\n";
        $spec .= "      responses:\n";
        $spec .= "        204:\n";
        $spec .= "          description: {$modelName} deleted successfully\n";

        // Define schemas
        $spec .= "components:\n";
        $spec .= "  schemas:\n";

        // Main model schema
        $spec .= "    {$modelName}:\n";
        $spec .= "      type: object\n";
        $spec .= "      properties:\n";

        foreach ($columns as $column) {
            $columnName = $column['name'] ?? $column['column_name'] ?? '';
            $columnType = $column['type'] ?? $column['data_type'] ?? 'string';

            if (!$columnName) continue;

            $openApiType = $this->mapColumnTypeToOpenApiType($columnType);
            $spec .= "        {$columnName}:\n";
            $spec .= "          type: {$openApiType}\n";
        }

        // Input model schema for POST/PUT
        $spec .= "    {$modelName}Input:\n";
        $spec .= "      type: object\n";
        $spec .= "      properties:\n";

        foreach ($columns as $column) {
            $columnName = $column['name'] ?? $column['column_name'] ?? '';
            $columnType = $column['type'] ?? $column['data_type'] ?? 'string';

            // Skip system columns
            if (!$columnName || in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $openApiType = $this->mapColumnTypeToOpenApiType($columnType);
            $spec .= "        {$columnName}:\n";
            $spec .= "          type: {$openApiType}\n";
        }

        return $spec;
    }

    /**
     * Map database column type to OpenAPI data type.
     *
     * @param string $columnType Database column type
     * @return string OpenAPI data type
     */
    protected function mapColumnTypeToOpenApiType(string $columnType)
    {
        $columnType = strtolower($columnType);

        if (strpos($columnType, 'int') !== false) {
            return 'integer';
        } elseif (
            strpos($columnType, 'decimal') !== false ||
            strpos($columnType, 'float') !== false ||
            strpos($columnType, 'double') !== false
        ) {
            return 'number';
        } elseif (strpos($columnType, 'bool') !== false) {
            return 'boolean';
        } elseif (
            strpos($columnType, 'json') !== false ||
            strpos($columnType, 'array') !== false
        ) {
            return 'object';
        } else {
            return 'string';
        }
    }

    /**
     * Generate API transformer content.
     *
     * @param string $namespace Transformer namespace
     * @param string $transformerClass Transformer class name
     * @param string $modelName Model name
     * @param array $columns Table columns
     * @return string Generated transformer code
     */
    protected function generateTransformerContent(string $namespace, string $transformerClass, string $modelName, array $columns)
    {
        $modelNamespace = config('crud.namespaces.models', 'App\\Models');

        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use League\\Fractal\\TransformerAbstract;\n";
        $content .= "use {$modelNamespace}\\{$modelName};\n\n";
        $content .= "/**\n";
        $content .= " * {$transformerClass}\n";
        $content .= " *\n";
        $content .= " * API response transformer for {$modelName} model\n";
        $content .= " */\n";
        $content .= "class {$transformerClass} extends TransformerAbstract\n";
        $content .= "{\n";
        $content .= "    /**\n";
        $content .= "     * List of available resources to include\n";
        $content .= "     *\n";
        $content .= "     * @var array\n";
        $content .= "     */\n";
        $content .= "    protected \$availableIncludes = [];\n\n";

        $content .= "    /**\n";
        $content .= "     * Transform a {$modelName} model\n";
        $content .= "     *\n";
        $content .= "     * @param {$modelName} \$model\n";
        $content .= "     * @return array\n";
        $content .= "     */\n";
        $content .= "    public function transform({$modelName} \$model)\n";
        $content .= "    {\n";
        $content .= "        return [\n";

        foreach ($columns as $column) {
            $columnName = $column['name'] ?? $column['column_name'] ?? '';
            if (!$columnName) continue;

            $content .= "            '{$columnName}' => \$model->{$columnName},\n";
        }

        $content .= "        ];\n";
        $content .= "    }\n";
        $content .= "}\n";

        return $content;
    }

    /**
     * Show a summary of all generated files.
     *
     * @return void
     */
    protected function showGeneratedFiles()
    {
        if (empty($this->generatedFiles)) {
            $this->info('No files were generated.');
            return;
        }

        $this->info("\nGenerated files:");

        foreach ($this->generatedFiles as $type => $files) {
            if (empty($files)) {
                continue;
            }

            $this->line("\n<comment>" . ucfirst($type) . ":</comment>");

            foreach ($files as $file) {
                $this->line("  - " . $file);
            }
        }
    }

    /**
     * Generate API tests for the specified table.
     *
     * @param string $table The database table name
     * @param string $version API version
     * @return array Files generated
     */
    protected function generateApiTests(string $table, string $version = 'v1')
    {
        if (!$this->option('with-tests')) {
            return [];
        }

        $this->info("Generating API tests for '{$table}'...");

        // Create tests directory if it doesn't exist
        $testsPath = base_path('tests/Feature/Api');
        if (!File::isDirectory($testsPath)) {
            File::makeDirectory($testsPath, 0755, true);
        }

        $modelName = Str::studly(Str::singular($table));
        $testClassName = "{$modelName}ApiTest";

        // Generate test file content
        $testFileContent = $this->generateApiTestContent($table, $modelName, $version);

        // Write the test file
        $filePath = "{$testsPath}/{$testClassName}.php";
        File::put($filePath, $testFileContent);

        $this->generatedFiles['tests'][] = $filePath;

        return [$filePath];
    }

    /**
     * Generate API test file content.
     *
     * @param string $table Table name
     * @param string $modelName Model name
     * @param string $version API version
     * @return string Test file content
     */
    protected function generateApiTestContent(string $table, string $modelName, string $version)
    {
        $pluralName = Str::plural($modelName);
        $apiPrefix = $this->option('prefix') ?? 'api';
        $routeName = Str::kebab($pluralName);
        $apiPath = "{$apiPrefix}/{$version}/{$routeName}";

        $content = "<?php\n\n";
        $content .= "namespace Tests\\Feature\\Api;\n\n";
        $content .= "use Tests\\TestCase;\n";
        $content .= "use App\\Models\\{$modelName};\n";
        $content .= "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n\n";

        $content .= "class {$modelName}ApiTest extends TestCase\n";
        $content .= "{\n";
        $content .= "    use RefreshDatabase;\n\n";

        // Setup method
        $content .= "    /**\n";
        $content .= "     * Setup the test environment.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    protected function setUp(): void\n";
        $content .= "    {\n";
        $content .= "        parent::setUp();\n";
        $content .= "        // Additional setup if needed\n";
        $content .= "    }\n\n";

        // Index endpoint test
        $content .= "    /**\n";
        $content .= "     * Test the index endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_can_get_all_{$table}()\n";
        $content .= "    {\n";
        $content .= "        // Create test data\n";
        $content .= "        {$modelName}::factory()->count(3)->create();\n\n";
        $content .= "        // Make request to the API\n";
        $content .= "        \$response = \$this->getJson('/{$apiPath}');\n\n";
        $content .= "        // Assert response\n";
        $content .= "        \$response->assertStatus(200)\n";
        $content .= "            ->assertJsonStructure([\n";
        $content .= "                'data' => [\n";
        $content .= "                    '*' => [\n";
        $content .= "                        'id',\n";
        $content .= "                        // Add other expected fields\n";
        $content .= "                    ]\n";
        $content .= "                ]\n";
        $content .= "            ]);\n";
        $content .= "    }\n\n";

        // Show endpoint test
        $content .= "    /**\n";
        $content .= "     * Test the show endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_can_get_single_{$table}_item()\n";
        $content .= "    {\n";
        $content .= "        // Create test data\n";
        $content .= "        \$item = {$modelName}::factory()->create();\n\n";
        $content .= "        // Make request to the API\n";
        $content .= "        \$response = \$this->getJson('/{$apiPath}/' . \$item->id);\n\n";
        $content .= "        // Assert response\n";
        $content .= "        \$response->assertStatus(200)\n";
        $content .= "            ->assertJson([\n";
        $content .= "                'data' => [\n";
        $content .= "                    'id' => \$item->id,\n";
        $content .= "                ]\n";
        $content .= "            ]);\n";
        $content .= "    }\n\n";

        // Store endpoint test
        $content .= "    /**\n";
        $content .= "     * Test the store endpoint.\n";
        $content .= "     *\n";
        $content .= "     * @return void\n";
        $content .= "     */\n";
        $content .= "    public function test_can_create_{$table}_item()\n";
        $content .= "    {\n";
        $content .= "        // Create test data\n";
        $content .= "        \$data = {$modelName}::factory()->make()->toArray();\n\n";
        $content .= "        // Make request to the API\n";
        $content .= "        \$response = \$this->postJson('/{$apiPath}', \$data);\n\n";
        $content .= "        // Assert response\n";
        $content .= "        \$response->assertStatus(201)\n";
        $content .= "            ->assertJsonStructure([\n";
        $content .= "                'data' => [\n";
        $content .= "                    'id',\n";
        $content .= "                    // Add other expected fields\n";
        $content .= "                ]\n";
        $content .= "            ]);\n\n";
        $content .= "        // Assert data was saved to database\n";
        $content .= "        \$this->assertDatabaseHas('{$table}', [\n";
        $content .= "            'id' => \$response->json('data.id'),\n";
        $content .= "        ]);\n";
        $content .= "    }\n";
        $content .= "}\n";

        return $content;
    }
}
