<?php

namespace SwatTech\Crud\Utilities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * SortBuilder
 *
 * A utility class for applying sorting to database queries.
 * Handles various sort types including relationship sorting
 * and case-insensitive sorting options.
 *
 * @package SwatTech\Crud\Utilities
 */
class SortBuilder
{
    /**
     * Apply sorts to a query builder instance.
     *
     * @param Builder $query The query builder to apply sorts to
     * @param array $sorts The sorts to apply as field => direction pairs
     * @return Builder The query builder with sorts applied
     */
    public function apply(Builder $query, array $sorts): Builder
    {
        if (empty($sorts)) {
            // Apply default sorts if none specified
            return $this->applyDefaultSorts($query);
        }

        foreach ($sorts as $field => $direction) {
            // Skip invalid values
            if (empty($field)) {
                continue;
            }

            // Normalize direction
            $direction = $this->parseDirectionString($direction);

            // Check if this is a relation.field sort
            if (Str::contains($field, '.')) {
                $parts = explode('.', $field);
                $relation = implode('.', array_slice($parts, 0, -1));
                $relationField = end($parts);
                
                $this->addRelationshipSort($query, $relation, $relationField, $direction);
            } else {
                $this->addSort($query, $field, $direction);
            }
        }

        return $query;
    }

    /**
     * Parse a sort string in the format "field:direction".
     *
     * @param string $sortString The sort string to parse
     * @return array [$field, $direction]
     */
    public function parseSortString(string $sortString): array
    {
        // Default to ascending direction
        $direction = 'asc';
        $field = $sortString;

        // If the string has a direction suffix
        if (Str::contains($sortString, ':')) {
            $parts = explode(':', $sortString, 2);
            $field = $parts[0];
            $direction = $this->parseDirectionString($parts[1]);
        }

        return [$field, $direction];
    }

    /**
     * Add a basic sort to the query builder.
     *
     * @param Builder $query The query builder
     * @param string $field The field to sort by
     * @param string $direction The sort direction (asc or desc)
     * @return Builder The modified query builder
     */
    public function addSort(Builder $query, string $field, string $direction = 'asc'): Builder
    {
        // Check if field exists in the table (basic check, could be enhanced)
        // For MySQL/PostgreSQL, we can do case-insensitive sorting
        if (config('crud.sorting.case_insensitive', false)) {
            return $this->applyCaseInsensitiveSort($query, $field, $direction);
        }
        
        // Standard sorting
        return $query->orderBy($field, $direction);
    }

    /**
     * Add a relationship sort to the query builder.
     *
     * @param Builder $query The query builder
     * @param string $relation The relationship to sort on
     * @param string $field The field within the relationship to sort by
     * @param string $direction The sort direction (asc or desc)
     * @return Builder The modified query builder
     */
    public function addRelationshipSort(Builder $query, string $relation, string $field, string $direction = 'asc'): Builder
    {
        // Get the model and relationship instance
        $model = $query->getModel();

        // Make sure the relationship method exists
        if (!method_exists($model, $relation)) {
            return $query;
        }

        // Handle different types of relationships
        // This is a simplified approach - a production implementation would handle more cases
        return $query->orderBy(function($subQuery) use ($relation, $field, $direction) {
            $subQuery->select($field)
                ->from($relation)
                ->whereColumn($relation . '.id', $subQuery->getModel()->getTable() . '.' . $relation . '_id')
                ->limit(1);
        }, $direction);
    }

    /**
     * Validate if a field is allowed to be sorted.
     *
     * @param string $field The field to validate
     * @param array $allowedFields Array of allowed fields (empty means all fields are allowed)
     * @return bool True if the field is allowed
     */
    public function validateField(string $field, array $allowedFields = []): bool
    {
        // If no allowed fields are specified, all fields are allowed
        if (empty($allowedFields)) {
            return true;
        }

        // Check if this is a relation field
        if (Str::contains($field, '.')) {
            $parts = explode('.', $field);
            $relation = implode('.', array_slice($parts, 0, -1));
            $relationField = end($parts);

            // Check if we have allowed fields for this relation
            $relationAllowedFields = Arr::get($allowedFields, $relation, []);

            if (empty($relationAllowedFields)) {
                // No specific fields defined for this relation, check if the relation itself is allowed
                return in_array($relation, $allowedFields);
            }

            // Check if the field is in the allowed fields for this relation
            return in_array($relationField, $relationAllowedFields);
        }

        // Simple field validation
        return in_array($field, $allowedFields);
    }

    /**
     * Build a sort array from request parameters.
     *
     * @param array $params Request parameters
     * @return array Structured sort array
     */
    public function buildSortArray(array $params): array
    {
        $sorts = [];

        // Handle 'sort' and 'order' parameters
        if (isset($params['sort'])) {
            $sortFields = is_array($params['sort']) ? $params['sort'] : explode(',', $params['sort']);
            $directions = isset($params['order']) 
                ? (is_array($params['order']) ? $params['order'] : explode(',', $params['order'])) 
                : [];

            foreach ($sortFields as $i => $field) {
                // Get corresponding direction if available, otherwise use 'asc'
                $direction = isset($directions[$i]) ? $directions[$i] : 'asc';
                $sorts[$field] = $direction;
            }
        }

        // Handle parameter with format 'sort_by' for single column sorting
        if (isset($params['sort_by'])) {
            $direction = isset($params['sort_dir']) ? $params['sort_dir'] : 'asc';
            $sorts[$params['sort_by']] = $direction;
        }

        // Handle parameter with format 'sort=field:direction'
        if (isset($params['sort']) && is_string($params['sort']) && Str::contains($params['sort'], ':')) {
            list($field, $direction) = $this->parseSortString($params['sort']);
            $sorts[$field] = $direction;
        }

        return $sorts;
    }

    /**
     * Get default sorts to apply when no sorts are specified.
     *
     * @return array Default sorts as field => direction pairs
     */
    public function getDefaultSorts(): array
    {
        // Read from configuration if available, otherwise use common defaults
        return config('crud.sorting.defaults', ['id' => 'desc']);
    }

    /**
     * Parse and normalize a direction string.
     *
     * @param string $direction The direction string to parse
     * @return string Normalized direction ('asc' or 'desc')
     */
    public function parseDirectionString(string $direction): string
    {
        $direction = strtolower(trim($direction));
        
        // Check for various ways to specify descending sort
        if (in_array($direction, ['desc', 'descending', 'down', '-1', 'reverse'])) {
            return 'desc';
        }
        
        // Default to ascending
        return 'asc';
    }

    /**
     * Validate that a direction is valid.
     *
     * @param string $direction The direction to validate
     * @return bool True if the direction is valid
     */
    public function validateDirection(string $direction): bool
    {
        return in_array(strtolower($direction), ['asc', 'desc']);
    }

    /**
     * Apply case-insensitive sorting to the query.
     *
     * @param Builder $query The query builder
     * @param string $field The field to sort by
     * @param string $direction The sort direction (asc or desc)
     * @return Builder The modified query builder
     */
    public function applyCaseInsensitiveSort(Builder $query, string $field, string $direction): Builder
    {
        // Get the connection type to determine how to do case-insensitive sorting
        $connection = $query->getConnection();
        $driverName = $connection->getDriverName();
        
        // Apply case-insensitive sorting based on the database driver
        switch ($driverName) {
            case 'mysql':
            case 'mariadb':
                return $query->orderByRaw("LOWER({$field}) {$direction}");
                
            case 'pgsql':
                return $query->orderByRaw("{$field} COLLATE \"C\" {$direction}");
                
            case 'sqlite':
                return $query->orderByRaw("LOWER({$field}) {$direction}");
                
            default:
                // Fallback to regular sorting for unsupported drivers
                return $query->orderBy($field, $direction);
        }
    }

    /**
     * Apply default sorts to a query.
     *
     * @param Builder $query The query builder
     * @return Builder The query builder with default sorts applied
     */
    protected function applyDefaultSorts(Builder $query): Builder
    {
        $defaultSorts = $this->getDefaultSorts();
        
        foreach ($defaultSorts as $field => $direction) {
            $this->addSort($query, $field, $direction);
        }
        
        return $query;
    }
}