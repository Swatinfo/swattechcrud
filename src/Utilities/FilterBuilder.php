<?php

namespace SwatTech\Crud\Utilities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FilterBuilder
 *
 * A utility class for applying filters to database queries.
 * Handles various filter types, operators, and relationship filtering.
 *
 * @package SwatTech\Crud\Utilities
 */
class FilterBuilder
{
    /**
     * Apply filters to a query builder instance.
     *
     * @param Builder $query The query builder to apply filters to
     * @param array $filters The filters to apply
     * @return Builder The filtered query builder
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        foreach ($filters as $field => $value) {
            // Skip empty values except zero
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // Check if this is a relation.field filter
            if (Str::contains($field, '.')) {
                $parts = explode('.', $field);
                $relation = implode('.', array_slice($parts, 0, -1));
                $relationField = end($parts);
                $operator = '=';
                
                // Check if value contains an operator
                if (is_string($value) && Str::contains($value, ':')) {
                    list($operator, $filterValue) = $this->parseFilterString($value);
                    $this->addRelationshipFilter($query, $relation, $relationField, $operator, $filterValue);
                } else {
                    $this->addRelationshipFilter($query, $relation, $relationField, $operator, $value);
                }
            } 
            // Check if this is a special "or" condition
            elseif ($field === 'or' && is_array($value)) {
                $this->addOrCondition($query, $value);
            }
            // Check if this is a custom filter
            elseif (Str::startsWith($field, 'filter_')) {
                $filterName = Str::after($field, 'filter_');
                $this->addCustomFilter($query, $filterName, $value);
            }
            // Standard field filter
            else {
                $operator = '=';
                
                // Check if value contains an operator
                if (is_string($value) && Str::contains($value, ':')) {
                    list($operator, $value) = $this->parseFilterString($value);
                }
                
                $this->addCondition($query, $field, $operator, $value);
            }
        }

        return $query;
    }

    /**
     * Parse a filter string in the format "operator:value".
     *
     * @param string $filterString The filter string to parse
     * @return array [$operator, $value]
     */
    public function parseFilterString(string $filterString): array
    {
        // Default to equals operator
        $operator = '=';
        $value = $filterString;

        // If the string has an operator prefix
        if (Str::contains($filterString, ':')) {
            $parts = explode(':', $filterString, 2);
            $operatorStr = strtolower($parts[0]);
            $value = $parts[1];

            $operators = $this->getSupportedOperators();
            if (isset($operators[$operatorStr])) {
                $operator = $operators[$operatorStr];
            }
        }

        return [$operator, $value];
    }

    /**
     * Add a condition to the query builder.
     *
     * @param Builder $query The query builder
     * @param string $field The field to filter on
     * @param string $operator The comparison operator
     * @param mixed $value The value to compare against
     * @return Builder The modified query builder
     */
    public function addCondition(Builder $query, string $field, string $operator, $value): Builder
    {
        // Handle special operators
        switch ($operator) {
            case 'in':
                if (!is_array($value)) {
                    $value = explode(',', (string) $value);
                }
                return $query->whereIn($field, $value);
                
            case 'not_in':
                if (!is_array($value)) {
                    $value = explode(',', (string) $value);
                }
                return $query->whereNotIn($field, $value);
                
            case 'between':
                if (!is_array($value)) {
                    $value = explode(',', (string) $value);
                }
                if (count($value) == 2) {
                    return $query->whereBetween($field, $value);
                }
                return $query;
                
            case 'not_between':
                if (!is_array($value)) {
                    $value = explode(',', (string) $value);
                }
                if (count($value) == 2) {
                    return $query->whereNotBetween($field, $value);
                }
                return $query;
                
            case 'null':
                return $query->whereNull($field);
                
            case 'not_null':
                return $query->whereNotNull($field);
                
            case 'like':
            case 'contains':
                return $query->where($field, 'like', "%{$value}%");
                
            case 'starts_with':
                return $query->where($field, 'like', "{$value}%");
                
            case 'ends_with':
                return $query->where($field, 'like', "%{$value}");
                
            case 'date':
                return $query->whereDate($field, '=', $value);
                
            case 'day':
                return $query->whereDay($field, '=', $value);
                
            case 'month':
                return $query->whereMonth($field, '=', $value);
                
            case 'year':
                return $query->whereYear($field, '=', $value);
                
            case 'time':
                return $query->whereTime($field, '=', $value);
                
            case 'exists':
                return $query->whereExists(function ($subquery) use ($field, $value) {
                    // This is a simplified example - real implementation would be more complex
                    $subquery->from($value)->whereRaw("id = {$field}");
                });
                
            default:
                return $query->where($field, $operator, $this->escapeValue($value));
        }
    }

    /**
     * Add an OR condition group to the query builder.
     *
     * @param Builder $query The query builder
     * @param array $conditions The OR conditions to apply
     * @return Builder The modified query builder
     */
    public function addOrCondition(Builder $query, array $conditions): Builder
    {
        return $query->where(function ($query) use ($conditions) {
            foreach ($conditions as $field => $value) {
                $operator = '=';
                
                // Check if value contains an operator
                if (is_string($value) && Str::contains($value, ':')) {
                    list($operator, $value) = $this->parseFilterString($value);
                }
                
                $query->orWhere(function ($subQuery) use ($field, $operator, $value) {
                    $this->addCondition($subQuery, $field, $operator, $value);
                });
            }
        });
    }

    /**
     * Add a relationship filter to the query builder.
     *
     * @param Builder $query The query builder
     * @param string $relation The relationship to filter on
     * @param string $field The field within the relationship to filter
     * @param string $operator The comparison operator
     * @param mixed $value The value to compare against
     * @return Builder The modified query builder
     */
    public function addRelationshipFilter(Builder $query, string $relation, string $field, string $operator, $value): Builder
    {
        return $query->whereHas($relation, function ($subQuery) use ($field, $operator, $value) {
            $this->addCondition($subQuery, $field, $operator, $value);
        });
    }

    /**
     * Add a custom filter to the query builder.
     *
     * @param Builder $query The query builder
     * @param string $name The custom filter name
     * @param mixed $value The value for the custom filter
     * @return Builder The modified query builder
     */
    public function addCustomFilter(Builder $query, string $name, $value): Builder
    {
        $methodName = 'apply' . Str::studly($name) . 'Filter';
        
        // Check if the model has a scope method for this filter
        $scopeMethod = 'scope' . Str::studly($name);
        $model = $query->getModel();
        
        if (method_exists($model, $scopeMethod)) {
            return $query->{$name}($value);
        }
        
        // Check if this class implements the custom filter
        if (method_exists($this, $methodName)) {
            return $this->{$methodName}($query, $value);
        }
        
        return $query;
    }

    /**
     * Validate if a field is allowed to be filtered.
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
     * Build a filter array from request parameters.
     *
     * @param array $params Request parameters
     * @return array Structured filter array
     */
    public function buildFilterArray(array $params): array
    {
        $filters = [];
        
        foreach ($params as $key => $value) {
            // Skip pagination and sorting parameters
            if (in_array($key, ['page', 'per_page', 'sort', 'order'])) {
                continue;
            }
            
            // Handle special filter parameter format
            if ($key === 'filter' && is_array($value)) {
                foreach ($value as $filterField => $filterValue) {
                    $filters[$filterField] = $filterValue;
                }
                continue;
            }
            
            $filters[$key] = $value;
        }
        
        return $filters;
    }

    /**
     * Get a list of supported operators and their SQL equivalents.
     *
     * @return array Operator mapping
     */
    public function getSupportedOperators(): array
    {
        return [
            'eq' => '=',
            'neq' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            'in' => 'in',
            'not_in' => 'not_in',
            'between' => 'between',
            'not_between' => 'not_between',
            'like' => 'like',
            'not_like' => 'not like',
            'contains' => 'contains',
            'starts_with' => 'starts_with',
            'ends_with' => 'ends_with',
            'null' => 'null',
            'not_null' => 'not_null',
            'date' => 'date',
            'day' => 'day',
            'month' => 'month',
            'year' => 'year',
            'time' => 'time',
            'exists' => 'exists',
        ];
    }

    /**
     * Escape a value for safe use in SQL queries.
     *
     * @param mixed $value The value to escape
     * @return mixed The escaped value
     */
    public function escapeValue($value)
    {
        if (is_string($value)) {
            // For strings, use the database's escape mechanism
            // But we avoid direct SQL injection by using parameterized queries in Laravel
            return $value;
        }
        
        // For non-strings, return as is (ints, floats, bools are safe)
        return $value;
    }

    /**
     * Example implementation of a custom filter.
     * This is just an example - real implementations would be specific to application needs.
     *
     * @param Builder $query The query builder
     * @param mixed $value The filter value
     * @return Builder The modified query builder
     */
    protected function applySearchFilter(Builder $query, $value): Builder
    {
        if (empty($value)) {
            return $query;
        }
        
        // Get the searchable fields from model if available
        $model = $query->getModel();
        $searchableFields = $model->searchable ?? ['id', 'name', 'title', 'description', 'email'];
        
        return $query->where(function ($query) use ($searchableFields, $value) {
            foreach ($searchableFields as $field) {
                $query->orWhere($field, 'like', "%{$value}%");
            }
        });
    }

    /**
     * Example implementation of a date range filter.
     *
     * @param Builder $query The query builder
     * @param mixed $value The filter value (expected as array with start and end dates)
     * @return Builder The modified query builder
     */
    protected function applyDateRangeFilter(Builder $query, $value): Builder
    {
        if (!is_array($value) || !isset($value['start']) || !isset($value['end'])) {
            return $query;
        }
        
        $field = $value['field'] ?? 'created_at';
        
        return $query->whereBetween($field, [$value['start'], $value['end']]);
    }

    /**
     * Example implementation of a status filter.
     *
     * @param Builder $query The query builder
     * @param mixed $value The filter value (status or array of statuses)
     * @return Builder The modified query builder
     */
    protected function applyStatusFilter(Builder $query, $value): Builder
    {
        if (is_array($value)) {
            return $query->whereIn('status', $value);
        }
        
        return $query->where('status', '=', $value);
    }

    /**
     * Example implementation of a complex filter that checks multiple fields.
     *
     * @param Builder $query The query builder
     * @param mixed $value The filter value
     * @return Builder The modified query builder
     */
    protected function applyComplexFilter(Builder $query, $value): Builder
    {
        return $query->where(function ($query) use ($value) {
            $query->where('field1', '=', $value)
                  ->orWhere('field2', '=', $value)
                  ->orWhere('field3', 'like', "%{$value}%");
        });
    }
}