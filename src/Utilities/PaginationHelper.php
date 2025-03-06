<?php

namespace SwatTech\Crud\Utilities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;

/**
 * PaginationHelper
 *
 * A utility class for handling different types of pagination in the application.
 * Provides methods for standard pagination, simple pagination, and cursor pagination,
 * along with utilities for formatting pagination results.
 *
 * @package SwatTech\Crud\Utilities
 */
class PaginationHelper
{
    /**
     * Paginate a query with total count (length-aware pagination).
     *
     * This method applies length-aware pagination to a query builder instance,
     * which includes the total count of items for calculating total pages.
     *
     * @param Builder $query The query builder to paginate
     * @param int $page The page number to retrieve (starting from 1)
     * @param int $perPage The number of items per page
     * @return LengthAwarePaginator The paginated results
     */
    public function paginate(Builder $query, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        // Ensure page is at least 1
        $page = max(1, $page);
        
        // Retrieve the current path for pagination links
        $path = Paginator::resolveCurrentPath();
        
        return $this->getLengthAwarePaginator($query, $page, $perPage, $path);
    }

    /**
     * Paginate a query without total count (simple pagination).
     *
     * This method applies simple pagination to a query builder instance, which
     * only determines if there is a next page without counting the total items.
     * This is more efficient for large datasets when total count isn't needed.
     *
     * @param Builder $query The query builder to paginate
     * @param int $page The page number to retrieve (starting from 1)
     * @param int $perPage The number of items per page
     * @return Paginator The paginated results
     */
    public function simplePaginate(Builder $query, int $page = 1, int $perPage = 15): Paginator
    {
        // Ensure page is at least 1
        $page = max(1, $page);
        
        // Retrieve the current path for pagination links
        $path = Paginator::resolveCurrentPath();
        
        return $this->getSimplePaginator($query, $page, $perPage, $path);
    }

    /**
     * Paginate a query using cursor-based pagination.
     *
     * This method applies cursor-based pagination to a query builder instance,
     * which uses a cursor pointer instead of page numbers. This is more efficient
     * for infinite scrolling and real-time data.
     *
     * @param Builder $query The query builder to paginate
     * @param int $perPage The number of items per page
     * @param string|null $cursor The cursor pointer value
     * @return CursorPaginator The paginated results
     */
    public function cursorPaginate(Builder $query, int $perPage = 15, string $cursor = null): CursorPaginator
    {
        return $this->getCursorPaginator($query, $perPage, $cursor);
    }

    /**
     * Get a LengthAwarePaginator instance from a query.
     *
     * @param Builder $query The query builder instance
     * @param int $page The page number
     * @param int $perPage The number of items per page
     * @param string|null $path The base path for pagination links
     * @return LengthAwarePaginator The length-aware paginator instance
     */
    public function getLengthAwarePaginator(Builder $query, int $page, int $perPage, string $path = null): LengthAwarePaginator
    {
        // Get current query parameters to preserve them in pagination links
        $queryParams = Request::query();
        unset($queryParams['page']); // Remove page from query params
        
        $total = $query->toBase()->getCountForPagination();
        $items = $total ? $query->forPage($page, $perPage)->get() : collect();
        
        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => $path ?? Request::url(),
                'query' => $queryParams
            ]
        );
    }

    /**
     * Get a Paginator instance from a query (simple pagination).
     *
     * @param Builder $query The query builder instance
     * @param int $page The page number
     * @param int $perPage The number of items per page
     * @param string|null $path The base path for pagination links
     * @return Paginator The simple paginator instance
     */
    public function getSimplePaginator(Builder $query, int $page, int $perPage, string $path = null): Paginator
    {
        // Get current query parameters to preserve them in pagination links
        $queryParams = Request::query();
        unset($queryParams['page']); // Remove page from query params
        
        $offset = ($page - 1) * $perPage;
        $items = $query->skip($offset)->take($perPage + 1)->get();
        
        $hasMorePages = $items->count() > $perPage;
        if ($hasMorePages) {
            $items = $items->slice(0, $perPage);
        }
        
        return new Paginator(
            $items,
            $perPage,
            $page,
            [
                'path' => $path ?? Request::url(),
                'query' => $queryParams
            ]
        );
    }

    /**
     * Get a CursorPaginator instance from a query.
     *
     * @param Builder $query The query builder instance
     * @param int $perPage The number of items per page
     * @param string|null $cursor The cursor value
     * @return CursorPaginator The cursor paginator instance
     */
    public function getCursorPaginator(Builder $query, int $perPage, string $cursor = null): CursorPaginator
    {
        return $query->cursorPaginate(
            $perPage, 
            ['*'], 
            'cursor', 
            $cursor
        );
    }

    /**
     * Calculate the total number of pages.
     *
     * @param int $total The total number of items
     * @param int $perPage The number of items per page
     * @return int The total number of pages
     */
    public function calculateTotalPages(int $total, int $perPage): int
    {
        return (int) ceil($total / max(1, $perPage));
    }

    /**
     * Build an array of pagination links.
     *
     * @param LengthAwarePaginator $paginator The paginator instance
     * @return array An array of pagination links
     */
    public function buildPaginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * Get pagination metadata as an array.
     *
     * @param LengthAwarePaginator $paginator The paginator instance
     * @return array Pagination metadata
     */
    public function getPaginationMetadata(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'count' => $paginator->count(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'total_pages' => $paginator->lastPage(),
            'links' => $this->buildPaginationLinks($paginator),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Format paginated results into a structured API response array.
     *
     * @param LengthAwarePaginator $paginator The paginator instance
     * @return array The formatted pagination result
     */
    public function formatPaginationResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'pagination' => $this->getPaginationMetadata($paginator),
            ],
        ];
    }

    /**
     * Parse and validate pagination parameters from request input.
     *
     * @param array $input The input parameters
     * @param int $defaultPerPage The default number of items per page
     * @return array An array containing page and per_page values
     */
    public function parsePaginationParameters(array $input, int $defaultPerPage = 15): array
    {
        $page = (int) Arr::get($input, 'page', 1);
        $page = max(1, $page); // Ensure page is at least 1
        
        $perPage = (int) Arr::get($input, 'per_page', $defaultPerPage);
        $maxPerPage = config('crud.pagination.max_per_page', 100);
        $perPage = max(1, min($perPage, $maxPerPage)); // Ensure perPage is between 1 and maxPerPage
        
        return compact('page', 'perPage');
    }

    /**
     * Check if the request wants simple pagination.
     *
     * @param array $input The input parameters
     * @return bool True if simple pagination is requested
     */
    public function shouldUseSimplePagination(array $input): bool
    {
        return filter_var(
            Arr::get($input, 'simple_pagination', false), 
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Check if the request wants cursor pagination.
     *
     * @param array $input The input parameters
     * @return bool True if cursor pagination is requested
     */
    public function shouldUseCursorPagination(array $input): bool
    {
        return isset($input['cursor']) || filter_var(
            Arr::get($input, 'cursor_pagination', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}