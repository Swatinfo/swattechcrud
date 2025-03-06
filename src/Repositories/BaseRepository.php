<?php

namespace SwatTech\Crud\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use SwatTech\Crud\Contracts\RepositoryInterface;
use SwatTech\Crud\Utilities\FilterBuilder;
use SwatTech\Crud\Utilities\SortBuilder;
use SwatTech\Crud\Utilities\PaginationHelper;

/**
 * BaseRepository
 *
 * This class provides a generic implementation of the Repository pattern,
 * offering standard CRUD operations and advanced querying capabilities.
 * It serves as a base class for specific repositories, encapsulating database
 * interactions and providing a consistent API.
 *
 * @package SwatTech\Crud\Repositories
 */
class BaseRepository implements RepositoryInterface
{
    /**
     * The model instance.
     *
     * @var Model
     */
    protected $model;

    /**
     * The filter builder instance.
     *
     * @var FilterBuilder|null
     */
    protected $filterBuilder;

    /**
     * The sort builder instance.
     *
     * @var SortBuilder|null
     */
    protected $sortBuilder;

    /**
     * The pagination helper instance.
     *
     * @var PaginationHelper|null
     */
    protected $paginationHelper;

    /**
     * The relations to eager load.
     *
     * @var array
     */
    protected $relations = [];

    /**
     * The cache lifetime in minutes.
     *
     * @var int|null
     */
    protected $cacheLifetime = 60; // 1 hour by default, null for no caching

    /**
     * The cache key prefix.
     *
     * @var string
     */
    protected $cachePrefix = 'repository_';

    /**
     * Create a new repository instance.
     *
     * @param Model $model The model instance
     * @param FilterBuilder|null $filterBuilder The filter builder
     * @param SortBuilder|null $sortBuilder The sort builder
     * @param PaginationHelper|null $paginationHelper The pagination helper
     */
    public function __construct(
        Model $model, 
        FilterBuilder $filterBuilder = null, 
        SortBuilder $sortBuilder = null,
        PaginationHelper $paginationHelper = null
    ) {
        $this->model = $model;
        $this->filterBuilder = $filterBuilder ?: new FilterBuilder();
        $this->sortBuilder = $sortBuilder ?: new SortBuilder();
        $this->paginationHelper = $paginationHelper ?: new PaginationHelper();
    }

    /**
     * Get all records with optional filtering and sorting.
     *
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return Collection A collection of model instances
     */
    public function all(array $filters = [], array $sorts = []): Collection
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($filters, $sorts) {
            $query = $this->model->newQuery();
            
            // Apply relations
            if (!empty($this->relations)) {
                $query->with($this->relations);
            }
            
            // Apply filters
            if (!empty($filters) && $this->filterBuilder) {
                $query = $this->filterBuilder->apply($query, $filters);
            }
            
            // Apply sorts
            if (!empty($sorts) && $this->sortBuilder) {
                $query = $this->sortBuilder->apply($query, $sorts);
            }
            
            return $query->get();
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
    public function paginate(int $page = 1, int $perPage = 15, array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($page, $perPage, $filters, $sorts) {
            $query = $this->model->newQuery();
            
            // Apply relations
            if (!empty($this->relations)) {
                $query->with($this->relations);
            }
            
            // Apply filters
            if (!empty($filters) && $this->filterBuilder) {
                $query = $this->filterBuilder->apply($query, $filters);
            }
            
            // Apply sorts
            if (!empty($sorts) && $this->sortBuilder) {
                $query = $this->sortBuilder->apply($query, $sorts);
            }
            
            // Apply pagination
            if ($this->paginationHelper) {
                return $this->paginationHelper->paginate($query, $page, $perPage);
            }
            
            return $query->paginate($perPage, ['*'], 'page', $page);
        });
    }

    /**
     * Create a new record with the provided data.
     *
     * @param array $data The data to create the record with
     * @return Model The created model instance
     */
    public function create(array $data): Model
    {
        $model = null;

        DB::transaction(function () use ($data, &$model) {
            $model = $this->model->create($data);
            
            // Dispatch creation event
            Event::dispatch(get_class($this->model) . '.created', $model);
        });

        // Clear any cached queries that might now be invalid
        $this->clearCache();
        
        return $model;
    }

    /**
     * Update an existing record with the provided data.
     *
     * @param int $id The ID of the record to update
     * @param array $data The data to update the record with
     * @return Model|null The updated model instance, or null if not found
     */
    public function update(int $id, array $data): ?Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            return null;
        }
        
        DB::transaction(function () use ($model, $data) {
            $model->update($data);
            
            // Dispatch update event
            Event::dispatch(get_class($this->model) . '.updated', $model);
        });
        
        // Clear any cached queries that might now be invalid
        $this->clearCache();
        
        return $model;
    }

    /**
     * Delete a record by its ID.
     *
     * @param int $id The ID of the record to delete
     * @return bool True if deletion was successful, false otherwise
     */
    public function delete(int $id): bool
    {
        $model = $this->find($id);
        
        if (!$model) {
            return false;
        }
        
        $result = false;
        
        DB::transaction(function () use ($model, &$result) {
            // Check if model uses soft deletes
            $usesSoftDelete = in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model));
            
            // Delete the model (soft or hard delete based on model capabilities)
            $result = $model->delete();
            
            // Dispatch delete event
            Event::dispatch(get_class($this->model) . '.deleted', [
                'id' => $model->id,
                'soft_deleted' => $usesSoftDelete
            ]);
        });
        
        // Clear any cached queries that might now be invalid
        $this->clearCache();
        
        return $result;
    }

    /**
     * Find a record by its ID.
     *
     * @param int $id The ID of the record to find
     * @return Model|null The found model instance or null if not found
     */
    public function find(int $id): ?Model
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($id) {
            $query = $this->model->newQuery();
            
            // Apply relations
            if (!empty($this->relations)) {
                $query->with($this->relations);
            }
            
            return $query->find($id);
        });
    }

    /**
     * Find records by a specific column value.
     *
     * @param string $column The column to search on
     * @param mixed $value The value to search for
     * @return Collection A collection of matching model instances
     */
    public function findBy(string $column, $value): Collection
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($column, $value) {
            $query = $this->model->newQuery();
            
            // Apply relations
            if (!empty($this->relations)) {
                $query->with($this->relations);
            }
            
            return $query->where($column, $value)->get();
        });
    }

    /**
     * Set the relationships to eager load with the query.
     *
     * @param array $relations An array of relationship names to eager load
     * @return self Returns the repository instance for method chaining
     */
    public function with(array $relations): self
    {
        $this->relations = $relations;
        return $this;
    }

    /**
     * Get the underlying model instance.
     *
     * @return Model The model instance
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get the cache lifetime for this repository.
     *
     * @return int|null The cache lifetime in minutes, or null for no caching
     */
    public function getCacheLifetime(): ?int
    {
        return $this->cacheLifetime;
    }

    /**
     * Clear the cache for this repository.
     *
     * @return bool True if cache was cleared successfully, false otherwise
     */
    public function clearCache(): bool
    {
        $cacheKey = $this->cachePrefix . get_class($this->model);
        return Cache::forget($cacheKey);
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
        $parts = [
            $this->cachePrefix,
            get_class($this->model),
            $method,
            md5(serialize($args)),
            serialize($this->relations)
        ];
        
        return implode(':', $parts);
    }

    /**
     * Execute and potentially cache a query callback.
     *
     * @param string $key The cache key
     * @param callable $callback The query callback to execute
     * @return mixed The query result
     */
    protected function cacheQuery(string $key, callable $callback)
    {
        if ($this->cacheLifetime === null) {
            return $callback();
        }
        
        return Cache::remember($key, $this->cacheLifetime, $callback);
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
     * Execute callback within a transaction.
     *
     * @param callable $callback The callback to execute within transaction
     * @return mixed The callback result
     * @throws \Exception if the callback throws an exception
     */
    protected function transaction(callable $callback)
    {
        try {
            $this->beginTransaction();
            $result = $callback();
            $this->commitTransaction();
            return $result;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Apply query scopes from the model.
     *
     * @param string $scope The scope name
     * @param array $parameters The scope parameters
     * @return self Returns the repository instance for method chaining
     */
    protected function scope(string $scope, array $parameters = []): self
    {
        $this->model = $this->model->newQuery()->{$scope}(...$parameters)->getModel();
        return $this;
    }

    /**
     * Search for records using a search term across multiple columns.
     *
     * @param string $term The search term
     * @param array $columns The columns to search in
     * @return Collection A collection of matching records
     */
    protected function search(string $term, array $columns): Collection
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());

        return $this->cacheQuery($cacheKey, function () use ($term, $columns) {
            $query = $this->model->newQuery();
            
            // Apply relations
            if (!empty($this->relations)) {
                $query->with($this->relations);
            }
            
            $query->where(function ($q) use ($columns, $term) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'LIKE', "%{$term}%");
                }
            });
            
            return $query->get();
        });
    }

    /**
     * Set the cache lifetime for this repository.
     *
     * @param int|null $minutes The cache lifetime in minutes, or null for no caching
     * @return self Returns the repository instance for method chaining
     */
    protected function setCacheLifetime(?int $minutes): self
    {
        $this->cacheLifetime = $minutes;
        return $this;
    }

    /**
     * Set the cache key prefix for this repository.
     *
     * @param string $prefix The cache key prefix
     * @return self Returns the repository instance for method chaining
     */
    protected function setCachePrefix(string $prefix): self
    {
        $this->cachePrefix = $prefix;
        return $this;
    }
}