<?php

namespace SwatTech\Crud\Repositories;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * CacheDecorator
 *
 * A decorator for repository classes that adds caching functionality.
 * This class implements the repository pattern with caching to improve
 * performance for frequently accessed data while ensuring cache invalidation
 * when data changes.
 *
 * @package SwatTech\Crud\Repositories
 */
class CacheDecorator implements RepositoryInterface
{
    /**
     * The repository being decorated.
     *
     * @var RepositoryInterface
     */
    protected $repository;
    
    /**
     * The cache manager instance.
     *
     * @var CacheManager
     */
    protected $cache;
    
    /**
     * The cache lifetime in minutes.
     *
     * @var int|null
     */
    protected $cacheLifetime = 60; // Default: 1 hour
    
    /**
     * The cache key prefix.
     *
     * @var string
     */
    protected $cachePrefix = 'repository_cache_';
    
    /**
     * The cache tags to use.
     *
     * @var array|null
     */
    protected $cacheTags = null;
    
    /**
     * Create a new cache decorator instance.
     *
     * @param RepositoryInterface $repository The repository being decorated
     * @param CacheManager $cache The cache manager
     * @param int|null $cacheLifetime The cache lifetime in minutes, or null for no expiration
     */
    public function __construct(RepositoryInterface $repository, CacheManager $cache, ?int $cacheLifetime = 60)
    {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->cacheLifetime = $cacheLifetime;
        
        // Generate cache tags based on the repository model
        $this->cacheTags = $this->generateCacheTags();
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
        
        if ($this->cacheLifetime === null) {
            return $this->repository->all($filters, $sorts);
        }
        
        $cacheStore = $this->getCacheStore();
        
        return $cacheStore->remember($cacheKey, $this->cacheLifetime, function () use ($filters, $sorts) {
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
    public function paginate(int $page = 1, int $perPage = 15, array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        $cacheKey = $this->buildCacheKey(__METHOD__, func_get_args());
        
        if ($this->cacheLifetime === null) {
            return $this->repository->paginate($page, $perPage, $filters, $sorts);
        }
        
        $cacheStore = $this->getCacheStore();
        
        return $cacheStore->remember($cacheKey, $this->cacheLifetime, function () use ($page, $perPage, $filters, $sorts) {
            return $this->repository->paginate($page, $perPage, $filters, $sorts);
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
        // Create the record through the repository
        $result = $this->repository->create($data);
        
        // Invalidate the cache after creating
        $this->clearCache();
        
        return $result;
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
        // Update the record through the repository
        $result = $this->repository->update($id, $data);
        
        // Invalidate the cache after updating
        $this->clearCache();
        
        return $result;
    }
    
    /**
     * Delete a record by its ID.
     *
     * @param int $id The ID of the record to delete
     * @return bool True if deletion was successful, false otherwise
     */
    public function delete(int $id): bool
    {
        // Delete the record through the repository
        $result = $this->repository->delete($id);
        
        // Invalidate the cache after deleting
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
        
        if ($this->cacheLifetime === null) {
            return $this->repository->find($id);
        }
        
        $cacheStore = $this->getCacheStore();
        
        return $cacheStore->remember($cacheKey, $this->cacheLifetime, function () use ($id) {
            return $this->repository->find($id);
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
        
        if ($this->cacheLifetime === null) {
            return $this->repository->findBy($column, $value);
        }
        
        $cacheStore = $this->getCacheStore();
        
        return $cacheStore->remember($cacheKey, $this->cacheLifetime, function () use ($column, $value) {
            return $this->repository->findBy($column, $value);
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
        $this->repository->with($relations);
        return $this;
    }
    
    /**
     * Get the underlying model instance.
     *
     * @return Model The model instance
     */
    public function getModel(): Model
    {
        return $this->repository->getModel();
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
     * Set the cache lifetime for this repository.
     *
     * @param int|null $minutes The cache lifetime in minutes, or null for no caching
     * @return self Returns the repository instance for method chaining
     */
    public function setCacheLifetime(?int $minutes): self
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
    public function setCachePrefix(string $prefix): self
    {
        $this->cachePrefix = $prefix;
        return $this;
    }
    
    /**
     * Clear the cache for this repository.
     *
     * @return bool True if cache was cleared successfully, false otherwise
     */
    public function clearCache(): bool
    {
        if ($this->cacheTags) {
            return $this->cache->tags($this->cacheTags)->flush();
        }
        
        // If not using tags, we can only clear specific keys
        // This is a simplified implementation and may not catch all keys
        $cacheKey = $this->cachePrefix . get_class($this->getModel());
        return $this->cache->forget($cacheKey);
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
            get_class($this->getModel()),
            $method,
            md5(serialize($args))
        ];
        
        return implode(':', $parts);
    }
    
    /**
     * Generate cache tags based on the repository model.
     *
     * @return array|null Array of cache tags or null if tags are not supported
     */
    protected function generateCacheTags(): ?array
    {
        // Check if the cache store supports tags
        if (method_exists($this->cache->getStore(), 'tags')) {
            $modelName = strtolower(class_basename($this->getModel()));
            return [$modelName, 'repository', 'model-' . $modelName];
        }
        
        return null;
    }
    
    /**
     * Get the appropriate cache store instance with or without tags.
     *
     * @return mixed The cache store to use
     */
    protected function getCacheStore()
    {
        if ($this->cacheTags) {
            return $this->cache->tags($this->cacheTags);
        }
        
        return $this->cache;
    }
}