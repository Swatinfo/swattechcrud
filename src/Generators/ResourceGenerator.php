<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;

/**
 * ResourceGenerator
 *
 * This class is responsible for generating API resource classes for the application.
 * API Resources transform models into JSON responses with control over the
 * included data, relationships, and format.
 *
 * @package SwatTech\Crud\Generators
 */
class ResourceGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The relationship analyzer instance.
     *
     * @var RelationshipAnalyzer
     */
    protected $relationshipAnalyzer;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Resource configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ResourceGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param RelationshipAnalyzer $relationshipAnalyzer
     */
    public function __construct(StringHelper $stringHelper, RelationshipAnalyzer $relationshipAnalyzer)
    {
        $this->stringHelper = $stringHelper;
        $this->relationshipAnalyzer = $relationshipAnalyzer;

        // Load default configuration options
        $this->options = Config::get('crud.resources', []);
    }

    /**
     * Generate API resource files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for resource generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Get table columns and relationships
        $columns = $this->getTableColumns($table);
        $relationships = $this->relationshipAnalyzer->analyze($table);

        // Generate the resource class
        $resourcePath = $this->generateResource($table, $columns, $relationships, $this->options);

        // Generate the resource collection class if needed
        if ($this->options['generate_collection'] ?? true) {
            $collectionPath = $this->generateResourceCollection($table);
            $this->generatedFiles[] = $collectionPath;
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the resource.
     *
     * @param string $table The database table name
     * @return string The resource class name
     */
    public function getClassName(string $table): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Resource';
    }

    /**
     * Get the namespace for the resource.
     *
     * @return string The resource namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.resources', 'App\\Http\\Resources');
    }

    /**
     * Get the file path for the resource.
     *
     * @return string The resource file path
     */
    public function getPath(): string
    {
        return base_path(Config::get('crud.paths.resources', 'app/Http/Resources'));
    }

    /**
     * Get the stub template content for resource generation.
     *
     * @param string $type The type of stub (resource or collection)
     * @return string The stub template content
     */
    public function getStub(string $type = 'resource'): string
    {
        $stubName = $type === 'collection' ? 'resource_collection.stub' : 'resource.stub';
        $customStubPath = resource_path("stubs/crud/{$stubName}");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}");
    }

    /**
     * Generate a resource file for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @param array $options Options for resource generation
     * @return string The generated file path
     */
    protected function generateResource(string $table, array $columns, array $relationships, array $options): string
    {
        $className = $this->getClassName($table);
        $content = $this->buildClass($table, $columns, $relationships, $options);

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
     * Build the resource class based on options.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @param array $options The options for resource generation
     * @return string The generated resource content
     */
    public function buildClass(string $table, array $columns, array $relationships, array $options): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub();

        // Generate data transformation code
        $dataTransformation = $this->implementDataTransformation($columns, $options);

        // Setup relationship inclusion
        $relationshipInclusion = $this->setupRelationshipInclusion($relationships, $options);

        // Generate conditional fields
        $conditionalFields = $this->generateConditionalFields($columns, $options);

        // Implement metadata
        $metaData = $this->implementMetaData($options);

        // Setup pagination handling
        $paginationHandling = $this->setupPaginationHandling($options);

        // Implement link generation
        $linkGeneration = $this->implementLinkGeneration($table, $options);

        // Setup versioning
        $versioning = $this->setupVersioning($options);

        // Implement custom serialization
        $customSerialization = $this->implementCustomSerialization($options);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{dataTransformation}}',
            '{{relationshipInclusion}}',
            '{{conditionalFields}}',
            '{{metaData}}',
            '{{paginationHandling}}',
            '{{linkGeneration}}',
            '{{versioning}}',
            '{{customSerialization}}',
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $dataTransformation,
            $relationshipInclusion,
            $conditionalFields,
            $metaData,
            $paginationHandling,
            $linkGeneration,
            $versioning,
            $customSerialization,
        ], $stub);
    }

    /**
     * Generate a resource collection file for the specified table.
     *
     * @param string $table The database table name
     * @return string The generated file path
     */
    public function generateResourceCollection(string $table): string
    {
        $resourceClassName = $this->getClassName($table);
        $collectionClassName = Str::studly(Str::singular($table)) . 'Collection';
        $namespace = $this->getNamespace();
        $stub = $this->getStub('collection');

        $content = str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{resourceClass}}',
        ], [
            $namespace,
            $collectionClassName,
            $resourceClassName,
        ], $stub);

        $filePath = $this->getPath() . '/' . $collectionClassName . '.php';

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * Implement data transformation for resource.
     *
     * @param array $columns The table columns
     * @param array $options The options for resource generation
     * @return string The data transformation code
     */
    public function implementDataTransformation(array $columns, array $options): string
    {
        $transformationCode = '';

        // Fields to exclude from the resource
        $excludeFields = $options['exclude_fields'] ?? ['password', 'remember_token'];
        
        foreach ($columns as $column) {
            if (in_array($column['name'], $excludeFields)) {
                continue;
            }

            // Format dates according to options
            if (in_array($column['type'], ['date', 'datetime', 'timestamp'])) {
                $format = $options['date_format'] ?? 'Y-m-d H:i:s';
                $transformationCode .= "            '{$column['name']}' => \$this->{$column['name']} ? \$this->{$column['name']}->format('$format') : null,\n";
            } 
            // Custom formatting for specific field types
            elseif ($column['type'] === 'decimal' || $column['type'] === 'float') {
                $transformationCode .= "            '{$column['name']}' => (float) \$this->{$column['name']},\n";
            } 
            // Convert JSON fields to arrays
            elseif ($column['type'] === 'json') {
                $transformationCode .= "            '{$column['name']}' => json_decode(\$this->{$column['name']}),\n";
            } 
            // Default handling
            else {
                $transformationCode .= "            '{$column['name']}' => \$this->{$column['name']},\n";
            }
        }

        return $transformationCode;
    }

    /**
     * Setup relationship inclusion in the resource.
     *
     * @param array $relationships The table relationships
     * @param array $options The options for resource generation
     * @return string The relationship inclusion code
     */
    public function setupRelationshipInclusion(array $relationships, array $options): string
    {
        $relationshipCode = '';

        if (!empty($relationships)) {
            $relationshipCode = "\n            // Relationships\n";

            // Load relationships when requested
            $relationshipCode .= "            // Include relationships when requested or loaded\n";
            
            foreach ($relationships as $type => $relations) {
                foreach ($relations as $relation) {
                    $relationName = $relation['method'] ?? Str::camel($relation['related_table']);
                    $resourceClass = Str::studly(Str::singular($relation['related_table'])) . 'Resource';
                    
                    $relationshipCode .= "            '{$relationName}' => \$this->when(\$this->relationLoaded('{$relationName}'), function () {\n";
                    
                    if (in_array($type, ['hasMany', 'belongsToMany', 'morphMany'])) {
                        $relationshipCode .= "                return {$resourceClass}::collection(\$this->{$relationName});\n";
                    } else {
                        $relationshipCode .= "                return \$this->{$relationName} ? new {$resourceClass}(\$this->{$relationName}) : null;\n";
                    }
                    
                    $relationshipCode .= "            }),\n";
                }
            }
        }

        return $relationshipCode;
    }

    /**
     * Generate conditional fields for the resource.
     *
     * @param array $columns The table columns
     * @param array $options The options for resource generation
     * @return string The conditional fields code
     */
    public function generateConditionalFields(array $columns, array $options): string
    {
        $conditionalCode = '';

        // If there are any fields to conditionally include
        if (!empty($options['conditional_fields'])) {
            $conditionalCode = "\n            // Conditional fields\n";
            
            foreach ($options['conditional_fields'] as $field => $condition) {
                if (is_string($condition)) {
                    // Simple permission-based condition
                    $conditionalCode .= "            '{$field}' => \$this->when(auth()->user() && auth()->user()->can('{$condition}'), function () {\n";
                    $conditionalCode .= "                return \$this->{$field};\n";
                    $conditionalCode .= "            }),\n";
                } else if (is_callable($condition)) {
                    // Advanced custom condition using a callback
                    $conditionalCode .= "            // Custom condition for {$field}\n";
                    $conditionalCode .= "            '{$field}' => \$this->when(/* custom condition here */, function () {\n";
                    $conditionalCode .= "                return \$this->{$field};\n";
                    $conditionalCode .= "            }),\n";
                }
            }
        }

        // Add computed/derived fields
        if (!empty($options['computed_fields'])) {
            $conditionalCode .= "\n            // Computed fields\n";
            
            foreach ($options['computed_fields'] as $field => $computationMethod) {
                $conditionalCode .= "            '{$field}' => \$this->{$computationMethod}(),\n";
            }
        }

        return $conditionalCode;
    }

    /**
     * Implement metadata for the resource.
     *
     * @param array $options The options for resource generation
     * @return string The metadata code
     */
    public function implementMetaData(array $options): string
    {
        if (!($options['include_meta'] ?? false)) {
            return '';
        }

        return "
    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return array
     */
    public function with(\$request)
    {
        return [
            'meta' => [
                'created_at' => now()->toIso8601String(),
                'version' => config('app.version', '1.0'),
            ],
        ];
    }";
    }

    /**
     * Setup pagination handling for resource collections.
     *
     * @param array $options The options for resource generation
     * @return string The pagination handling code
     */
    public function setupPaginationHandling(array $options): string
    {
        if (!($options['handle_pagination'] ?? false)) {
            return '';
        }

        return "
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return array
     */
    public function toArray(\$request)
    {
        if (method_exists(\$this->resource, 'items')) {
            // This is a paginated collection
            return [
                'data' => \$this->collection,
                'pagination' => [
                    'total' => \$this->resource->total(),
                    'count' => \$this->resource->count(),
                    'per_page' => \$this->resource->perPage(),
                    'current_page' => \$this->resource->currentPage(),
                    'total_pages' => \$this->resource->lastPage(),
                    'has_more_pages' => \$this->resource->hasMorePages(),
                ],
            ];
        }
        
        // Regular collection
        return parent::toArray(\$request);
    }";
    }

    /**
     * Implement link generation for resources.
     *
     * @param string $table The database table name
     * @param array $options The options for resource generation
     * @return string The link generation code
     */
    public function implementLinkGeneration(string $table, array $options): string
    {
        if (!($options['include_links'] ?? false)) {
            return '';
        }

        $routeName = Str::plural(Str::kebab(Str::singular($table)));

        return "
    /**
     * Get HATEOAS links for the resource.
     *
     * @return array
     */
    protected function getLinks()
    {
        return [
            'self' => route('{$routeName}.show', \$this->id),
            'collection' => route('{$routeName}.index'),
        ];
    }
    
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return array
     */
    public function toArray(\$request)
    {
        \$data = parent::toArray(\$request);
        \$data['_links'] = \$this->getLinks();
        
        return \$data;
    }";
    }

    /**
     * Setup versioning for resources.
     *
     * @param array $options The options for resource generation
     * @return string The versioning code
     */
    public function setupVersioning(array $options): string
    {
        if (!($options['versioned'] ?? false)) {
            return '';
        }

        return "
    /**
     * The API version to use for this resource.
     *
     * @var string
     */
    protected \$version;

    /**
     * Create a new resource instance.
     *
     * @param  mixed  \$resource
     * @param  string|null  \$version
     * @return void
     */
    public function __construct(\$resource, \$version = null)
    {
        parent::__construct(\$resource);
        \$this->version = \$version ?? request()->header('Accept-Version', 'v1');
    }
    
    /**
     * Transform the resource into an array based on version.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return array
     */
    public function toArray(\$request)
    {
        \$method = 'toArray' . Str::studly(\$this->version);
        
        if (method_exists(\$this, \$method)) {
            return \$this->{\$method}(\$request);
        }
        
        return parent::toArray(\$request);
    }";
    }

    /**
     * Implement custom serialization for resources.
     *
     * @param array $options The options for resource generation
     * @return string The custom serialization code
     */
    public function implementCustomSerialization(array $options): string
    {
        if (!($options['custom_serialization'] ?? false)) {
            return '';
        }

        return "
    /**
     * Customize the outgoing response for the resource.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @param  \Illuminate\Http\Response  \$response
     * @return void
     */
    public function withResponse(\$request, \$response)
    {
        \$response->header('X-Value', 'True');
        
        if (config('app.debug')) {
            \$response->header('X-Debug-Mode', 'True');
        }
    }";
    }

    /**
     * Get the columns for the specified table.
     *
     * @param string $table The database table name
     * @return array The table columns
     */
    protected function getTableColumns(string $table): array
    {
        // This is a placeholder. In a real implementation, you would use
        // a SchemaHelper or DatabaseAnalyzer to get the actual table columns
        // For now, we'll return a simple array with common fields
        
        return [
            ['name' => 'id', 'type' => 'integer'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'description', 'type' => 'text'],
            ['name' => 'price', 'type' => 'decimal'],
            ['name' => 'created_at', 'type' => 'timestamp'],
            ['name' => 'updated_at', 'type' => 'timestamp'],
        ];
    }
}