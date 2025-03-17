<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * RepositoryGenerator
 *
 * This class is responsible for generating repository classes for the application.
 * Repositories follow the repository pattern and provide a clean abstraction layer
 * between data access logic and business logic.
 *
 * @package SwatTech\Crud\Generators
 */
class RepositoryGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Repository configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new RepositoryGenerator instance.
     *
     * @param StringHelper $stringHelper
     */
    public function __construct(StringHelper $stringHelper)
    {
        $this->stringHelper = $stringHelper;

        // Load default configuration options
        $this->options = Config::get('crud.repositories', []);
    }

    /**
     * Generate repository files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for repository generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Get model class information
        $modelClass = $this->getModelClass($table);
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');

        // Generate interface if enabled
        if ($this->options['generate_interface'] ?? true) {
            $this->generateInterface($table, $this->options);
        }

        // Generate implementation
        $this->generateImplementation($table, $this->options);

        // Generate service provider if enabled
        if ($this->options['generate_provider'] ?? false) {
            $this->generateRepositoryServiceProvider();
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the repository.
     *
     * @param string $table The database table name
     * @return string The repository class name
     */
    public function getClassName(string $table, string $action = ""): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Repository';
    }

    /**
     * Get the namespace for the repository.
     *
     * @return string The repository namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.repositories', 'App\\Repositories');
    }

    /**
     * Get the file path for the repository.
     *
     * @return string The repository file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.repositories', 'app/Repositories'));
    }

    /**
     * Get the stub template content for repository generation.
     *
     * @param string $type Repository type (interface or implementation)
     * @return string The stub template content
     */
    public function getStub(string $type = 'implementation'): string
    {
        $stubName = $type === 'interface' ? 'repository_interface' : 'repository';
        $customStubPath = resource_path("stubs/crud/{$stubName}.stub");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}.stub");
    }

    /**
     * Build the repository class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for repository generation
     * @return string The generated repository content
     */
    public function buildClass(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $interfaceName = $className . 'Interface';
        $namespace = $this->getNamespace();
        $modelClass = $this->getModelClass($table);
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub("implementation");

        // Setup model reference
        $modelReference = $this->setupModelReference("{$modelNamespace}\\{$modelClass}");

        // Generate CRUD methods
        $crudMethods = $this->generateCrudMethods();

        // Generate query scope methods
        $scopeMethods = $this->generateQueryScopeMethods($options['scopes'] ?? []);

        // Setup caching layer
        $caching = $this->setupCachingLayer($options['enable_caching'] ?? false);

        // Generate transaction methods
        $transactions = $this->generateTransactionMethods();

        // Generate pagination methods
        $pagination = $this->generatePaginationMethods();

        // Generate ordering methods
        $ordering = $this->generateOrderingMethods();

        // Generate filtering methods
        $filtering = $this->generateFilteringMethods();

        // Setup relationship loading
        $relationships = $this->setupRelationshipLoading();

        // Setup event dispatching
        $events = $this->setupEventDispatching();

        // Get required imports
        $imports = $this->getRequiredImports($options);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{implements}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{modelReference}}',
            '{{crudMethods}}',
            '{{scopeMethods}}',
            '{{caching}}',
            '{{transactions}}',
            '{{pagination}}',
            '{{ordering}}',
            '{{filtering}}',
            '{{relationships}}',
            '{{events}}',
        ], [
            $namespace,
            $imports,
            $className,
            $options['generate_interface'] ?? true ? " implements {$interfaceName}" : '',
            $modelNamespace,
            $modelClass,
            $modelReference,
            $crudMethods,
            $scopeMethods,
            $caching,
            $transactions,
            $pagination,
            $ordering,
            $filtering,
            $relationships,
            $events,
        ], $stub);
    }

    /**
     * Generate interface for repository.
     *
     * @param string $table The database table name
     * @param array $options Options for repository generation
     * @return string The generated file path
     */
    public function generateInterface(string $table, array $options): string
    {
        $interfaceName = $this->getClassName($table) . 'Interface';
        $namespace = $this->getNamespace();
        $modelClass = $this->getModelClass($table);
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub('interface');

        // Get method signatures
        $methodSignatures = $this->getInterfaceMethodSignatures();

        // Replace stub placeholders
        $content = str_replace([
            '{{namespace}}',
            '{{interface}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{methodSignatures}}'
        ], [
            $namespace,
            $interfaceName,
            $modelNamespace,
            $modelClass,
            $methodSignatures
        ], $stub);

        $filePath = $this->getPath() . '/' . $interfaceName . '.php';

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $content);

        $this->generatedFiles[] = $filePath;

        return $filePath;
    }

    /**
     * Generate implementation of repository.
     *
     * @param string $table The database table name
     * @param array $options Options for repository generation
     * @return string The generated file path
     */
    public function generateImplementation(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $content = $this->buildClass($table, $options);

        $filePath = $this->getPath() . '/' . $className . '.php';

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $content);

        $this->generatedFiles[] = $filePath;

        return $filePath;
    }

    /**
     * Setup model reference code.
     *
     * @param string $modelClass The fully qualified model class name
     * @return string The model reference code
     */
    public function setupModelReference(string $modelClass): string
    {
        return "    /**
            * The model class.
            *
            * @var string
            */
            protected \$modelClass = {$modelClass}::class;
            
            /**
             * The model instance.
             *
             * @var \\{$modelClass}
             */
            protected \$model;
            
            /**
             * Create a new repository instance.
             *
             * @param \\{$modelClass} \$model
             * @return void
             */
            public function __construct({$modelClass} \$model)
            {
                \$this->model = \$model;
            }
            
            /**
             * Get the model instance.
             *
             * @return \\{$modelClass}
             */
            public function getModel()
            {
                return \$this->model;
            }";
    }

    /**
     * Generate CRUD method implementations.
     *
     * @return string The CRUD methods code
     */
    public function generateCrudMethods(): string
    {
        return "    /**
            * Get all records with optional filtering and sorting.
            *
            * @param array \$filters
            * @param array \$sorts
            * @return \Illuminate\Database\Eloquent\Collection
            */
            public function all(array \$filters = [], array \$sorts = [])
            {
                \$query = \$this->model->newQuery();
                
                // Apply filters
                if (!empty(\$filters)) {
                    \$query = \$this->applyFilters(\$query, \$filters);
                }
                
                // Apply sorting
                if (!empty(\$sorts)) {
                    \$query = \$this->applySorting(\$query, \$sorts);
                }
                
                return \$query->get();
            }
            
            /**
             * Get paginated records with optional filtering and sorting.
             *
             * @param int \$page
             * @param int \$perPage
             * @param array \$filters
             * @param array \$sorts
             * @return \Illuminate\Pagination\LengthAwarePaginator
             */
            public function paginate(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = [])
            {
                \$query = \$this->model->newQuery();
                
                // Apply filters
                if (!empty(\$filters)) {
                    \$query = \$this->applyFilters(\$query, \$filters);
                }
                
                // Apply sorting
                if (!empty(\$sorts)) {
                    \$query = \$this->applySorting(\$query, \$sorts);
                }
                
                return \$query->paginate(\$perPage, ['*'], 'page', \$page);
            }
            
            /**
             * Find a record by ID.
             *
             * @param int \$id
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function find(int \$id)
            {
                return \$this->model->find(\$id);
            }
            
            /**
             * Find a record by a specific column and value.
             *
             * @param string \$column
             * @param mixed \$value
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function findBy(string \$column, \$value)
            {
                return \$this->model->where(\$column, \$value)->first();
            }
            
            /**
             * Create a new record.
             *
             * @param array \$data
             * @return \Illuminate\Database\Eloquent\Model
             */
            public function create(array \$data)
            {
                return \$this->model->create(\$data);
            }
            
            /**
             * Update an existing record.
             *
             * @param int \$id
             * @param array \$data
             * @return \Illuminate\Database\Eloquent\Model
             */
            public function update(int \$id, array \$data)
            {
                \$record = \$this->find(\$id);
                
                if (\$record) {
                    \$record->update(\$data);
                }
                
                return \$record;
            }
            
            /**
             * Delete a record by ID.
             *
             * @param int \$id
             * @return bool
             */
            public function delete(int \$id)
            {
                \$record = \$this->find(\$id);
                
                if (\$record) {
                    return \$record->delete();
                }
                
                return false;
            }";
    }

    /**
     * Generate query scope method implementations.
     *
     * @param array $scopes Scope definitions
     * @return string The query scope methods code
     */
    public function generateQueryScopeMethods(array $scopes): string
    {
        if (empty($scopes)) {
            return '';
        }

        $methods = "\n    /**
            * Apply scopes to the query.
            *
            * @param \Illuminate\Database\Eloquent\Builder \$query
            * @param array \$scopes
            * @return \Illuminate\Database\Eloquent\Builder
            */
            public function applyScopes(\$query, array \$scopes)
            {
                foreach (\$scopes as \$scope => \$parameters) {
                    if (method_exists(\$this->model, 'scope' . ucfirst(\$scope))) {
                        \$query = \$query->{\$scope}(...(array)\$parameters);
                    }
                }
                
                return \$query;
            }";

        // Add specific scope methods based on provided scopes
        foreach ($scopes as $scope => $parameters) {
            $methodName = 'get' . $this->stringHelper->studlyCase($scope);
            $parameters = is_array($parameters) ? implode(', ', $parameters) : $parameters;

            $methods .= "\n    
            /**
             * Get records using the {$scope} scope.
             *
             * @return \Illuminate\Database\Eloquent\Collection
             */
            public function {$methodName}()
            {
                return \$this->model->{$scope}({$parameters})->get();
            }";
        }

        return $methods;
    }

    /**
     * Setup caching layer for repository.
     *
     * @param bool $enableCaching Whether to enable caching
     * @return string The caching layer code
     */
    public function setupCachingLayer(bool $enableCaching): string
    {
        if (!$enableCaching) {
            return '';
        }

        return "\n    /**
            * Get the cache key for the given method and parameters.
            *
            * @param string \$method
            * @param array \$args
            * @return string
            */
            protected function buildCacheKey(string \$method, array \$args): string
            {
                \$key = get_class(\$this->model) . '|' . \$method . '|' . serialize(\$args);
                return md5(\$key);
            }
            
            /**
             * Get the cache lifetime in minutes.
             *
             * @return int
             */
            public function getCacheLifetime(): int
            {
                return config('crud.repositories.cache_lifetime', 60);
            }
            
            /**
             * Clear the cache for this repository.
             *
             * @return bool
             */
            public function clearCache(): bool
            {
                // You can implement cache tag clearing if using a cache driver that supports tags
                // Or implement a more specific cache clearing strategy
                return true;
            }";
    }

    /**
     * Generate transaction method implementations.
     *
     * @return string The transaction methods code
     */
    public function generateTransactionMethods(): string
    {
        return "\n    /**
            * Begin a new database transaction.
            *
            * @return void
            */
            protected function beginTransaction(): void
            {
                \\DB::beginTransaction();
            }
            
            /**
             * Commit the active database transaction.
             *
             * @return void
             */
            protected function commitTransaction(): void
            {
                \\DB::commit();
            }
            
            /**
             * Rollback the active database transaction.
             *
             * @return void
             */
            protected function rollbackTransaction(): void
            {
                \\DB::rollBack();
            }
            
            /**
             * Execute the given callback within a database transaction.
             *
             * @param callable \$callback
             * @return mixed
             * @throws \Throwable
             */
            public function transaction(callable \$callback)
            {
                \$this->beginTransaction();
                
                try {
                    \$result = \$callback();
                    \$this->commitTransaction();
                    
                    return \$result;
                } catch (\\Throwable \$e) {
                    \$this->rollbackTransaction();
                    throw \$e;
                }
            }";
    }

    /**
     * Generate pagination method implementations.
     *
     * @return string The pagination methods code
     */
    public function generatePaginationMethods(): string
    {
        return "\n    /**
            * Get a simple paginator instance.
            *
            * @param int \$page
            * @param int \$perPage
            * @param array \$filters
            * @param array \$sorts
            * @return \Illuminate\Pagination\Paginator
            */
            public function simplePaginate(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = [])
            {
                \$query = \$this->model->newQuery();
                
                if (!empty(\$filters)) {
                    \$query = \$this->applyFilters(\$query, \$filters);
                }
                
                if (!empty(\$sorts)) {
                    \$query = \$this->applySorting(\$query, \$sorts);
                }
                
                return \$query->simplePaginate(\$perPage, ['*'], 'page', \$page);
            }
            
            /**
             * Get a cursor paginator instance.
             *
             * @param int \$perPage
             * @param array \$filters
             * @param array \$sorts
             * @param string|null \$cursor
             * @return \Illuminate\Pagination\CursorPaginator
             */
            public function cursorPaginate(int \$perPage = 15, array \$filters = [], array \$sorts = [], ?string \$cursor = null)
            {
                \$query = \$this->model->newQuery();
                
                if (!empty(\$filters)) {
                    \$query = \$this->applyFilters(\$query, \$filters);
                }
                
                if (!empty(\$sorts)) {
                    \$query = \$this->applySorting(\$query, \$sorts);
                }
                
                return \$query->cursorPaginate(\$perPage, ['*'], 'cursor', \$cursor);
            }";
    }

    /**
     * Generate ordering method implementations.
     *
     * @return string The ordering methods code
     */
    public function generateOrderingMethods(): string
    {
        return "\n    /**
            * Apply sorting to a query.
            *
            * @param \Illuminate\Database\Eloquent\Builder \$query
            * @param array \$sorts
            * @return \Illuminate\Database\Eloquent\Builder
            */
            protected function applySorting(\$query, array \$sorts)
            {
                foreach (\$sorts as \$column => \$direction) {
                    // Handle relationship sorting
                    if (strpos(\$column, '.') !== false) {
                        \$this->addRelationshipSort(\$query, \$column, \$direction);
                        continue;
                    }
                    
                    // Validate direction
                    \$direction = strtolower(\$direction) === 'desc' ? 'desc' : 'asc';
                    
                    \$query->orderBy(\$column, \$direction);
                }
                
                return \$query;
            }
            
            /**
             * Add a sort for a relationship column.
             *
             * @param \Illuminate\Database\Eloquent\Builder \$query
             * @param string \$column The column in format 'relation.column'
             * @param string \$direction The sort direction ('asc' or 'desc')
             * @return \Illuminate\Database\Eloquent\Builder
             */
            protected function addRelationshipSort(\$query, string \$column, string \$direction)
            {
                list(\$relation, \$column) = explode('.', \$column, 2);
                
                // Validate direction
                \$direction = strtolower(\$direction) === 'desc' ? 'desc' : 'asc';
                
                return \$query->join(
                    \$relation,
                    \$this->model->getTable() . '.' . \$this->model->getForeignKey(),
                    '=',
                    \$relation . '.id'
                )->orderBy(\$relation . '.' . \$column, \$direction);
            }";
    }

    /**
     * Generate filtering method implementations.
     *
     * @return string The filtering methods code
     */
    public function generateFilteringMethods(): string
    {
        return "\n    /**
            * Apply filters to a query.
            *
            * @param \Illuminate\Database\Eloquent\Builder \$query
            * @param array \$filters
            * @return \Illuminate\Database\Eloquent\Builder
            */
            protected function applyFilters(\$query, array \$filters)
            {
                foreach (\$filters as \$field => \$value) {
                    // Handle relationship filtering
                    if (strpos(\$field, '.') !== false) {
                        \$this->addRelationshipFilter(\$query, \$field, \$value);
                        continue;
                    }
                    
                    // Handle operators
                    if (is_array(\$value) && isset(\$value['operator'])) {
                        \$operator = \$value['operator'];
                        \$filterValue = \$value['value'];
                        
                        \$this->addCondition(\$query, \$field, \$operator, \$filterValue);
                        continue;
                    }
                    
                    // Simple equals condition
                    \$query->where(\$field, \$value);
                }
                
                return \$query;
            }
            
            /**
             * Add a condition to a query.
             *
             * @param \Illuminate\Database\Eloquent\Builder \$query
             * @param string \$field
             * @param string \$operator
             * @param mixed \$value
             * @return \Illuminate\Database\Eloquent\Builder
             */
            protected function addCondition(\$query, string \$field, string \$operator, \$value)
            {
                // Map operator strings to actual SQL operators
                \$operatorMap = [
                    'eq' => '=',
                    'ne' => '!=',
                    'gt' => '>',
                    'lt' => '<',
                    'gte' => '>=',
                    'lte' => '<=',
                    'like' => 'LIKE',
                    'not_like' => 'NOT LIKE',
                    'in' => 'IN',
                    'not_in' => 'NOT IN',
                    'between' => 'BETWEEN',
                    'not_between' => 'NOT BETWEEN',
                    'null' => 'NULL',
                    'not_null' => 'NOT NULL',
                ];
                
                if (isset(\$operatorMap[\$operator])) {
                    \$sqlOperator = \$operatorMap[\$operator];
                    
                    if (\$sqlOperator === 'NULL') {
                        \$query->whereNull(\$field);
                    } elseif (\$sqlOperator === 'NOT NULL') {
                        \$query->whereNotNull(\$field);
                    } elseif (\$sqlOperator === 'IN') {
                        \$query->whereIn(\$field, (array) \$value);
                    } elseif (\$sqlOperator === 'NOT IN') {
                        \$query->whereNotIn(\$field, (array) \$value);
                    } elseif (\$sqlOperator === 'BETWEEN' || \$sqlOperator === 'NOT BETWEEN') {
                        \$betweenMethod = \$sqlOperator === 'BETWEEN' ? 'whereBetween' : 'whereNotBetween';
                        \$query->{\$betweenMethod}(\$field, (array) \$value);
                    } elseif (\$sqlOperator === 'LIKE' || \$sqlOperator === 'NOT LIKE') {
                        // Add wildcards if not already included
                        if (strpos(\$value, '%') === false) {
                            \$value = '%' . \$value . '%';
                        }
                        \$query->where(\$field, \$sqlOperator, \$value);
                    } else {
                        \$query->where(\$field, \$sqlOperator, \$value);
                    }
                } else {
                    // Default to equals
                    \$query->where(\$field, \$value);
                }
                
                return \$query;
            }
            
            /**
             * Add a relationship filter.
             *
             * @param \Illuminate\Database\Eloquent\Builder \$query
             * @param string \$field The field in format 'relation.column'
             * @param mixed \$value
             * @return \Illuminate\Database\Eloquent\Builder
             */
            protected function addRelationshipFilter(\$query, string \$field, \$value)
            {
                list(\$relation, \$column) = explode('.', \$field, 2);
                
                return \$query->whereHas(\$relation, function(\$q) use (\$column, \$value) {
                    if (is_array(\$value) && isset(\$value['operator'])) {
                        \$this->addCondition(\$q, \$column, \$value['operator'], \$value['value']);
                    } else {
                        \$q->where(\$column, \$value);
                    }
                });
            }";
    }

    /**
     * Setup relationship loading code.
     *
     * @return string The relationship loading code
     */
    public function setupRelationshipLoading(): string
    {
        return "\n    /**
            * Get all records with specified relationships.
            *
            * @param array \$relations
            * @return \Illuminate\Database\Eloquent\Builder
            */
            public function with(array \$relations)
            {
                return \$this->model->with(\$relations);
            }
            
            /**
             * Find a record by ID with specified relationships.
             *
             * @param int \$id
             * @param array \$relations
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function findWith(int \$id, array \$relations)
            {
                return \$this->model->with(\$relations)->find(\$id);
            }
            
            /**
             * Add eager load constraints to a query.
             *
             * @param \Illuminate\Database\Eloquent\Builder \$query
             * @param array \$relations
             * @return \Illuminate\Database\Eloquent\Builder
             */
            protected function eagerLoadRelations(\$query, array \$relations)
            {
                return \$query->with(\$relations);
            }";
    }

    /**
     * Setup event dispatching code.
     *
     * @return string The event dispatching code
     */
    public function setupEventDispatching(): string
    {
        return "\n    /**
     * Dispatch an event.
     *
     * @param string \$event
     * @param mixed \$data
     * @return void
     */
    protected function dispatchEvent(string \$event, \$data): void
    {
        // Dispatch event using Laravel's event system
        event(\$event, \$data);
    }";
    }

    /**
     * Get required imports for repository class.
     *
     * @param array $options Options for repository generation
     * @return string The import statements
     */
    protected function getRequiredImports(array $options): string
    {
        $imports = [
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Eloquent\Collection',
            'Illuminate\Pagination\LengthAwarePaginator'
        ];

        if ($options['generate_interface'] ?? true) {
            $imports[] = $this->getNamespace() . '\\' . $this->getClassName($options['table'] ?? '') . 'Interface';
        }

        $imports = array_unique($imports);
        sort($imports);

        $importStatements = '';
        foreach ($imports as $import) {
            $importStatements .= "use {$import};\n";
        }

        return $importStatements;
    }


    /**
     * Get the model class name for the table.
     *
     * @param string $table The database table name
     * @return string The model class name
     */
    protected function getModelClass(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    /**
     * Generate a repository service provider.
     *
     * @return string The generated file path
     */
    protected function generateRepositoryServiceProvider(): string
    {
        $namespace = Config::get('crud.namespaces.providers', 'App\\Providers');
        $className = 'RepositoryServiceProvider';
        $repositoriesNamespace = $this->getNamespace();

        $stub = resource_path('stubs/crud/repository_provider.stub');
        if (!Config::get('crud.stubs.use_custom', false) || !file_exists($stub)) {
            $stub = __DIR__ . '/../stubs/repository_provider.stub';
        }

        // If stub doesn't exist, create content directly
        if (!file_exists($stub)) {
            $content = "<?php

                namespace {$namespace};

                use Illuminate\Support\ServiceProvider;

                class {$className} extends ServiceProvider
                {
                    /**
                     * Register services.
                     *
                     * @return void
                     */
                    public function register()
                    {
                        // Register repository bindings
                        \$this->app->bind(
                            \\{$repositoriesNamespace}\\ExampleRepositoryInterface::class,
                            \\{$repositoriesNamespace}\\ExampleRepository::class
                        );
                        
                        // Add other repository bindings here
                    }

                    /**
                     * Bootstrap services.
                     *
                     * @return void
                     */
                    public function boot()
                    {
                        //
                    }
                }";
        } else {
            $content = file_get_contents($stub);
            $content = str_replace([
                '{{namespace}}',
                '{{class}}',
                '{{repositoriesNamespace}}'
            ], [
                $namespace,
                $className,
                $repositoriesNamespace
            ], $content);
        }

        $filePath = base_path('app/Providers/' . $className . '.php');

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $content);

        $this->generatedFiles[] = $filePath;

        return $filePath;
    }
    /**
     * Get interface method signatures for interface generation.
     *
     * @return string The method signatures
     */
    protected function getInterfaceMethodSignatures(): string
    {
        return "    /**
            * Get all records with optional filtering and sorting.
            *
            * @param array \$filters
            * @param array \$sorts
            * @return \Illuminate\Database\Eloquent\Collection
            */
            public function all(array \$filters = [], array \$sorts = []);
            
            /**
             * Get paginated records with optional filtering and sorting.
             *
             * @param int \$page
             * @param int \$perPage
             * @param array \$filters
             * @param array \$sorts
             * @return \Illuminate\Pagination\LengthAwarePaginator
             */
            public function paginate(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = []);
            
            /**
             * Find a record by ID.
             *
             * @param int \$id
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function find(int \$id);
            
            /**
             * Find a record by a specific column and value.
             *
             * @param string \$column
             * @param mixed \$value
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function findBy(string \$column, \$value);
            
            /**
             * Create a new record.
             *
             * @param array \$data
             * @return \Illuminate\Database\Eloquent\Model
             */
            public function create(array \$data);
            
            /**
             * Update an existing record.
             *
             * @param int \$id
             * @param array \$data
             * @return \Illuminate\Database\Eloquent\Model
             */
            public function update(int \$id, array \$data);
            
            /**
             * Delete a record by ID.
             *
             * @param int \$id
             * @return bool
             */
            public function delete(int \$id);
            
            /**
             * Get a simple paginator instance.
             *
             * @param int \$page
             * @param int \$perPage
             * @param array \$filters
             * @param array \$sorts
             * @return \Illuminate\Pagination\Paginator
             */
            public function simplePaginate(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = []);
            
            /**
             * Get a cursor paginator instance.
             *
             * @param int \$perPage
             * @param array \$filters
             * @param array \$sorts
             * @param string|null \$cursor
             * @return \Illuminate\Pagination\CursorPaginator
             */
            public function cursorPaginate(int \$perPage = 15, array \$filters = [], array \$sorts = [], ?string \$cursor = null);
            
            /**
             * Execute the given callback within a database transaction.
             *
             * @param callable \$callback
             * @return mixed
             * @throws \Throwable
             */
            public function transaction(callable \$callback);
            
            /**
             * Get all records with specified relationships.
             *
             * @param array \$relations
             * @return \Illuminate\Database\Eloquent\Builder
             */
            public function with(array \$relations);
            
            /**
             * Find a record by ID with specified relationships.
             *
             * @param int \$id
             * @param array \$relations
             * @return \Illuminate\Database\Eloquent\Model|null
             */
            public function findWith(int \$id, array \$relations);
            
            /**
             * Get the cache lifetime in minutes.
             *
             * @return int
             */
            public function getCacheLifetime(): int;
            
            /**
             * Clear the cache for this repository.
             *
             * @return bool
             */
            public function clearCache(): bool;";
    }

      /**
     * Set configuration options for the generator.
     *
     * @param array $options Configuration options
     * @return self Returns the generator instance for method chaining
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options ?? [], $options);
        return $this;
    }

    /**
     * Get a list of all generated file paths.
     *
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles ?? [];
    }

    /**
     * Determine if the generator supports customization.
     *
     * @return bool True if the generator supports customization
     */
    public function supportsCustomization(): bool
    {
        return true;
    }
}