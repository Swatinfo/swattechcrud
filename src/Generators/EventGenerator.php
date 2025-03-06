<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * EventGenerator
 *
 * This class is responsible for generating event classes for the application.
 * Events can be used to decouple various aspects of your application, since a
 * single event can have multiple listeners that do not depend on each other.
 *
 * @package SwatTech\Crud\Generators
 */
class EventGenerator implements GeneratorInterface
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
     * Event configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new EventGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param ModelGenerator $modelGenerator
     */
    public function __construct(StringHelper $stringHelper, ModelGenerator $modelGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->modelGenerator = $modelGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.events', []);
    }

    /**
     * Generate event files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for event generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Default event actions to generate
        $actions = $this->options['actions'] ?? ['Created', 'Updated', 'Deleted'];

        foreach ($actions as $action) {
            $this->generateEvent($table, $action, $this->options);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the event.
     *
     * @param string $table The database table name
     * @param string $action The event action (Created, Updated, etc.)
     * @return string The event class name
     */
    public function getClassName(string $table, string $action): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . $action . 'Event';
    }

    /**
     * Get the namespace for the event.
     *
     * @return string The event namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.events', 'App\\Events');
    }

    /**
     * Get the file path for the event.
     *
     * @return string The event file path
     */
    public function getPath(): string
    {
        return base_path(Config::get('crud.paths.events', 'app/Events'));
    }

    /**
     * Get the stub template content for event generation.
     *
     * @param bool $shouldBroadcast Whether the event should broadcast
     * @return string The stub template content
     */
    public function getStub(bool $shouldBroadcast = false): string
    {
        $stubName = $shouldBroadcast ? 'broadcasting_event.stub' : 'event.stub';
        $customStubPath = resource_path("stubs/crud/{$stubName}");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}");
    }

    /**
     * Generate an event file for the specified table and action.
     *
     * @param string $table The database table name
     * @param string $action The event action (Created, Updated, etc.)
     * @param array $options Options for event generation
     * @return string The generated file path
     */
    protected function generateEvent(string $table, string $action, array $options): string
    {
        $className = $this->getClassName($table, $action);
        $shouldBroadcast = $options['broadcast_events'] ?? false;
        
        $content = $this->buildClass($table, $action, $options);

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
     * Build the event class based on options.
     *
     * @param string $table The database table name
     * @param string $action The event action (Created, Updated, etc.)
     * @param array $options The options for event generation
     * @return string The generated event content
     */
    public function buildClass(string $table, string $action, array $options): string
    {
        $className = $this->getClassName($table, $action);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        
        // Determine if event should broadcast
        $shouldBroadcast = $options['broadcast_events'] ?? false;
        $stub = $this->getStub($shouldBroadcast);

        // Set up broadcasting implementation
        $broadcastingCode = '';
        if ($shouldBroadcast) {
            $broadcastingCode = $this->implementShouldBroadcast($options);
        }

        // Set up data properties
        $dataProperties = $this->setupDataProperties($table, $action);

        // Generate serialization methods
        $serializationMethods = $this->generateSerializationMethods();

        // Set up broadcasting channels
        $broadcastingChannel = '';
        if ($shouldBroadcast) {
            $broadcastingChannel = $this->implementBroadcastingChannel($options);
        }

        // Generate event constructor
        $constructorCode = $this->generateEventConstructor($table);

        // Set up queue specification
        $queueSpec = $this->setupQueueSpecification($options);

        // Customize payload for broadcasting
        $payloadCustomization = '';
        if ($shouldBroadcast) {
            $payloadCustomization = $this->customizePayload($options);
        }

        // Implement event interfaces
        $interfaces = $shouldBroadcast ? ['ShouldBroadcast'] : [];
        if ($options['should_queue'] ?? false) {
            $interfaces[] = 'ShouldQueue';
        }
        $interfaceImplementation = $this->implementEventInterfaces($interfaces);

        // Event dispatching setup
        $eventDispatching = $this->setupEventDispatching();

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{interfaceImplementation}}',
            '{{broadcastingImplementation}}',
            '{{dataProperties}}',
            '{{serialization}}',
            '{{broadcastingChannel}}',
            '{{constructor}}',
            '{{queueSpecification}}',
            '{{payloadCustomization}}',
            '{{eventDispatching}}',
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $interfaceImplementation,
            $broadcastingCode,
            $dataProperties,
            $serializationMethods,
            $broadcastingChannel,
            $constructorCode,
            $queueSpec,
            $payloadCustomization,
            $eventDispatching,
        ], $stub);
    }

    /**
     * Implement ShouldBroadcast interface for the event.
     *
     * @param array $options Options for broadcasting
     * @return string The broadcasting implementation code
     */
    public function implementShouldBroadcast(array $options): string
    {
        $privateChannel = $options['private_channel'] ?? false;
        $presenceChannel = $options['presence_channel'] ?? false;
        $useQueue = $options['queue_broadcasts'] ?? false;
        
        $imports = [
            'use Illuminate\Broadcasting\InteractsWithSockets;',
            'use Illuminate\Broadcasting\PrivateChannel;',
            'use Illuminate\Contracts\Broadcasting\ShouldBroadcast;',
            'use Illuminate\Foundation\Events\Dispatchable;',
            'use Illuminate\Queue\SerializesModels;',
        ];

        if ($presenceChannel) {
            $imports[] = 'use Illuminate\Broadcasting\PresenceChannel;';
        }

        if ($useQueue) {
            $imports[] = 'use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;';
        }

        return implode("\n", $imports);
    }

    /**
     * Set up data properties for the event.
     *
     * @param string $table The database table name
     * @param string $action The event action
     * @return string The data properties code
     */
    public function setupDataProperties(string $table, string $action): string
    {
        $modelClass = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelClass);

        return "    /**
     * The {$modelClass} instance.
     *
     * @var \\{{modelNamespace}}\\{$modelClass}
     */
    public \${$modelVariable};

    /**
     * Additional data for the event.
     *
     * @var array
     */
    public \$data;";
    }

    /**
     * Generate serialization methods for the event.
     *
     * @return string The serialization methods code
     */
    public function generateSerializationMethods(): string
    {
        return "    /**
     * Get the channels the event should broadcast on.
     *
     * @return array|\Illuminate\Broadcasting\Channel|string
     */
    public function broadcastOn()
    {
        return \$this->getBroadcastChannel();
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return class_basename(\$this);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return \$this->getEventData();
    }

    /**
     * Get the event data for serialization.
     *
     * @return array
     */
    protected function getEventData(): array
    {
        return [
            'id' => \$this->model->id,
            'data' => \$this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }";
    }

    /**
     * Implement broadcasting channel for the event.
     *
     * @param array $options Options for broadcasting
     * @return string The broadcasting channel code
     */
    public function implementBroadcastingChannel(array $options): string
    {
        $privateChannel = $options['private_channel'] ?? false;
        $presenceChannel = $options['presence_channel'] ?? false;
        $channelName = $options['channel_name'] ?? '{{modelClass}}';
        
        $code = "    /**
     * Get the broadcasting channel for this event.
     *
     * @return \\Illuminate\\Broadcasting\\Channel|array
     */
    protected function getBroadcastChannel()
    {";
        
        if ($presenceChannel) {
            $code .= "
        return new PresenceChannel('{$channelName}.' . \$this->model->id);";
        } elseif ($privateChannel) {
            $code .= "
        return new PrivateChannel('{$channelName}.' . \$this->model->id);";
        } else {
            $code .= "
        return ['{$channelName}', '{$channelName}.' . \$this->model->id];";
        }
        
        $code .= "
    }";
        
        return $code;
    }

    /**
     * Generate event constructor.
     *
     * @param string $table The database table name
     * @return string The constructor code
     */
    public function generateEventConstructor(string $table): string
    {
        $modelClass = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelClass);

        return "    /**
     * Create a new event instance.
     *
     * @param  \\{{modelNamespace}}\\{$modelClass}  \${$modelVariable}
     * @param  array  \$data
     * @return void
     */
    public function __construct(\\{{modelNamespace}}\\{$modelClass} \${$modelVariable}, array \$data = [])
    {
        \$this->{$modelVariable} = \${$modelVariable};
        \$this->data = \$data;
    }";
    }

    /**
     * Set up queue specification for the event.
     *
     * @param array $options Options for queuing
     * @return string The queue specification code
     */
    public function setupQueueSpecification(array $options): string
    {
        if (!($options['should_queue'] ?? false)) {
            return '';
        }

        $queue = $options['queue'] ?? 'default';
        $connection = $options['connection'] ?? 'default';
        
        return "
    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public \$queue = '{$queue}';

    /**
     * The name of the connection the job should be sent to.
     *
     * @var string|null
     */
    public \$connection = '{$connection}';";
    }

    /**
     * Customize payload for broadcasting.
     *
     * @param array $options Options for payload customization
     * @return string The payload customization code
     */
    public function customizePayload(array $options): string
    {
        if (!($options['customize_payload'] ?? false)) {
            return '';
        }

        return "    /**
     * Customize the broadcast payload.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'id' => \$this->model->id,
            'type' => class_basename(\$this->model),
            'timestamp' => now()->toIso8601String(),
            'user_id' => auth()->id(),
            // Add more custom fields here
        ];
    }";
    }

    /**
     * Implement event interfaces.
     *
     * @param array $interfaces The interfaces to implement
     * @return string The interface implementation code
     */
    public function implementEventInterfaces(array $interfaces): string
    {
        if (empty($interfaces)) {
            return '';
        }

        $traitsToUse = [
            'use Dispatchable, InteractsWithSockets, SerializesModels;'
        ];

        $implementsString = 'implements ' . implode(', ', $interfaces);
        
        return $implementsString . "\n{
    " . implode("\n    ", $traitsToUse);
    }

    /**
     * Set up event dispatching methods.
     *
     * @return string The event dispatching code
     */
    public function setupEventDispatching(): string
    {
        return "    /**
     * Dispatch the event with the given arguments.
     *
     * @param  \\{{modelNamespace}}\\{{modelClass}}  \$model
     * @param  array  \$data
     * @return void
     */
    public static function dispatch{{modelClass}}(\\{{modelNamespace}}\\{{modelClass}} \$model, array \$data = [])
    {
        event(new static(\$model, \$data));
    }";
    }
}