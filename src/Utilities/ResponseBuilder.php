<?php

namespace SwatTech\Crud\Utilities;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * ResponseBuilder
 *
 * A utility class for standardizing API response formats.
 * Provides consistent structure for success and error responses,
 * with support for collections, pagination, transformers, and HATEOAS links.
 *
 * @package SwatTech\Crud\Utilities
 */
class ResponseBuilder
{
    /**
     * @var int The HTTP status code for the response
     */
    protected int $statusCode = 200;

    /**
     * @var array Additional metadata for the response
     */
    protected array $meta = [];

    /**
     * @var array HATEOAS links for the response
     */
    protected array $links = [];

    /**
     * Create a successful response with data.
     *
     * @param mixed $data The data to include in the response
     * @param string $message An optional success message
     * @param int $code The HTTP status code (default: 200)
     * @return JsonResponse
     */
    public function success(mixed $data, string $message = '', int $code = 200): JsonResponse
    {
        $this->setStatusCode($code);
        
        $response = [
            'success' => true,
            'data' => $data,
        ];

        if (!empty($message)) {
            $response['message'] = $message;
        }

        return $this->buildResponse($response);
    }

    /**
     * Create an error response.
     *
     * @param string $message The error message
     * @param int $code The HTTP status code (default: 400)
     * @param array $errors Additional error details
     * @return JsonResponse
     */
    public function error(string $message, int $code = 400, array $errors = []): JsonResponse
    {
        $this->setStatusCode($code);
        
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $this->buildResponse($response);
    }

    /**
     * Format a collection of resources.
     *
     * @param Collection $collection The collection to format
     * @param callable|null $transformer Optional transformer function/callback
     * @return JsonResponse
     */
    public function collection(Collection $collection, $transformer = null): JsonResponse
    {
        $data = $collection;
        
        if ($transformer !== null) {
            $data = $this->transformData($collection, $transformer);
        }

        return $this->success($data);
    }

    /**
     * Format a single resource item.
     *
     * @param mixed $item The resource to format
     * @param callable|null $transformer Optional transformer function/callback
     * @return JsonResponse
     */
    public function item($item, $transformer = null): JsonResponse
    {
        $data = $item;
        
        if ($transformer !== null) {
            $data = $this->transformItem($item, $transformer);
        }

        return $this->success($data);
    }

    /**
     * Format a paginated result.
     *
     * @param LengthAwarePaginator $paginator The paginator instance
     * @param callable|null $transformer Optional transformer for each item
     * @return JsonResponse
     */
    public function paginated(LengthAwarePaginator $paginator, $transformer = null): JsonResponse
    {
        $items = $paginator->items();
        
        if ($transformer !== null) {
            $items = $this->transformData($items, $transformer);
        }

        // Add pagination metadata
        $this->withMeta([
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]
        ]);

        // Add pagination links
        $this->withLinks([
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ]);

        return $this->success($items);
    }

    /**
     * Add metadata to the response.
     *
     * @param array $meta The metadata to add
     * @return self Returns the current instance for chaining
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    /**
     * Add HATEOAS links to the response.
     *
     * @param array $links The links to add
     * @return self Returns the current instance for chaining
     */
    public function withLinks(array $links): self
    {
        $this->links = array_merge($this->links, $links);
        return $this;
    }

    /**
     * Create a cached response.
     *
     * @param mixed $data The data or a callback that returns the data
     * @param int $ttl The time-to-live in seconds
     * @return JsonResponse
     */
    public function cached(mixed $data, int $ttl): JsonResponse
    {
        // Generate a unique cache key based on the request
        $cacheKey = 'api_response_' . md5(request()->fullUrl());
        
        // Retrieve from cache or generate fresh
        $result = Cache::remember($cacheKey, $ttl, function () use ($data) {
            // If $data is callable, execute it to get the result
            if (is_callable($data)) {
                return $data();
            }
            
            return $data;
        });

        return $this->success($result);
    }

    /**
     * Transform data using a transformer.
     *
     * @param mixed $data The data to transform
     * @param callable|object $transformer The transformer function or object
     * @return mixed The transformed data
     */
    public function transform(mixed $data, $transformer): mixed
    {
        if (is_array($data) || $data instanceof Collection) {
            return $this->transformData($data, $transformer);
        }
        
        return $this->transformItem($data, $transformer);
    }

    /**
     * Set the HTTP status code for the response.
     *
     * @param int $code The HTTP status code
     * @return self Returns the current instance for chaining
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Transform a collection of items.
     *
     * @param array|Collection $data The collection of items
     * @param callable|object $transformer The transformer
     * @return array The transformed collection
     */
    protected function transformData($data, $transformer): array
    {
        $result = [];
        
        foreach ($data as $item) {
            $result[] = $this->transformItem($item, $transformer);
        }
        
        return $result;
    }

    /**
     * Transform a single item.
     *
     * @param mixed $item The item to transform
     * @param callable|object $transformer The transformer
     * @return mixed The transformed item
     */
    protected function transformItem($item, $transformer): mixed
    {
        if (is_callable($transformer)) {
            return $transformer($item);
        }
        
        // Handle Laravel API Resources
        if (method_exists($transformer, 'make')) {
            return $transformer::make($item)->resolve();
        }
        
        // Handle transformer objects with a transform method
        if (is_object($transformer) && method_exists($transformer, 'transform')) {
            return $transformer->transform($item);
        }
        
        // Handle transformer class names
        if (is_string($transformer) && class_exists($transformer)) {
            $instance = new $transformer();
            if (method_exists($instance, 'transform')) {
                return $instance->transform($item);
            }
        }
        
        // If no transformation could be applied, return item as is
        return $item;
    }

    /**
     * Build the final response with meta and links.
     *
     * @param array $baseResponse The base response array
     * @return JsonResponse
     */
    protected function buildResponse(array $baseResponse): JsonResponse
    {
        // Add meta data if present
        if (!empty($this->meta)) {
            $baseResponse['meta'] = $this->meta;
        }
        
        // Add links if present
        if (!empty($this->links)) {
            $baseResponse['links'] = $this->links;
        }

        // Clear meta and links after building response
        $this->meta = [];
        $this->links = [];
        
        return new JsonResponse($baseResponse, $this->statusCode);
    }
}