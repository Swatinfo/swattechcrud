<?php

namespace SwatTech\Crud\Features\BatchOperations;

use SwatTech\Crud\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

/**
 * BatchManager
 *
 * A service class for managing batch operations with progress tracking,
 * error handling, transaction management, and notification support.
 * Can be used for large-scale data processing, imports, exports, and updates.
 *
 * @package SwatTech\Crud\Features\BatchOperations
 */
class BatchManager extends BaseService
{
    /**
     * The repository instance for data access.
     *
     * @var mixed
     */
    protected $repository;

    /**
     * Configuration for batch operations.
     *
     * @var array
     */
    protected $config;

    /**
     * Callback for tracking progress.
     *
     * @var callable|null
     */
    protected $progressCallback = null;

    /**
     * Error handling strategy.
     *
     * @var string
     */
    protected $errorStrategy = 'continue';

    /**
     * Transaction mode.
     *
     * @var string
     */
    protected $transactionMode = 'single';

    /**
     * Notification channels and recipients.
     *
     * @var array
     */
    protected $notifications = [];

    /**
     * Retry configuration.
     *
     * @var array|null
     */
    protected $retryConfig = null;

    /**
     * Rate limiting configuration.
     *
     * @var array|null
     */
    protected $rateLimitConfig = null;

    /**
     * Create a new BatchManager instance.
     *
     * @param mixed $repository Optional repository for data operations
     * @return void
     */
    public function __construct($repository = null)
    {
        $this->repository = $repository;
        
        $this->config = config('crud.features.batch', [
            'default_chunk_size' => 100,
            'enable_transactions' => true,
            'default_error_strategy' => 'continue', // continue, abort
            'enable_notifications' => true,
            'default_notification_channels' => ['mail', 'database'],
            'log_level' => 'info',
            'rate_limit' => [
                'enabled' => false,
                'max_requests' => 60,
                'decay_minutes' => 1
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'backoff' => [1, 5, 10] // seconds
            ],
            'parallelization' => [
                'enabled' => false,
                'processes' => 2
            ]
        ]);
    }

    /**
     * Process a batch operation on multiple items.
     *
     * @param string $operation The operation to perform on each item (create, update, delete, etc)
     * @param array $items The data items to process
     * @param array $options Additional options for batch processing
     * @return array Results of the batch operation with success/failure counts
     * 
     * @throws Exception If batch processing fails and error strategy is set to abort
     */
    public function processBatchJob(string $operation, array $items, array $options = [])
    {
        $items = Collection::make($items);
        $total = $items->count();
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $errors = [];
        
        // Apply options
        $chunkSize = $options['chunk_size'] ?? $this->config['default_chunk_size'];
        $errorStrategy = $options['error_strategy'] ?? $this->errorStrategy;
        $transactionMode = $options['transaction_mode'] ?? $this->transactionMode;
        
        // Start timing for performance metrics
        $startTime = microtime(true);
        
        try {
            // Use transaction if enabled and in "all" mode
            if ($this->config['enable_transactions'] && $transactionMode === 'all') {
                DB::beginTransaction();
            }
            
            // Process in chunks for better memory management
            foreach ($items->chunk($chunkSize) as $index => $chunk) {
                // Apply rate limiting if configured
                if ($this->rateLimitConfig && $this->rateLimitConfig['enabled']) {
                    $this->applyRateLimiting($operation);
                }
                
                // Use transaction for each chunk if in chunk mode
                if ($this->config['enable_transactions'] && $transactionMode === 'chunk') {
                    DB::beginTransaction();
                }
                
                try {
                    foreach ($chunk as $key => $item) {
                        try {
                            // Apply per-item transaction if in "single" mode
                            if ($this->config['enable_transactions'] && $transactionMode === 'single') {
                                DB::beginTransaction();
                            }
                            
                            // Process the item based on operation
                            $result = $this->processItem($operation, $item, $options);
                            
                            // Commit per-item transaction if successful
                            if ($this->config['enable_transactions'] && $transactionMode === 'single') {
                                DB::commit();
                            }
                            
                            $successful++;
                            
                        } catch (Exception $e) {
                            // Rollback per-item transaction
                            if ($this->config['enable_transactions'] && $transactionMode === 'single') {
                                DB::rollBack();
                            }
                            
                            $failed++;
                            $itemIndex = ($index * $chunkSize) + $key;
                            $errors[$itemIndex] = [
                                'item' => $item,
                                'message' => $e->getMessage(),
                                'exception' => get_class($e),
                                'trace' => $e->getTraceAsString()
                            ];
                            
                            // Handle errors based on strategy
                            if ($errorStrategy === 'abort') {
                                throw new Exception("Batch processing aborted due to error: {$e->getMessage()}", 0, $e);
                            }
                            
                            // Log the error
                            Log::error("Batch processing error for item {$itemIndex}", [
                                'operation' => $operation,
                                'error' => $e->getMessage(),
                                'item' => is_array($item) ? json_encode($item) : $item
                            ]);
                        }
                        
                        $processed++;
                        
                        // Update progress if callback is set
                        if ($this->progressCallback !== null) {
                            call_user_func($this->progressCallback, $total, $processed);
                        }
                    }
                    
                    // Commit chunk transaction if successful
                    if ($this->config['enable_transactions'] && $transactionMode === 'chunk') {
                        DB::commit();
                    }
                    
                } catch (Exception $e) {
                    // Rollback chunk transaction
                    if ($this->config['enable_transactions'] && $transactionMode === 'chunk') {
                        DB::rollBack();
                    }
                    
                    // Re-throw if using abort strategy
                    if ($errorStrategy === 'abort') {
                        throw $e;
                    }
                    
                    // Log the chunk error
                    Log::error("Batch processing error for chunk {$index}", [
                        'operation' => $operation,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Commit the overall transaction if in "all" mode
            if ($this->config['enable_transactions'] && $transactionMode === 'all') {
                DB::commit();
            }
            
            // Calculate time taken
            $timeTaken = microtime(true) - $startTime;
            
            // Log completion
            Log::info("Batch processing completed", [
                'operation' => $operation,
                'total' => $total,
                'successful' => $successful,
                'failed' => $failed,
                'time_taken' => round($timeTaken, 2) . 's'
            ]);
            
            // Send completion notification if configured
            if ($this->config['enable_notifications'] && !empty($this->notifications)) {
                $this->sendCompletionNotification($operation, $total, $successful, $failed, $errors);
            }
            
            // Return results
            return [
                'total' => $total,
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'errors' => $errors,
                'time_taken' => round($timeTaken, 2)
            ];
            
        } catch (Exception $e) {
            // Rollback the overall transaction if in "all" mode
            if ($this->config['enable_transactions'] && $transactionMode === 'all') {
                DB::rollBack();
            }
            
            Log::error("Batch processing failed", [
                'operation' => $operation,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Batch processing failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up progress tracking for batch operations.
     *
     * @param callable $callback Function to call with progress updates (takes total and processed counts)
     * @return $this
     */
    public function trackProgress(callable $callback)
    {
        $this->progressCallback = $callback;
        
        Log::debug("Batch progress tracking configured");
        
        return $this;
    }

    /**
     * Handle batch operation errors.
     *
     * @param array $errors The errors that occurred during processing
     * @param string $strategy Strategy for handling errors ('continue' or 'abort')
     * @return $this
     */
    public function handleErrors(array $errors, string $strategy = 'continue')
    {
        if (!in_array($strategy, ['continue', 'abort'])) {
            throw new Exception("Invalid error handling strategy: {$strategy}. Valid values are: continue, abort");
        }
        
        $this->errorStrategy = $strategy;
        
        // Process errors (log, store, etc.)
        if (!empty($errors)) {
            // Log a summary of errors
            Log::warning("Batch errors summary", [
                'count' => count($errors),
                'strategy' => $strategy
            ]);
            
            // Store detailed error information for later analysis if needed
            $errorLogId = Str::uuid()->toString();
            Cache::put("batch.errors.{$errorLogId}", $errors, now()->addDays(7));
            
            Log::info("Detailed error log saved", ['error_log_id' => $errorLogId]);
        }
        
        return $this;
    }

    /**
     * Set up partial completion management.
     *
     * @param array $options Options for partial completion
     * @return $this
     */
    public function managePartialCompletion(array $options = [])
    {
        $partialCompletionConfig = array_merge([
            'min_success_percentage' => 90,
            'store_failed_items' => true,
            'retry_failed' => false,
            'notification_on_partial' => true
        ], $options);
        
        $this->config['partial_completion'] = $partialCompletionConfig;
        
        Log::debug("Partial completion configuration set", $partialCompletionConfig);
        
        return $this;
    }

    /**
     * Set up transaction management for the batch operations.
     *
     * @param string $mode Transaction mode ('single', 'chunk', 'all', or 'none')
     * @return $this
     */
    public function setupTransactionManagement(string $mode = 'single')
    {
        $validModes = ['single', 'chunk', 'all', 'none'];
        
        if (!in_array($mode, $validModes)) {
            throw new Exception("Invalid transaction mode: {$mode}. Valid modes are: " . implode(', ', $validModes));
        }
        
        $this->transactionMode = $mode;
        $this->config['enable_transactions'] = ($mode !== 'none');
        
        Log::debug("Transaction management configured", ['mode' => $mode]);
        
        return $this;
    }

    /**
     * Configure notification settings for batch operations.
     *
     * @param array $channels Notification channels to use
     * @param mixed $recipients Recipients for notifications
     * @return $this
     */
    public function configureNotifications(array $channels = [], $recipients = null)
    {
        if (empty($channels)) {
            $channels = $this->config['default_notification_channels'];
        }
        
        $this->notifications = [
            'enabled' => true,
            'channels' => $channels,
            'recipients' => $recipients,
            'on_complete' => true,
            'on_error' => true,
            'include_details' => true
        ];
        
        Log::debug("Batch notifications configured", [
            'channels' => $channels,
            'has_recipients' => !is_null($recipients)
        ]);
        
        return $this;
    }

    /**
     * Schedule a batch job for future execution.
     *
     * @param Carbon $scheduledAt When to execute the batch job
     * @param string $operation The operation to perform
     * @param array $items The items to process
     * @param array $options Additional options
     * @return string Job ID for the scheduled batch
     */
    public function implementScheduling(Carbon $scheduledAt, string $operation, array $items, array $options = [])
    {
        if ($scheduledAt->isPast()) {
            throw new Exception("Cannot schedule a batch job in the past");
        }
        
        // Generate a unique job ID
        $jobId = (string) Str::uuid();
        
        // Store the job data
        $jobData = [
            'id' => $jobId,
            'operation' => $operation,
            'items' => $items,
            'options' => $options,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'created_at' => now(),
            'status' => 'scheduled'
        ];
        
        // Store in cache or database
        Cache::put("batch.scheduled.{$jobId}", $jobData, $scheduledAt->addDay());
        
        // If using a queue system, dispatch a delayed job
        if (class_exists('\App\Jobs\ProcessBatchJob')) {
            $job = (new \App\Jobs\ProcessBatchJob($jobId, $operation, $items, $options))
                ->delay($scheduledAt);
                
            dispatch($job);
            
            Log::info("Batch job scheduled", [
                'job_id' => $jobId,
                'operation' => $operation,
                'items_count' => count($items),
                'scheduled_at' => $scheduledAt->toDateTimeString()
            ]);
        } else {
            // Log that the job was scheduled but no processor class was found
            Log::warning("Batch job scheduled but ProcessBatchJob class was not found", [
                'job_id' => $jobId
            ]);
        }
        
        return $jobId;
    }

    /**
     * Set up parallel processing for batch operations.
     *
     * @param int $processes Number of parallel processes
     * @return $this
     */
    public function setupParallelization(int $processes = 2)
    {
        if ($processes < 1) {
            throw new Exception("Number of processes must be at least 1");
        }
        
        $this->config['parallelization'] = [
            'enabled' => true,
            'processes' => $processes
        ];
        
        Log::debug("Parallelization configured", ['processes' => $processes]);
        
        return $this;
    }

    /**
     * Configure rate limiting for batch operations.
     *
     * @param int $limit Maximum number of operations
     * @param string $period Time period for the limit (minute, hour, day)
     * @return $this
     */
    public function configureRateLimiting(int $limit, string $period = 'minute')
    {
        $validPeriods = ['second', 'minute', 'hour', 'day'];
        
        if (!in_array(strtolower($period), $validPeriods)) {
            throw new Exception("Invalid rate limiting period. Valid periods are: " . implode(', ', $validPeriods));
        }
        
        // Convert period to minutes for storage
        $decayMinutes = match(strtolower($period)) {
            'second' => 1/60,
            'minute' => 1,
            'hour' => 60,
            'day' => 1440,
            default => 1
        };
        
        $this->rateLimitConfig = [
            'enabled' => true,
            'max_requests' => $limit,
            'decay_minutes' => $decayMinutes,
            'key_prefix' => 'batch_rate_limit'
        ];
        
        Log::info("Rate limiting configured", [
            'limit' => $limit,
            'period' => $period
        ]);
        
        return $this;
    }

    /**
     * Set up automatic retry logic for failed operations.
     *
     * @param int $attempts Maximum number of retry attempts
     * @param int $backoff Base backoff time in seconds
     * @return $this
     */
    public function implementRetryLogic(int $attempts = 3, int $backoff = 5)
    {
        if ($attempts < 1) {
            throw new Exception("Retry attempts must be at least 1");
        }
        
        if ($backoff < 1) {
            throw new Exception("Backoff time must be at least 1 second");
        }
        
        // Calculate exponential backoff sequence
        $backoffSequence = [];
        for ($i = 0; $i < $attempts; $i++) {
            $backoffSequence[] = $backoff * pow(2, $i);
        }
        
        $this->retryConfig = [
            'enabled' => true,
            'max_attempts' => $attempts,
            'backoff' => $backoffSequence
        ];
        
        Log::debug("Retry logic configured", [
            'attempts' => $attempts,
            'backoff' => $backoffSequence
        ]);
        
        return $this;
    }

    /**
     * Process a single item based on the operation type.
     *
     * @param string $operation Operation type
     * @param mixed $item The item to process
     * @param array $options Processing options
     * @return mixed Result of the operation
     * 
     * @throws Exception If operation is not supported or fails
     */
    protected function processItem(string $operation, $item, array $options = [])
    {
        if (!$this->repository) {
            throw new Exception("Repository is required for item processing");
        }
        
        switch ($operation) {
            case 'create':
                return $this->repository->create($item);
                
            case 'update':
                if (!isset($item['id'])) {
                    throw new Exception("Item ID is required for update operation");
                }
                return $this->repository->update($item['id'], $item);
                
            case 'delete':
                if (is_array($item) && isset($item['id'])) {
                    return $this->repository->delete($item['id']);
                } else {
                    return $this->repository->delete($item);
                }
                
            case 'restore':
                if (is_array($item) && isset($item['id'])) {
                    return $this->repository->restore($item['id']);
                } else {
                    return $this->repository->restore($item);
                }
                
            case 'custom':
                if (!isset($options['callback']) || !is_callable($options['callback'])) {
                    throw new Exception("Custom callback is required for custom operation");
                }
                return call_user_func($options['callback'], $item);
                
            default:
                throw new Exception("Unsupported operation: {$operation}");
        }
    }

    /**
     * Apply rate limiting to batch operations.
     *
     * @param string $operation The current operation for rate limiting key
     * @return void
     * 
     * @throws Exception If rate limit is exceeded
     */
    protected function applyRateLimiting(string $operation)
    {
        if (!$this->rateLimitConfig || !$this->rateLimitConfig['enabled']) {
            return;
        }
        
        $key = "{$this->rateLimitConfig['key_prefix']}:{$operation}";
        
        if (RateLimiter::tooManyAttempts($key, $this->rateLimitConfig['max_requests'])) {
            $seconds = RateLimiter::availableIn($key);
            throw new Exception("Rate limit exceeded. Try again in {$seconds} seconds.");
        }
        
        RateLimiter::hit($key, $this->rateLimitConfig['decay_minutes'] * 60);
    }

    /**
     * Send completion notification for the batch process.
     *
     * @param string $operation The operation performed
     * @param int $total Total number of items
     * @param int $successful Number of successful operations
     * @param int $failed Number of failed operations
     * @param array $errors Detailed error information
     * @return void
     */
    protected function sendCompletionNotification(string $operation, int $total, int $successful, int $failed, array $errors)
    {
        if (empty($this->notifications) || !$this->notifications['enabled']) {
            return;
        }
        
        $recipients = $this->notifications['recipients'];
        if (!$recipients) {
            return;
        }
        
        $status = $failed === 0 ? 'success' : ($successful > 0 ? 'partial' : 'failed');
        
        // Skip notification if not configured for this status
        if ($status === 'success' && !($this->notifications['on_complete'] ?? true)) {
            return;
        }
        
        if ($status !== 'success' && !($this->notifications['on_error'] ?? true)) {
            return;
        }
        
        // This is a placeholder - you would typically use your application's notification system
        Log::info("Batch completion notification would be sent", [
            'operation' => $operation,
            'status' => $status,
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'channels' => $this->notifications['channels']
        ]);
    }

    /**
     * Get the status of a previously scheduled batch job.
     *
     * @param string $jobId The ID of the scheduled job
     * @return array|null The job status information or null if not found
     */
    public function getJobStatus(string $jobId)
    {
        $jobData = Cache::get("batch.scheduled.{$jobId}");
        
        if (!$jobData) {
            return null;
        }
        
        return [
            'id' => $jobId,
            'operation' => $jobData['operation'],
            'status' => $jobData['status'],
            'items_count' => count($jobData['items']),
            'scheduled_at' => $jobData['scheduled_at'],
            'started_at' => $jobData['started_at'] ?? null,
            'completed_at' => $jobData['completed_at'] ?? null,
            'progress' => $jobData['progress'] ?? 0,
            'results' => $jobData['results'] ?? null
        ];
    }
}