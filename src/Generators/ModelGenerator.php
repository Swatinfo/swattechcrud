<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * ModelGenerator
 *
 * This class is responsible for generating Eloquent model classes based on
 * database table schemas. It handles features like relationships, casts,
 * mutators, accessors, and other model configurations.
 *
 * @package SwatTech\Crud\Generators
 */
class ModelGenerator implements GeneratorInterface
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
     * Generator configuration options.
     *
     * @var array
     */
    protected $options = [];
    
    /**
     * The columns that should be hidden in model arrays.
     *
     * @var array
     */
    protected $defaultHiddenColumns = ['password', 'remember_token', 'api_token'];
    
    /**
     * Create a new ModelGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param RelationshipAnalyzer $relationshipAnalyzer
     */
    public function __construct(StringHelper $stringHelper, RelationshipAnalyzer $relationshipAnalyzer)
    {
        $this->stringHelper = $stringHelper;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        
        // Load default configuration options
        $this->options = Config::get('crud.models', []);
    }
    
    /**
     * Generate model files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for model generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);
        
        // Reset generated files
        $this->generatedFiles = [];
        
        // Analyze relationships
        $relationships = $this->getRelationships($table);
        
        // Get table schema
        $tableSchema = $this->getTableSchema($table);
        
        // Build the model class
        $className = $this->getClassName($table);
        $modelContent = $this->buildClass($table, $tableSchema, $relationships);
        
        // Generate the file path
        $filePath = $this->getPath() . '/' . $className . '.php';
        
        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Write the file
        file_put_contents($filePath, $modelContent);
        
        $this->generatedFiles[] = $filePath;
        
        return $this->generatedFiles;
    }
    
    /**
     * Get the class name for the model.
     *
     * @param string $table The database table name
     * @return string The model class name
     */
    public function getClassName(string $table, string $action = ""): string
    {
        // Convert table name to singular studly case
        return Str::studly(Str::singular($table));
    }
    
    /**
     * Get the namespace for the model.
     *
     * @return string The model namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.models', 'App\\Models');
    }
    
    /**
     * Get the file path for the model.
     *
     * @return string The model file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.models', 'app/Models'));
    }
    
    /**
     * Get the stub template content for model generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path('stubs/crud/model.stub');
        
        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }
        
        return file_get_contents(__DIR__ . '/../stubs/model.stub');
    }
    
    /**
     * Build the model class based on the table schema and relationships.
     *
     * @param string $table The database table name
     * @param array $schema The table schema information
     * @param array $relationships The table relationships
     * @return string The generated model content
     */
    public function buildClass(string $table, array $schema, array $relationships): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $stub = $this->getStub();
        
        // Set up core model features
        $tableDefinition = $this->setupTableName($table, $className);
        $primaryKey = $this->setupPrimaryKey($schema['primary_key'] ?? 'id');
        $timestamps = $this->setupTimestamps($schema['has_timestamps'] ?? true);
        $softDeletes = $this->setupSoftDeletes($schema['has_soft_deletes'] ?? false);
        
        // Generate attributes and features
        $fillable = $this->generateFillableAttributes($schema['columns'] ?? []);
        $guarded = $this->generateGuardedAttributes($schema['columns'] ?? []);
        $casts = $this->generateCasts($schema['columns'] ?? []);
        $hidden = $this->generateHiddenAttributes($schema['columns'] ?? []);
        $accessors = $this->generateAccessors($schema['columns'] ?? []);
        $mutators = $this->generateMutators($schema['columns'] ?? []);
        $scopes = $this->generateScopes($this->options);
        $relationshipMethods = $this->generateRelationshipMethods($relationships);
        $traits = $this->setupTraits($this->options);
        $modelEvents = $this->setupModelEvents();
        $factoryReference = $this->setupFactoryReference();
        
        // Add imports
        $imports = [
            'Illuminate\Database\Eloquent\Model',
        ];
        
        if ($softDeletes === true) {
            $imports[] = 'Illuminate\Database\Eloquent\SoftDeletes';
            $traits .= "    use SoftDeletes;\n";
        }
        
        if (!empty($schema['has_uuid']) && $schema['has_uuid'] === true) {
            $imports[] = 'Illuminate\Database\Eloquent\Concerns\HasUuids';
            $traits .= "    use HasUuids;\n";
        }
        
        $importsStr = '';
        foreach ($imports as $import) {
            $importsStr .= "use {$import};\n";
        }
        
        // Replace stub placeholders
        $modelContent = str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{table}}',
            '{{primaryKey}}',
            '{{timestamps}}',
            '{{traits}}',
            '{{fillable}}',
            '{{guarded}}',
            '{{hidden}}',
            '{{casts}}',
            '{{accessors}}',
            '{{mutators}}',
            '{{scopes}}',
            '{{relationships}}',
            '{{events}}',
            '{{factory}}'
        ], [
            $namespace,
            $importsStr,
            $className,
            $tableDefinition,
            $primaryKey,
            $timestamps,
            $traits,
            $fillable,
            $guarded,
            $hidden,
            $casts,
            $accessors,
            $mutators,
            $scopes,
            $relationshipMethods,
            $modelEvents,
            $factoryReference
        ], $stub);
        
        return $modelContent;
    }
    
    /**
     * Generate relationship methods based on analyzed relationships.
     *
     * @param array $relationships The relationships data
     * @return string Generated relationship method code
     */
    public function generateRelationshipMethods(array $relationships): string
    {
        $methods = '';
        
        if (empty($relationships['method_definitions'] ?? [])) {
            return $methods;
        }
        
        foreach ($relationships['method_definitions'] as $method) {
            $methods .= $method['code'] . "\n\n";
        }
        
        return $methods;
    }
    
    /**
     * Set up the table name property for the model.
     *
     * @param string $table The database table name
     * @param string $className The model class name
     * @return string Generated table property code
     */
    public function setupTableName(string $table, string $className): string
    {
        $defaultTable = Str::snake(Str::plural($className));
        
        if ($table === $defaultTable) {
            return '';
        }
        
        return "    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = '{$table}';\n";
    }
    
    /**
     * Set up the primary key property for the model.
     *
     * @param string $primaryKey The primary key column name
     * @return string Generated primary key property code
     */
    public function setupPrimaryKey(string $primaryKey): string
    {
        if ($primaryKey === 'id') {
            return '';
        }
        
        return "    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected \$primaryKey = '{$primaryKey}';\n";
    }
    
    /**
     * Set up timestamps configuration for the model.
     *
     * @param bool $timestamps Whether the table has timestamp columns
     * @return string Generated timestamps property code
     */
    public function setupTimestamps(bool $timestamps): string
    {
        if ($timestamps) {
            return '';
        }
        
        return "    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public \$timestamps = false;\n";
    }
    
    /**
     * Set up soft deletes configuration for the model.
     *
     * @param bool $softDeletes Whether the table has a soft delete column
     * @return bool
     */
    public function setupSoftDeletes(bool $softDeletes): bool
    {
        return $softDeletes;
    }
    
    /**
     * Generate fillable attributes for the model.
     *
     * @param array $columns The table columns
     * @return string Generated fillable property code
     */
    public function generateFillableAttributes(array $columns): string
    {
        if ($this->options['mass_assignable_strategy'] !== 'fillable') {
            return '';
        }
        
        $fillable = [];
        
        foreach ($columns as $column => $details) {
            // Skip primary key, timestamps, and soft delete columns
            if ($column === $details['primary_key'] || 
                in_array($column, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            
            $fillable[] = "'" . $column . "'";
        }
        
        $fillableStr = implode(', ', $fillable);
        
        return "    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected \$fillable = [{$fillableStr}];\n";
    }
    
    /**
     * Generate guarded attributes for the model.
     *
     * @param array $columns The table columns
     * @return string Generated guarded property code
     */
    public function generateGuardedAttributes(array $columns): string
    {
        if ($this->options['mass_assignable_strategy'] !== 'guarded') {
            return '';
        }
        
        if ($this->options['include_all_columns'] === true) {
            return "    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected \$guarded = [];\n";
        }
        
        return "    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected \$guarded = ['id'];\n";
    }
    
    /**
     * Generate hidden attributes for the model.
     *
     * @param array $columns The table columns
     * @return string Generated hidden property code
     */
    public function generateHiddenAttributes(array $columns): string
    {
        $hidden = [];
        
        foreach ($columns as $column => $details) {
            if (in_array($column, $this->defaultHiddenColumns)) {
                $hidden[] = "'" . $column . "'";
            }
        }
        
        if (empty($hidden)) {
            return '';
        }
        
        $hiddenStr = implode(', ', $hidden);
        
        return "    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected \$hidden = [{$hiddenStr}];\n";
    }
    
    /**
     * Generate accessor methods for the model.
     *
     * @param array $columns The table columns
     * @return string Generated accessor methods code
     */
    public function generateAccessors(array $columns): string
    {
        $accessors = '';
        
        foreach ($columns as $column => $details) {
            // Generate accessors for specific column types that might need formatting
            if (in_array($details['type'], ['boolean', 'json', 'date', 'datetime'])) {
                $methodName = 'get' . Str::studly($column) . 'Attribute';
                
                switch ($details['type']) {
                    case 'boolean':
                        $accessors .= "    /**
     * Get the {$column} as a boolean value.
     *
     * @return bool
     */
    public function {$methodName}(\$value)
    {
        return (bool) \$value;
    }\n\n";
                        break;
                        
                    case 'json':
                        $accessors .= "    /**
     * Get the {$column} as a decoded JSON object.
     *
     * @return array|object|null
     */
    public function {$methodName}(\$value)
    {
        return json_decode(\$value);
    }\n\n";
                        break;
                }
            }
        }
        
        return $accessors;
    }
    
    /**
     * Generate mutator methods for the model.
     *
     * @param array $columns The table columns
     * @return string Generated mutator methods code
     */
    public function generateMutators(array $columns): string
    {
        $mutators = '';
        
        foreach ($columns as $column => $details) {
            // Generate mutators for specific column types that might need special handling
            if (in_array($details['type'], ['string', 'password', 'json'])) {
                $methodName = 'set' . Str::studly($column) . 'Attribute';
                
                switch ($details['type']) {
                    case 'string':
                        if ($column === 'slug' || Str::endsWith($column, '_slug')) {
                            $mutators .= "    /**
     * Set the {$column} with automatic slugification.
     *
     * @param string \$value
     * @return void
     */
    public function {$methodName}(\$value)
    {
        \$this->attributes['{$column}'] = Str::slug(\$value);
    }\n\n";
                        }
                        break;
                        
                    case 'password':
                        $mutators .= "    /**
     * Set the {$column} with automatic hashing.
     *
     * @param string \$value
     * @return void
     */
    public function {$methodName}(\$value)
    {
        \$this->attributes['{$column}'] = bcrypt(\$value);
    }\n\n";
                        break;
                        
                    case 'json':
                        $mutators .= "    /**
     * Set the {$column} as a JSON encoded string.
     *
     * @param array \$value
     * @return void
     */
    public function {$methodName}(\$value)
    {
        \$this->attributes['{$column}'] = json_encode(\$value);
    }\n\n";
                        break;
                }
            }
        }
        
        return $mutators;
    }
    
    /**
     * Set up model events for the model.
     *
     * @return string Generated model events code
     */
    public function setupModelEvents(): string
    {
        $events = '';
        
        if (!isset($this->options['model_events']) || $this->options['model_events'] !== true) {
            return $events;
        }
        
        $events .= "    /**
     * The model's default events.
     *
     * @var array
     */
    protected \$dispatchesEvents = [
        'created' => \App\Events\ModelCreated::class,
        'updated' => \App\Events\ModelUpdated::class,
        'deleted' => \App\Events\ModelDeleted::class,
    ];\n\n";
            
        $events .= "    /**
     * The \"booting\" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function (\$model) {
            // Code to run before creating a model...
        });
        
        static::created(function (\$model) {
            // Code to run after a model is created...
        });
        
        static::updating(function (\$model) {
            // Code to run before updating a model...
        });
        
        static::updated(function (\$model) {
            // Code to run after a model is updated...
        });
        
        static::deleting(function (\$model) {
            // Code to run before deleting a model...
        });
        
        static::deleted(function (\$model) {
            // Code to run after a model is deleted...
        });
    }\n";
        
        return $events;
    }
    
    /**
     * Generate scope methods for the model.
     *
     * @param array $options Generator options
     * @return string Generated scope methods code
     */
    public function generateScopes(array $options): string
    {
        $scopes = '';
        
        if (!isset($options['generate_scopes']) || $options['generate_scopes'] !== true) {
            return $scopes;
        }
        
        // Common scopes
        $scopes .= "    /**
     * Scope a query to only include active records.
     *
     * @param \Illuminate\Database\Eloquent\Builder \$query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive(\$query)
    {
        return \$query->where('is_active', true);
    }\n\n";
        
        $scopes .= "    /**
     * Scope a query to order by created date in descending order.
     *
     * @param \Illuminate\Database\Eloquent\Builder \$query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatest(\$query)
    {
        return \$query->orderBy('created_at', 'desc');
    }\n\n";
        
        $scopes .= "    /**
     * Scope a query to filter by a search term.
     *
     * @param \Illuminate\Database\Eloquent\Builder \$query
     * @param string \$term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(\$query, \$term)
    {
        if (\$term) {
            return \$query->where(function(\$query) use (\$term) {
                // Add searchable columns here
                \$query->where('name', 'LIKE', \"%{\$term}%\")
                      ->orWhere('description', 'LIKE', \"%{\$term}%\");
            });
        }
        
        return \$query;
    }\n";
        
        return $scopes;
    }
    
    /**
     * Generate attribute casts for the model.
     *
     * @param array $columns The table columns
     * @return string Generated casts property code
     */
    public function generateCasts(array $columns): string
    {
        if (!isset($this->options['add_attribute_casts']) || $this->options['add_attribute_casts'] !== true) {
            return '';
        }
        
        $casts = [];
        
        foreach ($columns as $column => $details) {
            $castType = $this->mapColumnTypeToCast($details['type']);
            
            if ($castType) {
                $casts[] = "'" . $column . "' => '" . $castType . "'";
            }
        }
        
        if (empty($casts)) {
            return '';
        }
        
        $castsStr = implode(",\n        ", $casts);
        
        return "    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected \$casts = [
        {$castsStr}
    ];\n";
    }
    
    /**
     * Set up factory reference for the model.
     *
     * @return string Generated factory reference code
     */
    public function setupFactoryReference(): string
    {
        if (!isset($this->options['with_factory']) || $this->options['with_factory'] !== true) {
            return '';
        }
        
        return "    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Database\Factories\\" . $this->getClassName($this->options['table']) . "Factory::new();
    }\n";
    }
    
    /**
     * Set up traits for the model.
     *
     * @param array $options Generator options
     * @return string Generated traits code
     */
    public function setupTraits(array $options): string
    {
        $traits = '';
        
        if (isset($options['traits']) && is_array($options['traits']) && !empty($options['traits'])) {
            $traits = "    use " . implode(', ', $options['traits']) . ";\n";
        }
        
        return $traits;
    }
    
    /**
     * Map database column type to Eloquent cast type.
     *
     * @param string $columnType The database column type
     * @return string|null The corresponding cast type or null
     */
    protected function mapColumnTypeToCast(string $columnType): ?string
    {
        $mapping = [
            'int' => 'integer',
            'integer' => 'integer',
            'bigint' => 'integer',
            'tinyint' => 'boolean',
            'boolean' => 'boolean',
            'float' => 'float',
            'double' => 'double',
            'decimal' => 'decimal',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'json' => 'array',
            'array' => 'array',
            'object' => 'object',
            'collection' => 'collection',
        ];
        
        return $mapping[$columnType] ?? null;
    }
    
    /**
     * Get table schema information.
     *
     * @param string $table The database table name
     * @return array The table schema
     */
    protected function getTableSchema(string $table): array
    {
        // In a real implementation, this would use a SchemaAnalyzer or similar
        // For now, we'll just return a simplified schema
        return [
            'columns' => [],
            'primary_key' => 'id',
            'has_timestamps' => true,
            'has_soft_deletes' => false
        ];
    }
    
    /**
     * Get relationships for the table.
     *
     * @param string $table The database table name
     * @return array The relationship data
     */
    protected function getRelationships(string $table): array
    {
        try {
            return $this->relationshipAnalyzer->analyze($table)->getResults();
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Set configuration options for the generator.
     *
     * @param array $options Configuration options
     * @return self Returns the generator instance for method chaining
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
    
    /**
     * Get a list of all generated file paths.
     *
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }
    
    /**
     * Determine if the generator supports customization.
     *
     * @return bool True if the generator supports customization
     */
    public function supportsCustomization(): bool
    {
        return true;
    }
}