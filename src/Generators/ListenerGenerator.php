<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * ListenerGenerator
 *
 * This class is responsible for generating event listener classes for the application.
 * Listeners handle events that are dispatched throughout the application and perform
 * actions in response to those events.
 *
 * @package SwatTech\Crud\Generators
 */
class ListenerGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The event generator instance.
     *
     * @var EventGenerator
     */
    protected $eventGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Listener configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ListenerGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param EventGenerator $eventGenerator
     */
    public function __construct(StringHelper $stringHelper, EventGenerator $eventGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->eventGenerator = $eventGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.listeners', []);
    }

    /**
     * Generate listener files for the specified database table's events.
     *
     * @param string $table The database table name
     * @param array $options Options for listener generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Default event actions to listen for
        $events = $this->options['events'] ?? ['Created', 'Updated', 'Deleted'];

        // Process each event type
        foreach ($events as $event) {
            $this->generateListener($table, $event, $this->options);
        }

        // Generate event subscriber if requested
        if ($this->options['use_subscriber'] ?? false) {
            $this->generateEventSubscriber($table, $events, $this->options);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the listener.
     *
     * @param string $table The database table name
     * @param string $event The event name
     * @return string The listener class name
     */
    public function getClassName(string $table, string $event): string
    {
        $modelName = Str::studly(Str::singular($table));
        $eventName = $modelName . $event . 'Event';
        return "Handle{$eventName}";
    }

    /**
     * Get the namespace for the listener.
     *
     * @return string The listener namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.listeners', 'App\\Listeners');
    }

    /**
     * Get the file path for the listener.
     *
     * @return string The listener file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.listeners', 'app/Listeners'));
    }

    /**
     * Get the stub template content for listener generation.
     *
     * @param bool $queued Whether the listener should be queued
     * @return string The stub template content
     */
    public function getStub(string $filename): string
    {
        // $stubName = $queued ? 'queued_listener.stub' : 'listener.stub';
        $stubName = $filename;
        $subscriberStubName = 'event_subscriber.stub';

        if ($this->options['subscriber'] ?? false) {
            $stubName = $subscriberStubName;
        }

        $customStubPath = resource_path("stubs/crud/{$stubName}");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}");
    }

    /**
     * Generate a listener file for the specified table and event.
     *
     * @param string $table The database table name
     * @param string $event The event name
     * @param array $options Options for listener generation
     * @return string The generated file path
     */
    protected function generateListener(string $table, string $event, array $options): string
    {
        $className = $this->getClassName($table, $event);
        $queued = $options['queued'] ?? false;

        $content = $this->buildClass($table, $event, $options);

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
     * Generate an event subscriber file that handles multiple events.
     *
     * @param string $table The database table name
     * @param array $events The events to subscribe to
     * @param array $options Options for subscriber generation
     * @return string The generated file path
     */
    protected function generateEventSubscriber(string $table, array $events, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $className = "{$modelName}EventSubscriber";

        $subscriberEvents = [];
        foreach ($events as $event) {
            $eventClass = $this->eventGenerator->getClassName($table, $event);
            $subscriberEvents[$eventClass] = "handle{$event}";
        }

        $content = $this->buildSubscriberClass($table, $subscriberEvents, $options);

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
     * Build the listener class based on options.
     *
     * @param string $table The database table name
     * @param string $event The event name
     * @param array $options The options for listener generation
     * @return string The generated listener content
     */
    public function buildClass(string $table, string $event, array $options): string
    {
        $className = $this->getClassName($table, $event);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $eventClass = $this->eventGenerator->getClassName($table, $event);
        $eventNamespace = $this->eventGenerator->getNamespace();

        // Determine if listener should be queued
        $queued = $options['queued'] ?? false;

        // $stub = $this->getStub($queued);
        $stub = $this->getStub($queued ? 'queued_listener.stub' : 'listener.stub');

        // Set up queue specification
        $queueSpec = '';
        if ($queued) {
            $queueSpec = $this->setupQueueSpecification($options);
        }

        // Generate handle method
        $handleMethod = $this->generateHandleMethod($eventClass);

        // Set up failure handling
        $failureHandling = '';
        if ($queued) {
            $failureHandling = $this->implementFailureHandling();
        }

        // Set up retry configuration
        $retryConfig = '';
        if ($queued && ($options['retry'] ?? false)) {
            $retryConfig = $this->setupRetryConfiguration($options);
        }

        // Set up dependency injection
        $dependencyInjection = $this->setupDependencyInjection($options['dependencies'] ?? []);

        // Assign middleware
        $middleware = '';
        if ($queued && isset($options['middleware']) && !empty($options['middleware'])) {
            $middleware = $this->assignMiddleware($options['middleware']);
        }

        // Implement conditional processing
        $conditionalProcessing = $this->implementConditionalProcessing();

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{eventNamespace}}',
            '{{eventClass}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{queueSpecification}}',
            '{{handleMethod}}',
            '{{failureHandling}}',
            '{{retryConfiguration}}',
            '{{dependencyInjection}}',
            '{{middleware}}',
            '{{conditionalProcessing}}',
        ], [
            $namespace,
            $className,
            $eventNamespace,
            $eventClass,
            $modelNamespace,
            $modelClass,
            $queueSpec,
            $handleMethod,
            $failureHandling,
            $retryConfig,
            $dependencyInjection,
            $middleware,
            $conditionalProcessing,
        ], $stub);
    }

    /**
     * Build the event subscriber class.
     *
     * @param string $table The database table name
     * @param array $events The events to subscribe to
     * @param array $options The options for subscriber generation
     * @return string The generated subscriber content
     */
    protected function buildSubscriberClass(string $table, array $events, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $className = "{$modelName}EventSubscriber";
        $namespace = $this->getNamespace();
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $eventNamespace = $this->eventGenerator->getNamespace();

        $stub = file_get_contents(__DIR__ . "/../stubs/event_subscriber.stub");

        // Generate the subscriber methods
        $subscriberMethods = '';
        foreach ($events as $eventClass => $methodName) {
            $subscriberMethods .= $this->generateSubscriberMethod($eventClass, $methodName, $table);
        }

        // Generate the subscribe method with event mapping
        $subscribeMethod = $this->generateEventSubscriberOptions($events);

        // Set up dependency injection
        $dependencyInjection = $this->setupDependencyInjection($options['dependencies'] ?? []);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{eventNamespace}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{dependencyInjection}}',
            '{{subscriberMethods}}',
            '{{subscribeMethod}}',
        ], [
            $namespace,
            $className,
            $eventNamespace,
            $modelNamespace,
            $modelName,
            $dependencyInjection,
            $subscriberMethods,
            $subscribeMethod,
        ], $stub);
    }

    /**
     * Generate the handle method for the event.
     *
     * @param string $event The event class name
     * @return string The handle method code
     */
    public function generateHandleMethod(string $event): string
    {
        return "    /**
     * Handle the event.
     *
     * @param  \\{{eventNamespace}}\\{$event}  \$event
     * @return void
     */
    public function handle(\\{{eventNamespace}}\\{$event} \$event)
    {
        // Access the model via \$event->{{modelVariable}}
        \$model = \$event->{{modelVariable}};
        
        // Additional data can be accessed via \$event->data
        \$data = \$event->data;
        
        // Implement your event handling logic here
        \\Illuminate\\Support\\Facades\\Log::info('{$event} was handled for model #' . \$model->id);
        
        // Example: Send notification
        // \\Illuminate\\Support\\Facades\\Notification::send(\$recipients, new \\App\\Notifications\\{{modelClass}}Notification(\$model));
    }";
    }

    /**
     * Generate a method for the event subscriber.
     * 
     * @param string $eventClass The event class
     * @param string $methodName The method name to handle the event
     * @param string $table The database table name
     * @return string The subscriber method code
     */
    protected function generateSubscriberMethod(string $eventClass, string $methodName, string $table): string
    {
        $modelVariable = Str::camel(Str::singular($table));

        return "    /**
     * Handle {$eventClass} events.
     *
     * @param  \\{{eventNamespace}}\\{$eventClass}  \$event
     * @return void
     */
    public function {$methodName}(\\{{eventNamespace}}\\{$eventClass} \$event)
    {
        // Access the model via \$event->{$modelVariable}
        \$model = \$event->{$modelVariable};
        
        // Additional data can be accessed via \$event->data
        \$data = \$event->data;
        
        // Implement your event handling logic here
        \\Illuminate\\Support\\Facades\\Log::info('{$eventClass} was handled for model #' . \$model->id);
    }

";
    }

    /**
     * Set up queue specification for the listener.
     *
     * @param array $options Options for queuing
     * @return string The queue specification code
     */
    public function setupQueueSpecification(array $options): string
    {
        $queue = $options['queue'] ?? 'default';
        $connection = $options['connection'] ?? null;

        $code = "    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public \$queue = '{$queue}';";

        if ($connection) {
            $code .= "
    
    /**
     * The name of the connection the job should use.
     *
     * @var string|null
     */
    public \$connection = '{$connection}';";
        }

        return $code;
    }

    /**
     * Implement failure handling for queued listeners.
     *
     * @return string The failure handling code
     */
    public function implementFailureHandling(): string
    {
        return "    /**
     * Handle a job failure.
     *
     * @param  \\{{eventNamespace}}\\{{eventClass}}  \$event
     * @param  \\Throwable  \$exception
     * @return void
     */
    public function failed(\\{{eventNamespace}}\\{{eventClass}} \$event, \$exception)
    {
        // Log the failure
        \\Illuminate\\Support\\Facades\\Log::error('Listener failed to process event', [
            'event' => get_class(\$event),
            'model_id' => \$event->{{modelVariable}}->id,
            'exception' => \$exception->getMessage(),
            'trace' => \$exception->getTraceAsString(),
        ]);
        
        // Notify developers about the failure
        // \\Illuminate\\Support\\Facades\\Notification::route('mail', config('mail.admin_email'))
        //    ->notify(new \\App\\Notifications\\EventProcessingFailedNotification(get_class(\$event), \$exception));
    }";
    }

    /**
     * Set up retry configuration for queued listeners.
     *
     * @param array $options Options for retry configuration
     * @return string The retry configuration code
     */
    public function setupRetryConfiguration(array $options): string
    {
        $maxTries = $options['max_tries'] ?? 3;
        $retryAfter = $options['retry_after'] ?? 60;
        $backoff = $options['backoff'] ?? null;

        $code = "    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public \$tries = {$maxTries};
    
    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public \$timeout = 120;";

        if ($backoff) {
            $code .= "
    
    /**
     * Get the backoff strategy for the job.
     *
     * @return array
     */
    public function backoff()
    {
        return [" . implode(', ', $backoff) . "];
    }";
        } else {
            $code .= "
    
    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public \$retryAfter = {$retryAfter};";
        }

        return $code;
    }

    /**
     * Generate listener registration code.
     *
     * @return string The listener registration code
     */
    public function generateListenerRegistration(): string
    {
        return "// In App\\Providers\\EventServiceProvider
protected \$listen = [
    \\{{eventNamespace}}\\{{eventClass}}::class => [
        \\{{namespace}}\\{{class}}::class,
    ],
];";
    }

    /**
     * Set up dependency injection for the listener.
     *
     * @param array $dependencies The dependencies to inject
     * @return string The dependency injection code
     */
    public function setupDependencyInjection(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '';
        }

        $propertyDefinitions = '';
        $constructorParams = [];
        $constructorAssignments = [];

        foreach ($dependencies as $name => $type) {
            if (is_int($name)) {
                // If the key is numeric, use the value as both type and property name
                $type = $type;
                $name = $this->stringHelper->getVariableName($type);
            }

            $propertyDefinitions .= "    /**
     * The {$name} instance.
     *
     * @var \\{$type}
     */
    protected \${$name};

";
            $constructorParams[] = "\\{$type} \${$name}";
            $constructorAssignments[] = "        \$this->{$name} = \${$name};";
        }

        $constructorCode = "    /**
     * Create a new listener instance.
     *
     * @param  " . implode("\n     * @param  ", $constructorParams) . "
     * @return void
     */
    public function __construct(" . implode(', ', $constructorParams) . ")
    {
" . implode("\n", $constructorAssignments) . "
    }

";

        return $propertyDefinitions . $constructorCode;
    }

    /**
     * Assign middleware to the listener.
     *
     * @param array $middleware The middleware to assign
     * @return string The middleware assignment code
     */
    public function assignMiddleware(array $middleware): string
    {
        if (empty($middleware)) {
            return '';
        }

        $middlewareItems = array_map(function ($item) {
            return "        new \\{$item}(),";
        }, $middleware);

        return "    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware()
    {
        return [
" . implode("\n", $middlewareItems) . "
        ];
    }";
    }

    /**
     * Implement conditional processing for the listener.
     *
     * @return string The conditional processing code
     */
    public function implementConditionalProcessing(): string
    {
        return "    /**
     * Determine if this event should be handled.
     *
     * @param  \\{{eventNamespace}}\\{{eventClass}}  \$event
     * @return bool
     */
    private function shouldHandle(\\{{eventNamespace}}\\{{eventClass}} \$event)
    {
        // Example: only process if model has specific attribute
        // return !empty(\$event->{{modelVariable}}->some_attribute);
        
        // Example: only process during business hours
        // \$now = now();
        // return \$now->isWeekday() && \$now->hour >= 9 && \$now->hour < 17;
        
        return true;
    }";
    }

    /**
     * Generate event subscriber options.
     *
     * @param array $events The events to subscribe to
     * @return string The event subscriber options code
     */
    public function generateEventSubscriberOptions(array $events): string
    {
        $mappings = [];

        foreach ($events as $event => $method) {
            $mappings[] = "            \\{{eventNamespace}}\\{$event}::class => '{$method}',";
        }

        return "    /**
     * Register the listeners for the subscriber.
     *
     * @param  \\Illuminate\\Events\\Dispatcher  \$events
     * @return array
     */
    public function subscribe(\$events)
    {
        return [
" . implode("\n", $mappings) . "
        ];
    }";
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
