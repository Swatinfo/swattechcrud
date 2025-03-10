<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * ServiceGenerator
 *
 * This class is responsible for generating service classes for the application.
 * Services contain business logic and act as an intermediary layer between
 * controllers and repositories, following the service pattern.
 *
 * @package SwatTech\Crud\Generators
 */
class ServiceGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The repository generator instance.
     *
     * @var RepositoryGenerator|null
     */
    protected $repositoryGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Service configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ServiceGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param RepositoryGenerator|null $repositoryGenerator
     */
    public function __construct(StringHelper $stringHelper, ?RepositoryGenerator $repositoryGenerator = null)
    {
        $this->stringHelper = $stringHelper;
        $this->repositoryGenerator = $repositoryGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.services', []);
    }

    /**
     * Generate service files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for service generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Generate service implementation
        $this->generateImplementation($table, $this->options);

        // Generate service provider if enabled
        if ($this->options['generate_provider'] ?? false) {
            $this->generateServiceProvider($table);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the service.
     *
     * @param string $table The database table name
     * @return string The service class name
     */
    public function getClassName(string $table, string $action = ""): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Service';
    }

    /**
     * Get the namespace for the service.
     *
     * @return string The service namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.services', 'App\\Services');
    }

    /**
     * Get the file path for the service.
     *
     * @return string The service file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.services', 'app/Services'));
    }

    /**
     * Get the stub template content for service generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path("stubs/crud/service.stub");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/service.stub");
    }

    /**
     * Generate implementation of service.
     *
     * @param string $table The database table name
     * @param array $options Options for service generation
     * @return string The generated file path
     */
    protected function generateImplementation(string $table, array $options): string
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
     * Build the service class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for service generation
     * @return string The generated service content
     */
    public function buildClass(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $repositoryClass = $modelClass . 'Repository';
        $repositoryNamespace = Config::get('crud.namespaces.repositories', 'App\\Repositories');
        $repositoryInterfaceName = $repositoryClass . 'Interface';

        $stub = $this->getStub();

        // Setup repository injection
        $repositoryInjection = $this->setupRepositoryInjection("{$repositoryNamespace}\\{$repositoryInterfaceName}");

        // Generate business logic methods
        $businessMethods = $this->generateBusinessLogicMethods($options);

        // Setup validation handling
        $validation = $this->setupValidationHandling();

        // Setup transaction coordination
        $transactions = $this->setupTransactionCoordination();

        // Setup event dispatching
        $events = $this->setupEventDispatching();

        // Setup error handling
        $errorHandling = $this->setupErrorHandling();

        // Setup notification sending
        $notifications = $this->setupNotificationSending();

        // Generate logging functionality
        $logging = $this->generateLoggingFunctionality();

        // Setup cache management
        $caching = $this->setupCacheManagement();

        // Get required imports
        $imports = $this->getRequiredImports($options, $repositoryNamespace, $repositoryInterfaceName, $modelNamespace, $modelClass);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{modelClass}}',
            '{{repositoryInjection}}',
            '{{businessMethods}}',
            '{{validation}}',
            '{{transactions}}',
            '{{events}}',
            '{{errorHandling}}',
            '{{notifications}}',
            '{{logging}}',
            '{{caching}}',
        ], [
            $namespace,
            $imports,
            $className,
            $modelClass,
            $repositoryInjection,
            $businessMethods,
            $validation,
            $transactions,
            $events,
            $errorHandling,
            $notifications,
            $logging,
            $caching,
        ], $stub);
    }

    /**
     * Setup repository injection code.
     *
     * @param string $repositoryClass The fully qualified repository class name
     * @return string The repository injection code
     */
    public function setupRepositoryInjection(string $repositoryClass): string
    {
        return "    /**
     * The repository instance.
     *
     * @var \\{$repositoryClass}
     */
    protected \$repository;

    /**
     * Create a new service instance.
     *
     * @param \\{$repositoryClass} \$repository
     * @return void
     */
    public function __construct({$repositoryClass} \$repository)
    {
        \$this->repository = \$repository;
    }";
    }

    /**
     * Generate business logic methods implementation.
     *
     * @param array $options Options for method generation
     * @return string The business logic methods code
     */
    public function generateBusinessLogicMethods(array $options): string
    {
        return "    /**
     * Get all records with optional filtering and sorting.
     *
     * @param array \$filters
     * @param array \$sorts
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll(array \$filters = [], array \$sorts = [])
    {
        return \$this->repository->all(\$filters, \$sorts);
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
    public function getPaginated(int \$page = 1, int \$perPage = 15, array \$filters = [], array \$sorts = [])
    {
        return \$this->repository->paginate(\$page, \$perPage, \$filters, \$sorts);
    }

    /**
     * Find a record by ID.
     *
     * @param int \$id
     * @return \Illuminate\Database\Eloquent\Model|null
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findById(int \$id)
    {
        \$record = \$this->repository->find(\$id);
        
        if (!\\$record && \$this->options['throw_not_found'] ?? true) {
            throw new \\Illuminate\\Database\\Eloquent\\ModelNotFoundException('Record not found');
        }
        
        return \$record;
    }

    /**
     * Create a new record.
     *
     * @param array \$data
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array \$data)
    {
        \$this->validate(\$data);
        
        \$result = \$this->repository->transaction(function () use (\$data) {
            \$record = \$this->repository->create(\$data);
            
            \$this->processRelatedData(\$record, \$data);
            \$this->dispatchEvent('created', \$record);
            
            return \$record;
        });
        
        return \$result;
    }

    /**
     * Update an existing record.
     *
     * @param int \$id
     * @param array \$data
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int \$id, array \$data)
    {
        \$this->validate(\$data, \$id);
        
        \$record = \$this->findById(\$id);
        
        \$result = \$this->repository->transaction(function () use (\$id, \$data, \$record) {
            \$beforeUpdate = \$record->toArray();
            \$updated = \$this->repository->update(\$id, \$data);
            
            \$this->processRelatedData(\$updated, \$data);
            \$this->dispatchEvent('updated', \$updated, ['before' => \$beforeUpdate]);
            
            return \$updated;
        });
        
        return \$result;
    }

    /**
     * Delete a record by ID.
     *
     * @param int \$id
     * @return bool
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int \$id): bool
    {
        \$record = \$this->findById(\$id);
        
        \$result = \$this->repository->transaction(function () use (\$id, \$record) {
            \$beforeDelete = \$record->toArray();
            \$deleted = \$this->repository->delete(\$id);
            
            if (\$deleted) {
                \$this->dispatchEvent('deleted', \$record, ['before' => \$beforeDelete]);
            }
            
            return \$deleted;
        });
        
        return \$result;
    }

    /**
     * Process related data after record creation or update.
     *
     * @param \Illuminate\Database\Eloquent\Model \$record
     * @param array \$data
     * @return void
     */
    protected function processRelatedData(\$record, array \$data): void
    {
        // Handle relationships if provided in data
        // This is a placeholder for relationship handling logic
        // Implement based on specific relationship requirements
    }";
    }

    /**
     * Setup validation handling code.
     *
     * @return string The validation handling code
     */
    public function setupValidationHandling(): string
    {
        return "

    /**
     * Validate the given data.
     *
     * @param array \$data
     * @param int|null \$id ID for update validation rules
     * @return bool
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validate(array \$data, ?int \$id = null): bool
    {
        \$rules = \$this->getValidationRules(\$id);
        
        if (empty(\$rules)) {
            return true;
        }
        
        \$validator = \\Validator::make(\$data, \$rules, \$this->getValidationMessages());
        
        if (\$validator->fails()) {
            throw new \\Illuminate\\Validation\\ValidationException(\$validator);
        }
        
        return true;
    }
    
    /**
     * Get validation rules for the service.
     *
     * @param int|null \$id ID for update validation rules
     * @return array
     */
    protected function getValidationRules(?int \$id = null): array
    {
        \$rules = [];
        
        // Add common validation rules here
        
        // Add unique rules with ID exception for updates
        if (\$id) {
            // Modify rules for update scenario
        }
        
        return \$rules;
    }
    
    /**
     * Get custom validation messages.
     *
     * @return array
     */
    protected function getValidationMessages(): array
    {
        return [];
    }
    
    /**
     * Authorize the current user for an action.
     *
     * @param string \$ability
     * @param mixed \$model
     * @return bool
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorize(string \$ability, \$model = null): bool
    {
        if (\\Gate::denies(\$ability, \$model)) {
            throw new \\Illuminate\\Auth\\Access\\AuthorizationException('This action is unauthorized.');
        }
        
        return true;
    }";
    }

    /**
     * Setup transaction coordination code.
     *
     * @return string The transaction coordination code
     */
    public function setupTransactionCoordination(): string
    {
        return "

    /**
     * Execute a callable within a database transaction.
     *
     * @param callable \$callback
     * @return mixed
     */
    protected function transaction(callable \$callback)
    {
        return \$this->repository->transaction(\$callback);
    }";
    }

    /**
     * Setup event dispatching code.
     *
     * @return string The event dispatching code
     */
    public function setupEventDispatching(): string
    {
        return "

    /**
     * Dispatch an event.
     *
     * @param string \$action The action name (created, updated, deleted, etc.)
     * @param mixed \$model The model instance
     * @param array \$payload Additional payload data
     * @return void
     */
    protected function dispatchEvent(string \$action, \$model, array \$payload = []): void
    {
        // This should be implemented with specific event classes
        // Example: event(new ModelCreated(\$model));
        \$modelClass = get_class(\$model);
        \$modelName = class_basename(\$modelClass);
        \$eventClass = \"App\\\\Events\\\\{$modelName}{$action}\";
        
        if (class_exists(\$eventClass)) {
            event(new \$eventClass(\$model, \$payload));
        }
    }";
    }

    /**
     * Generate a service provider.
     *
     * @param string $table The database table name
     * @return string The generated file path
     */
    protected function generateServiceProvider(string $table): string
    {
        $namespace = Config::get('crud.namespaces.providers', 'App\\Providers');
        $className = 'ServiceServiceProvider';
        $servicesNamespace = $this->getNamespace();

        $stub = resource_path('stubs/crud/service_provider.stub');
        if (!Config::get('crud.stubs.use_custom', false) || !file_exists($stub)) {
            $stub = __DIR__ . '/../stubs/service_provider.stub';
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
        // Register service bindings
        // Add your service bindings here
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
                '{{servicesNamespace}}'
            ], [
                $namespace,
                $className,
                $servicesNamespace
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
     * Setup error handling code.
     *
     * @return string The error handling code
     */
    public function setupErrorHandling(): string
    {
        return "

    /**
     * Handle exceptions gracefully.
     *
     * @param \Closure \$callback
     * @return mixed
     * @throws \Throwable
     */
    protected function handleExceptions(\Closure \$callback)
    {
        try {
            return \$callback();
        } catch (\\Illuminate\\Database\\Eloquent\\ModelNotFoundException \$e) {
            \$this->log('error', 'Model not found: ' . \$e->getMessage());
            throw \$e;
        } catch (\\Illuminate\\Validation\\ValidationException \$e) {
            \$this->log('warning', 'Validation failed: ' . json_encode(\$e->errors()));
            throw \$e;
        } catch (\\Illuminate\\Auth\\Access\\AuthorizationException \$e) {
            \$this->log('warning', 'Authorization failed: ' . \$e->getMessage());
            throw \$e;
        } catch (\\Throwable \$e) {
            \$this->log('error', 'Unexpected error: ' . \$e->getMessage());
            throw \$e;
        }
    }";
    }

    /**
     * Setup notification sending code.
     *
     * @return string The notification sending code
     */
    public function setupNotificationSending(): string
    {
        return "

    /**
     * Send a notification to a notifiable entity.
     *
     * @param mixed \$notifiable The entity to receive the notification
     * @param mixed \$notification The notification instance
     * @return void
     */
    protected function sendNotification(\$notifiable, \$notification): void
    {
        if (\$notifiable && method_exists(\$notifiable, 'notify')) {
            \$notifiable->notify(\$notification);
        }
    }";
    }

    /**
     * Generate logging functionality code.
     *
     * @return string The logging functionality code
     */
    public function generateLoggingFunctionality(): string
    {
        return "

    /**
     * Log a message with the specified level.
     *
     * @param string \$level The log level (info, error, warning, etc.)
     * @param string \$message The log message
     * @param array \$context Additional context data
     * @return void
     */
    protected function log(string \$level, string \$message, array \$context = []): void
    {
        \\Log::{\$level}(\$message, \$context);
    }";
    }

    /**
     * Setup cache management code.
     *
     * @return string The cache management code
     */
    public function setupCacheManagement(): string
    {
        return "

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param string \$key The cache key
     * @param int \$ttl Cache time-to-live in minutes
     * @param \Closure \$callback The closure to execute if item doesn't exist
     * @return mixed
     */
    protected function remember(string \$key, int \$ttl, \Closure \$callback)
    {
        return \\Cache::remember(\$key, \$ttl, \$callback);
    }
    
    /**
     * Remove an item from the cache.
     *
     * @param string \$key The cache key
     * @return bool
     */
    protected function forgetCache(string \$key): bool
    {
        return \\Cache::forget(\$key);
    }
    
    /**
     * Clear cache for this service.
     *
     * @return bool
     */
    protected function clearCache(): bool
    {
        // Implement service-specific cache clearing logic
        return true;
    }";
    }

    /**
     * Get required imports for service class.
     *
     * @param array $options Options for service generation
     * @param string $repositoryNamespace Repository namespace
     * @param string $repositoryInterfaceName Repository interface name
     * @param string $modelNamespace Model namespace
     * @param string $modelClass Model class name
     * @return string The import statements
     */
    protected function getRequiredImports(
        array $options,
        string $repositoryNamespace,
        string $repositoryInterfaceName,
        string $modelNamespace,
        string $modelClass
    ): string {
        $imports = [
            'Illuminate\Database\Eloquent\Collection',
            'Illuminate\Pagination\LengthAwarePaginator',
            "{$repositoryNamespace}\\{$repositoryInterfaceName}",
            "{$modelNamespace}\\{$modelClass}",
        ];

        $imports = array_unique($imports);
        sort($imports);

        $importStatements = '';
        foreach ($imports as $import) {
            $importStatements .= "use {$import};\n";
        }

        return $importStatements;
    }
    /**
     * Set configuration options for the generator.
     *
     * @param array $options Configuration options
     * @return self Returns the generator instance for method chaining
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Get a list of all generated file paths.
     *
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
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
