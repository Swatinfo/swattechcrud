<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * ObserverGenerator
 *
 * This class is responsible for generating model observer classes for the application.
 * Model observers handle lifecycle events of Eloquent models such as creating, created,
 * updating, updated, deleting, deleted, etc., allowing for clean separation of concerns.
 *
 * @package SwatTech\Crud\Generators
 */
class ObserverGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The model generator instance.
     *
     * @var ModelGenerator
     */
    protected $modelGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Observer configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ObserverGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param ModelGenerator $modelGenerator
     */
    public function __construct(StringHelper $stringHelper, ModelGenerator $modelGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->modelGenerator = $modelGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.observers', []);
    }

    /**
     * Generate observer files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for observer generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Generate the observer
        $filePath = $this->generateObserver($table, $this->options);

        // Register the observer in a service provider if needed
        if ($this->options['register_observer'] ?? true) {
            $this->registerObserver($table);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the observer.
     *
     * @param string $table The database table name
     * @return string The observer class name
     */
    public function getClassName(string $table, string $action = ""): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Observer';
    }

    /**
     * Get the namespace for the observer.
     *
     * @return string The observer namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.observers', 'App\\Observers');
    }

    /**
     * Get the file path for the observer.
     *
     * @return string The observer file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.observers', 'app/Observers'));
    }

    /**
     * Get the stub template content for observer generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view): string
    {
        $customStubPath = resource_path('stubs/crud/observer.stub');

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . '/../stubs/observer.stub');
    }

    /**
     * Generate an observer file for the specified table.
     *
     * @param string $table The database table name
     * @param array $options Options for observer generation
     * @return string The generated file path
     */
    protected function generateObserver(string $table, array $options): string
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
     * Build the observer class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for observer generation
     * @return string The generated observer content
     */
    public function buildClass(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $modelVariable = Str::camel($modelClass);
        
        $stub = $this->getStub("");

        // Generate lifecycle methods
        $lifecycleMethods = $this->generateLifecycleMethods($modelClass, $modelVariable, $options);

        // Setup event dispatching
        $eventDispatching = $this->setupEventDispatching($modelClass, $options);

        // Setup cache invalidation
        $cacheInvalidation = $this->setupCacheInvalidation($modelClass, $options);

        // Implement activity logging
        $activityLogging = $this->implementActivityLogging($modelClass, $options);

        // Setup relationship handling
        $relationships = $options['relationships'] ?? [];
        $relationshipHandling = $this->setupRelationshipHandling($relationships);

        // Setup notification sending
        $notificationSending = $this->setupNotificationSending($modelClass, $options);

        // Generate validation methods
        $validationMethods = $this->generateValidationMethods($options);

        // Implement search indexing
        $searchIndexing = $this->implementSearchIndexing($options);

        // Setup audit logging
        $auditLogging = $this->setupAuditLogging($modelClass, $options);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{lifecycleMethods}}',
            '{{eventDispatching}}',
            '{{cacheInvalidation}}',
            '{{activityLogging}}',
            '{{relationshipHandling}}',
            '{{notificationSending}}',
            '{{validationMethods}}',
            '{{searchIndexing}}',
            '{{auditLogging}}',
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $lifecycleMethods,
            $eventDispatching,
            $cacheInvalidation,
            $activityLogging,
            $relationshipHandling,
            $notificationSending,
            $validationMethods,
            $searchIndexing,
            $auditLogging,
        ], $stub);
    }

    /**
     * Generate lifecycle methods for the observer.
     *
     * @param string $modelClass The model class name
     * @param string $modelVariable The model variable name
     * @param array $options Options for method generation
     * @return string The lifecycle methods code
     */
    public function generateLifecycleMethods(string $modelClass, string $modelVariable, array $options): string
    {
        $lifecycleEvents = [
            'retrieved' => "The model was retrieved from the database.",
            'creating' => "The model is about to be created.",
            'created' => "The model was newly created.",
            'updating' => "The model is about to be updated.",
            'updated' => "The model was updated.",
            'saving' => "The model is about to be saved.",
            'saved' => "The model was saved.",
            'deleting' => "The model is about to be deleted.",
            'deleted' => "The model was deleted.",
            'restoring' => "The model is about to be restored.",
            'restored' => "The model was restored.",
            'forceDeleting' => "The model is about to be force deleted.",
            'forceDeleted' => "The model was force deleted.",
        ];

        // Get selected lifecycle events to include
        $selectedEvents = $options['lifecycle_events'] ?? array_keys($lifecycleEvents);

        $code = '';
        foreach ($lifecycleEvents as $event => $description) {
            if (!in_array($event, $selectedEvents)) {
                continue;
            }

            $code .= "
    /**
     * Handle the {$modelClass} \"{$event}\" event.
     * {$description}
     *
     * @param  \\{$modelClass}  \${$modelVariable}
     * @return void
     */
    public function {$event}({$modelClass} \${$modelVariable})
    {
        // Implementation for {$event} event
    }
";
        }

        return $code;
    }

    /**
     * Setup event dispatching for the observer.
     *
     * @param string $modelClass The model class name
     * @param array $options Options for event dispatching
     * @return string The event dispatching code
     */
    public function setupEventDispatching(string $modelClass, array $options): string
    {
        if (!($options['dispatch_events'] ?? false)) {
            return '';
        }

        $events = $options['events'] ?? [
            'created' => "{$modelClass}Created",
            'updated' => "{$modelClass}Updated",
            'deleted' => "{$modelClass}Deleted",
        ];

        $code = "\n    /**
     * Dispatch custom events for model lifecycle.
     *
     * @param  string  \$event
     * @param  \\{$modelClass}  \$model
     * @return void
     */
    protected function dispatchEvent(string \$event, {$modelClass} \$model)
    {
        switch (\$event) {
";

        foreach ($events as $lifecycle => $eventClass) {
            $code .= "            case '{$lifecycle}':
                event(new \\App\\Events\\{$eventClass}(\$model));
                break;
";
        }

        $code .= "        }
    }";

        return $code;
    }

    /**
     * Setup cache invalidation for the observer.
     *
     * @param string $modelClass The model class name
     * @param array $options Options for cache invalidation
     * @return string The cache invalidation code
     */
    public function setupCacheInvalidation(string $modelClass, array $options): string
    {
        if (!($options['cache_invalidation'] ?? false)) {
            return '';
        }

        return "\n    /**
     * Invalidate cache after model changes.
     *
     * @param  \\{$modelClass}  \$model
     * @return void
     */
    protected function invalidateCache({$modelClass} \$model)
    {
        // Clear model cache
        \\Illuminate\\Support\\Facades\\Cache::forget('{$modelClass}:' . \$model->id);
        
        // Clear collection cache
        \\Illuminate\\Support\\Facades\\Cache::forget('{$modelClass}:all');
        
        // You might want to clear related caches as well
    }";
    }

    /**
     * Implement activity logging for the observer.
     *
     * @param string $modelClass The model class name
     * @param array $options Options for activity logging
     * @return string The activity logging code
     */
    public function implementActivityLogging(string $modelClass, array $options): string
    {
        if (!($options['activity_logging'] ?? false)) {
            return '';
        }

        return "\n    /**
     * Log activity for the model.
     *
     * @param  string  \$action
     * @param  \\{$modelClass}  \$model
     * @param  array|null  \$before
     * @return void
     */
    protected function logActivity(string \$action, {$modelClass} \$model, ?array \$before = null)
    {
        // Get the authenticated user
        \$user = auth()->user();
        
        // Create activity log
        \\App\\Models\\ActivityLog::create([
            'user_id' => \$user ? \$user->id : null,
            'action' => \$action,
            'subject_type' => get_class(\$model),
            'subject_id' => \$model->id,
            'properties' => [
                'attributes' => \$model->getAttributes(),
                'old' => \$before,
            ],
        ]);
    }";
    }

    /**
     * Setup relationship handling for the observer.
     *
     * @param array $relationships The relationships to handle
     * @return string The relationship handling code
     */
    public function setupRelationshipHandling(array $relationships): string
    {
        if (empty($relationships)) {
            return '';
        }

        $code = "\n    /**
     * Handle related models when main model is deleted.
     *
     * @param  \\Illuminate\\Database\\Eloquent\\Model  \$model
     * @return void
     */
    protected function handleRelatedModels(\$model)
    {
        // Handle cascade deletes or updates for related models
";

        foreach ($relationships as $type => $relations) {
            foreach ($relations as $relation) {
                $relationName = $relation['method'] ?? Str::camel($relation['related_table']);
                $action = $relation['on_delete'] ?? 'cascade';

                if ($action === 'cascade' && in_array($type, ['hasMany', 'hasOne'])) {
                    $code .= "        // Handle {$relationName} relationship ({$type})
        if (method_exists(\$model, '{$relationName}')) {
            \$model->{$relationName}()->delete();
        }
";
                } elseif ($action === 'set_null' && in_array($type, ['hasMany', 'hasOne'])) {
                    $foreignKey = $relation['foreign_key'] ?? null;
                    if ($foreignKey) {
                        $code .= "        // Set null for {$relationName} relationship ({$type})
        if (method_exists(\$model, '{$relationName}')) {
            \$model->{$relationName}()->update(['{$foreignKey}' => null]);
        }
";
                    }
                }
            }
        }

        $code .= "    }";
        return $code;
    }

    /**
     * Setup notification sending for the observer.
     *
     * @param string $modelClass The model class name
     * @param array $options Options for notification sending
     * @return string The notification sending code
     */
    public function setupNotificationSending(string $modelClass, array $options): string
    {
        if (!($options['send_notifications'] ?? false)) {
            return '';
        }

        $notifications = $options['notifications'] ?? [
            'created' => "{$modelClass}CreatedNotification",
            'updated' => "{$modelClass}UpdatedNotification",
            'deleted' => "{$modelClass}DeletedNotification",
        ];

        $code = "\n    /**
     * Send notifications for model events.
     *
     * @param  string  \$event
     * @param  \\{$modelClass}  \$model
     * @return void
     */
    protected function sendNotifications(string \$event, {$modelClass} \$model)
    {
        // Determine which users should receive the notification
        \$recipients = \$this->getNotificationRecipients(\$model);
        
        if (empty(\$recipients)) {
            return;
        }
        
        switch (\$event) {
";

        foreach ($notifications as $lifecycle => $notificationClass) {
            $code .= "            case '{$lifecycle}':
                \\Illuminate\\Support\\Facades\\Notification::send(
                    \$recipients, 
                    new \\App\\Notifications\\{$notificationClass}(\$model)
                );
                break;
";
        }

        $code .= "        }
    }
    
    /**
     * Get users who should receive notifications for this model.
     *
     * @param  \\{$modelClass}  \$model
     * @return \\Illuminate\\Database\\Eloquent\\Collection
     */
    protected function getNotificationRecipients({$modelClass} \$model)
    {
        // Implement logic to determine who should be notified
        // For example, return admins or users related to this model
        return \\App\\Models\\User::where('is_admin', true)->get();
    }";

        return $code;
    }

    /**
     * Generate validation methods for the observer.
     *
     * @param array $options Options for validation methods
     * @return string The validation methods code
     */
    public function generateValidationMethods(array $options): string
    {
        if (!($options['validate_in_observer'] ?? false)) {
            return '';
        }

        return "\n    /**
     * Validate the model data.
     *
     * @param  \\Illuminate\\Database\\Eloquent\\Model  \$model
     * @return void
     * @throws \\Illuminate\\Validation\\ValidationException
     */
    protected function validateModel(\$model)
    {
        \$rules = [
            // Define validation rules here or load them from a configuration
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . \$model->id,
            // Add more rules as needed
        ];
        
        \$validator = \\Illuminate\\Support\\Facades\\Validator::make(\$model->getAttributes(), \$rules);
        
        if (\$validator->fails()) {
            throw new \\Illuminate\\Validation\\ValidationException(\$validator);
        }
    }";
    }

    /**
     * Implement search indexing for the observer.
     *
     * @param array $options Options for search indexing
     * @return string The search indexing code
     */
    public function implementSearchIndexing(array $options): string
    {
        if (!($options['search_indexing'] ?? false)) {
            return '';
        }

        $driver = $options['search_driver'] ?? 'scout';

        if ($driver === 'scout') {
            return "\n    /**
     * Update the search index for the model.
     *
     * @param  \\Illuminate\\Database\\Eloquent\\Model  \$model
     * @return void
     */
    protected function updateSearchIndex(\$model)
    {
        // Laravel Scout handles indexing automatically
        // This method can be used for custom logic or other search drivers
    }";
        } else {
            return "\n    /**
     * Update the search index for the model.
     *
     * @param  \\Illuminate\\Database\\Eloquent\\Model  \$model
     * @return void
     */
    protected function updateSearchIndex(\$model)
    {
        // Implement custom search indexing logic here
        // Example for Elasticsearch:
        // \\App\\Services\\SearchService::index(\$model);
    }";
        }
    }

    /**
     * Setup audit logging for the observer.
     *
     * @param string $modelClass The model class name
     * @param array $options Options for audit logging
     * @return string The audit logging code
     */
    public function setupAuditLogging(string $modelClass, array $options): string
    {
        if (!($options['audit_logging'] ?? false)) {
            return '';
        }

        return "\n    /**
     * Record audit log for the model.
     *
     * @param  string  \$action
     * @param  \\{$modelClass}  \$model
     * @param  array|null  \$oldAttributes
     * @return void
     */
    protected function auditLog(string \$action, {$modelClass} \$model, ?array \$oldAttributes = null)
    {
        // Get the changes
        \$changes = [];
        
        if (\$action === 'updated' && \$oldAttributes) {
            foreach (\$model->getDirty() as \$key => \$value) {
                \$changes[\$key] = [
                    'old' => \$oldAttributes[\$key] ?? null,
                    'new' => \$value,
                ];
            }
        }
        
        // Record the audit
        \\App\\Models\\Audit::create([
            'user_id' => auth()->id(),
            'action' => \$action,
            'model_type' => get_class(\$model),
            'model_id' => \$model->id,
            'changes' => \$changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }";
    }

    /**
     * Register the observer in a service provider.
     *
     * @param string $table The database table name
     * @return void
     */
    protected function registerObserver(string $table): void
    {
        $modelClass = Str::studly(Str::singular($table));
        $observerClass = $this->getClassName($table);
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $observerNamespace = $this->getNamespace();

        // Content to add to ObserverServiceProvider or AppServiceProvider
        $registrationCode = "{$modelNamespace}\\{$modelClass}::observe({$observerNamespace}\\{$observerClass}::class);";

        // Determine the provider file
        $providerPath = base_path('app/Providers/ObserverServiceProvider.php');
        if (!file_exists($providerPath)) {
            $providerPath = base_path('app/Providers/AppServiceProvider.php');
        }

        if (file_exists($providerPath)) {
            $providerContent = file_get_contents($providerPath);

            // Check if the observer is already registered
            if (!str_contains($providerContent, $registrationCode)) {
                // Find the position to insert the code (after boot method opening)
                $bootMethodPosition = strpos($providerContent, 'public function boot');
                if ($bootMethodPosition !== false) {
                    $closingBracket = strpos($providerContent, '{', $bootMethodPosition);
                    if ($closingBracket !== false) {
                        // Insert the registration code after the boot method opening
                        $newContent = substr_replace(
                            $providerContent,
                            "{\n        {$registrationCode}\n",
                            $closingBracket,
                            1
                        );

                        file_put_contents($providerPath, $newContent);
                        $this->generatedFiles[] = $providerPath;
                    }
                }
            }
        }
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