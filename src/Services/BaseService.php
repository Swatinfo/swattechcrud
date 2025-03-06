<?php

namespace SwatTech\Crud\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * BaseService
 *
 * This class provides a base service implementation for business logic
 * that sits between repositories and controllers. It handles common
 * functionality like validation, authorization, transactions, logging,
 * and event dispatching.
 *
 * @package SwatTech\Crud\Services
 */
class BaseService
{
    /**
     * The repository instance.
     *
     * @var RepositoryInterface
     */
    protected $repository;

    /**
     * The validation rules.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * Whether to use caching.
     *
     * @var bool
     */
    protected $useCache = true;

    /**
     * Cache lifetime in minutes.
     *
     * @var int|null
     */
    protected $cacheLifetime = 60; // 1 hour

    /**
     * Cache key prefix.
     *
     * @var string
     */
    protected $cachePrefix = 'service_';

    /**
     * Create a new service instance.
     *
     * @param RepositoryInterface $repository The repository instance
     */
    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get all records with optional filtering and sorting.
     *
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return Collection A collection of model instances
     */
    public function getAll(array $filters = [], array $sorts = []): Collection
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($filters, $sorts) {
            return $this->repository->all($filters, $sorts);
        });
    }

    /**
     * Get paginated results with optional filtering and sorting.
     *
     * @param int $page The page number to retrieve (starting from 1)
     * @param int $perPage The number of records per page
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return LengthAwarePaginator A paginator instance with records and metadata
     */
    public function getPaginated(int $page = 1, int $perPage = 15, array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($page, $perPage, $filters, $sorts) {
            return $this->repository->paginate($page, $perPage, $filters, $sorts);
        });
    }

    /**
     * Find a record by its ID.
     *
     * @param int $id The record ID
     * @return Model|null The found model or null if not found
     */
    public function findById(int $id): ?Model
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($id) {
            return $this->repository->find($id);
        });
    }

    /**
     * Create a new record with validation.
     *
     * @param array $data The data to create the record with
     * @return Model The created model
     * @throws ValidationException If validation fails
     */
    public function create(array $data): Model
    {
        // Validate data
        $this->validate($data, $this->rules['create'] ?? $this->rules);

        // Authorize action
        $this->authorize('create', $this->repository->getModel());

        // Begin transaction
        $this->beginTransaction();

        try {
            // Create the record
            $model = $this->repository->create($data);

            // Dispatch creation event
            $this->dispatchEvent('created', $model);

            // Commit transaction
            $this->commitTransaction();

            // Clear cache
            $this->clearCache();

            return $model;
        } catch (\Exception $e) {
            // Rollback transaction on error
            $this->rollbackTransaction();
            
            // Log the error
            $this->log('error', "Error creating record: {$e->getMessage()}", ['data' => $data]);
            
            throw $e;
        }
    }

    /**
     * Update an existing record with validation.
     *
     * @param int $id The ID of the record to update
     * @param array $data The data to update the record with
     * @return Model|null The updated model or null if not found
     * @throws ValidationException If validation fails
     */
    public function update(int $id, array $data): ?Model
    {
        // Find the model
        $model = $this->findById($id);

        if (!$model) {
            return null;
        }

        // Validate data
        $this->validate($data, $this->rules['update'] ?? $this->rules);

        // Authorize action
        $this->authorize('update', $model);

        // Begin transaction
        $this->beginTransaction();

        try {
            // Update the record
            $model = $this->repository->update($id, $data);

            if ($model) {
                // Dispatch update event
                $this->dispatchEvent('updated', $model);
            }

            // Commit transaction
            $this->commitTransaction();

            // Clear cache
            $this->clearCache();

            return $model;
        } catch (\Exception $e) {
            // Rollback transaction on error
            $this->rollbackTransaction();
            
            // Log the error
            $this->log('error', "Error updating record {$id}: {$e->getMessage()}", ['data' => $data]);
            
            throw $e;
        }
    }

    /**
     * Delete a record by its ID.
     *
     * @param int $id The ID of the record to delete
     * @return bool True if deletion was successful, false otherwise
     */
    public function delete(int $id): bool
    {
        // Find the model
        $model = $this->findById($id);

        if (!$model) {
            return false;
        }

        // Authorize action
        $this->authorize('delete', $model);

        // Begin transaction
        $this->beginTransaction();

        try {
            // Delete the record
            $result = $this->repository->delete($id);

            if ($result) {
                // Dispatch deletion event
                $this->dispatchEvent('deleted', ['id' => $id, 'model' => get_class($model)]);
            }

            // Commit transaction
            $this->commitTransaction();

            // Clear cache
            $this->clearCache();

            return $result;
        } catch (\Exception $e) {
            // Rollback transaction on error
            $this->rollbackTransaction();
            
            // Log the error
            $this->log('error', "Error deleting record {$id}: {$e->getMessage()}");
            
            throw $e;
        }
    }

    /**
     * Begin a database transaction.
     *
     * @return void
     */
    protected function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    /**
     * Commit a database transaction.
     *
     * @return void
     */
    protected function commitTransaction(): void
    {
        DB::commit();
    }

    /**
     * Rollback a database transaction.
     *
     * @return void
     */
    protected function rollbackTransaction(): void
    {
        DB::rollBack();
    }

    /**
     * Dispatch an event.
     *
     * @param string $event The event name (will be prefixed with model class name)
     * @param mixed $data The data to include with the event
     * @return void
     */
    protected function dispatchEvent(string $event, $data): void
    {
        $modelClass = get_class($this->repository->getModel());
        $eventName = "{$modelClass}.{$event}";
        
        Event::dispatch($eventName, $data);
    }

    /**
     * Validate data against rules.
     *
     * @param array $data The data to validate
     * @param array $rules The validation rules
     * @return array The validated data
     * @throws ValidationException If validation fails
     */
    protected function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Authorize an action.
     *
     * @param string $ability The ability to check
     * @param mixed $model The model to check against (optional)
     * @return bool True if authorized
     * @throws \Illuminate\Auth\Access\AuthorizationException If not authorized
     */
    protected function authorize(string $ability, $model = null): bool
    {
        return Auth::user()->can($ability, $model);
    }

    /**
     * Send a notification to a user or users.
     *
     * @param mixed $notifiable The user or users to notify
     * @param mixed $notification The notification to send
     * @return void
     */
    protected function sendNotification($notifiable, $notification): void
    {
        Notification::send($notifiable, $notification);
    }

    /**
     * Log a message with context.
     *
     * @param string $level The log level (debug, info, notice, warning, error, critical, alert, emergency)
     * @param string $message The log message
     * @param array $context Additional context data
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::$level($message, $context);
    }

    /**
     * Build a cache key for a method and its arguments.
     *
     * @param string $method The method name
     * @param array $args The method arguments
     * @return string The cache key
     */
    protected function buildCacheKey(string $method, array $args): string
    {
        $modelClass = get_class($this->repository->getModel());
        return $this->cachePrefix . $modelClass . ':' . $method . ':' . md5(serialize($args));
    }

    /**
     * Cache a query result if caching is enabled.
     *
     * @param string $key The cache key
     * @param callable $callback The query callback to execute
     * @return mixed The query result
     */
    protected function cacheQuery(string $key, callable $callback)
    {
        if (!$this->useCache || $this->cacheLifetime === null) {
            return $callback();
        }

        return Cache::remember($key, $this->cacheLifetime, $callback);
    }

    /**
     * Clear the service cache.
     *
     * @return bool True if cache was cleared successfully, false otherwise
     */
    protected function clearCache(): bool
    {
        $modelClass = get_class($this->repository->getModel());
        $cacheKey = $this->cachePrefix . $modelClass;
        
        return Cache::forget($cacheKey);
    }

    /**
     * Enable or disable caching.
     *
     * @param bool $useCache Whether to use caching
     * @return self Returns the service instance for method chaining
     */
    protected function setUseCache(bool $useCache): self
    {
        $this->useCache = $useCache;
        return $this;
    }

    /**
     * Set the cache lifetime.
     *
     * @param int|null $minutes The cache lifetime in minutes (null for no expiration)
     * @return self Returns the service instance for method chaining
     */
    protected function setCacheLifetime(?int $minutes): self
    {
        $this->cacheLifetime = $minutes;
        return $this;
    }

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback The callback to execute
     * @return mixed The callback result
     * @throws \Exception if an error occurs
     */
    protected function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commitTransaction();
            return $result;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Get the repository instance.
     *
     * @return RepositoryInterface The repository instance
     */
    public function getRepository(): RepositoryInterface
    {
        return $this->repository;
    }
}