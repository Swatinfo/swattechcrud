<?php

namespace SwatTech\Crud\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Interface RepositoryInterface
 * 
 * This interface defines the standard repository pattern methods
 * for database operations. It provides a consistent API for all
 * repositories in the application, promoting SOLID principles and
 * testability.
 *
 * @package SwatTech\Crud\Contracts
 */
interface RepositoryInterface
{
    /**
     * Get all records with optional filtering and sorting.
     *
     * This method retrieves all records from the repository's underlying
     * model, with optional filtering and sorting capabilities.
     *
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return Collection A collection of model instances
     */
    public function all(array $filters = [], array $sorts = []): Collection;

    /**
     * Get paginated results with optional filtering and sorting.
     *
     * This method returns a paginated set of results, allowing for
     * efficient retrieval of large datasets with database-level pagination.
     *
     * @param int $page The page number to retrieve (starting from 1)
     * @param int $perPage The number of records per page
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return LengthAwarePaginator A paginator instance with records and metadata
     */
    public function paginate(int $page = 1, int $perPage = 15, array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Create a new record with the provided data.
     *
     * This method creates a new record in the database with the
     * provided data and returns the created model instance.
     *
     * @param array $data The data to create the record with
     * @return Model The created model instance
     */
    public function create(array $data): Model;

    /**
     * Update an existing record with the provided data.
     *
     * This method updates an existing record in the database with
     * the provided data and returns the updated model instance.
     *
     * @param int $id The ID of the record to update
     * @param array $data The data to update the record with
     * @return Model The updated model instance
     */
    public function update(int $id, array $data): Model;

    /**
     * Delete a record with the specified ID.
     *
     * This method deletes a record from the database. Depending on the
     * implementation, this may be a soft delete or a permanent deletion.
     *
     * @param int $id The ID of the record to delete
     * @return bool True if deletion was successful, false otherwise
     */
    public function delete(int $id): bool;

    /**
     * Find a record by its ID.
     *
     * This method retrieves a single record from the repository
     * based on its primary key (ID).
     *
     * @param int $id The ID of the record to find
     * @return Model|null The found model instance or null if not found
     */
    public function find(int $id): ?Model;

    /**
     * Find records by a specific column value.
     *
     * This method retrieves records matching a specific column value,
     * which is useful for lookups on non-primary key columns.
     *
     * @param string $column The column to search on
     * @param mixed $value The value to search for
     * @return Collection A collection of matching model instances
     */
    public function findBy(string $column, $value): Collection;

    /**
     * Set the relationships to eager load with the query.
     *
     * This method allows specifying which relationships should be
     * eager loaded when retrieving data, reducing N+1 query problems.
     *
     * @param array $relations An array of relationship names to eager load
     * @return self Returns the repository instance for method chaining
     */
    public function with(array $relations): self;

    /**
     * Get the underlying model instance.
     *
     * This method returns the model instance that the repository
     * is operating on, allowing for more direct model access when needed.
     *
     * @return Model The model instance
     */
    public function getModel(): Model;

    /**
     * Get the cache lifetime for this repository.
     *
     * This method returns the number of minutes that results from
     * this repository should be cached, or null for no caching.
     *
     * @return int|null The cache lifetime in minutes, or null for no caching
     */
    public function getCacheLifetime(): ?int;

    /**
     * Clear the cache for this repository.
     *
     * This method flushes any cached results related to this repository,
     * which is useful after mutations to ensure fresh data retrieval.
     *
     * @return bool True if cache was cleared successfully, false otherwise
     */
    public function clearCache(): bool;
}