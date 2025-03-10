<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * JobGenerator
 *
 * This class is responsible for generating queue job classes for the application.
 * Queue jobs allow for deferred processing and can be dispatched to various queue
 * connections and channels for improved application performance and scalability.
 *
 * @package SwatTech\Crud\Generators
 */
class JobGenerator implements GeneratorInterface
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
     * Job configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new JobGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param ModelGenerator $modelGenerator
     */
    public function __construct(StringHelper $stringHelper, ModelGenerator $modelGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->modelGenerator = $modelGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.jobs', []);
    }

    /**
     * Generate job files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for job generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Default job actions to generate
        $actions = $this->options['actions'] ?? ['Process', 'Import', 'Export', 'Sync'];

        foreach ($actions as $action) {
            $this->generateJob($table, $action, $this->options);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the job.
     *
     * @param string $table The database table name
     * @param string $action The job action (Process, Import, etc.)
     * @return string The job class name
     */
    public function getClassName(string $table, string $action): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $action . $modelName . 'Job';
    }

    /**
     * Get the namespace for the job.
     *
     * @return string The job namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.jobs', 'App\\Jobs');
    }

    /**
     * Get the file path for the job.
     *
     * @return string The job file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.jobs', 'app/Jobs'));
    }

    /**
     * Get the stub template content for job generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path('stubs/crud/job.stub');

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . '/../stubs/job.stub');
    }

    /**
     * Generate a job file for the specified table and action.
     *
     * @param string $table The database table name
     * @param string $action The job action
     * @param array $options Options for job generation
     * @return string The generated file path
     */
    protected function generateJob(string $table, string $action, array $options): string
    {
        $className = $this->getClassName($table, $action);
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
     * Build the job class based on options.
     *
     * @param string $table The database table name
     * @param string $action The job action
     * @param array $options The options for job generation
     * @return string The generated job content
     */
    public function buildClass(string $table, string $action, array $options): string
    {
        $className = $this->getClassName($table, $action);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $modelVariable = Str::camel($modelClass);

        $stub = $this->getStub();

        // Implement ShouldQueue interface
        $shouldQueueImplementation = $this->implementShouldQueueInterface();

        // Set up queue name and connection
        $queueSetup = $this->setupQueueNameAndConnection($options);

        // Implement retry configuration
        $retryConfig = $this->implementRetryConfiguration($options);

        // Set up timeout settings
        $timeoutSettings = $this->setupTimeoutSettings($options);

        // Assign middleware
        $middleware = $this->assignMiddleware($options['middleware'] ?? []);

        // Set up batch handling
        $batchHandling = $this->setupBatchHandling($options);

        // Implement failure handling
        $failureHandling = $this->implementFailureHandling();

        // Set up progress tracking
        $progressTracking = $this->setupProgressTracking();

        // Create chained jobs
        $chainedJobs = $this->createChainedJobs($options['chained_jobs'] ?? []);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{actionName}}',
            '{{modelVariable}}',
            '{{shouldQueueImplementation}}',
            '{{queueSetup}}',
            '{{retryConfig}}',
            '{{timeoutSettings}}',
            '{{middleware}}',
            '{{batchHandling}}',
            '{{failureHandling}}',
            '{{progressTracking}}',
            '{{chainedJobs}}',
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $action,
            $modelVariable,
            $shouldQueueImplementation,
            $queueSetup,
            $retryConfig,
            $timeoutSettings,
            $middleware,
            $batchHandling,
            $failureHandling,
            $progressTracking,
            $chainedJobs,
        ], $stub);
    }

    /**
     * Implement ShouldQueue interface for the job.
     *
     * @return string The ShouldQueue interface implementation code
     */
    public function implementShouldQueueInterface(): string
    {
        return "use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;";
    }

    /**
     * Set up queue name and connection for the job.
     *
     * @param array $options Options for queue configuration
     * @return string The queue configuration code
     */
    public function setupQueueNameAndConnection(array $options): string
    {
        $queue = $options['queue'] ?? 'default';
        $connection = $options['connection'] ?? null;

        $code = "    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public \$queue = '{$queue}';";

        if ($connection) {
            $code .= "\n
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
     * Implement retry configuration for the job.
     *
     * @param array $options Options for retry configuration
     * @return string The retry configuration code
     */
    public function implementRetryConfiguration(array $options): string
    {
        $maxTries = $options['max_tries'] ?? 3;
        $retryAfter = $options['retry_after'] ?? 60;
        $backoff = $options['backoff'] ?? null;

        $code = "    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public \$tries = {$maxTries};";

        if ($backoff) {
            $code .= "\n
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
            $code .= "\n
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
     * Set up timeout settings for the job.
     *
     * @param array $options Options for timeout settings
     * @return string The timeout settings code
     */
    public function setupTimeoutSettings(array $options): string
    {
        $timeout = $options['timeout'] ?? 60;

        return "    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public \$timeout = {$timeout};";
    }

    /**
     * Assign middleware to the job.
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
            return "            new \\{$item}(),";
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
     * Set up batch handling for the job.
     *
     * @param array $options Options for batch handling
     * @return string The batch handling code
     */
    public function setupBatchHandling(array $options): string
    {
        if (!($options['use_batch'] ?? false)) {
            return '';
        }

        return "    /**
     * Get the batch ID associated with the job.
     *
     * @return string|null
     */
    public \$batchId;

    /**
     * Set the batch ID for the job.
     *
     * @param  string  \$batchId
     * @return \$this
     */
    public function withBatchId(\$batchId)
    {
        \$this->batchId = \$batchId;
        
        return \$this;
    }
    
    /**
     * Determine if the job should be batched.
     *
     * @return bool
     */
    public function batching()
    {
        return true;
    }";
    }

    /**
     * Implement failure handling for the job.
     *
     * @return string The failure handling code
     */
    public function implementFailureHandling(): string
    {
        return "    /**
     * Handle a job failure.
     *
     * @param  \\Throwable  \$exception
     * @return void
     */
    public function failed(\$exception)
    {
        // Log the failure
        \\Illuminate\\Support\\Facades\\Log::error('Job failed to process', [
            'job' => get_class(\$this),
            'model_id' => \$this->{{modelVariable}}->id ?? null,
            'exception' => \$exception->getMessage(),
            'trace' => \$exception->getTraceAsString(),
        ]);
        
        // Send notification about the failure if needed
        // \\Illuminate\\Support\\Facades\\Notification::route('mail', config('mail.admin_email'))
        //    ->notify(new \\App\\Notifications\\JobFailedNotification(get_class(\$this), \$exception));
    }";
    }

    /**
     * Set up progress tracking for the job.
     *
     * @return string The progress tracking code
     */
    public function setupProgressTracking(): string
    {
        return "    /**
     * Update the job progress.
     *
     * @param  int  \$progress
     * @param  int  \$total
     * @return void
     */
    protected function updateProgress(int \$progress, int \$total)
    {
        // Example: Update a progress record in the database
        // \\App\\Models\\JobProgress::updateOrCreate(
        //     ['job_id' => \$this->job->getJobId()],
        //     ['progress' => \$progress, 'total' => \$total]
        // );
        
        // Or dispatch an event for real-time progress updates
        // event(new \\App\\Events\\JobProgressUpdated(\$this->job->getJobId(), \$progress, \$total));
    }";
    }

    /**
     * Create chained jobs configuration.
     *
     * @param array $chainedJobs The chained jobs configuration
     * @return string The chained jobs code
     */
    public function createChainedJobs(array $chainedJobs): string
    {
        if (empty($chainedJobs)) {
            return '';
        }

        $jobClasses = array_map(function ($job) {
            return "            new \\{$job}(\$this->{{modelVariable}}),";
        }, $chainedJobs);

        return "    /**
     * Get the chain of jobs that should be run after this job.
     *
     * @return array
     */
    public function chain()
    {
        return [
" . implode("\n", $jobClasses) . "
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
