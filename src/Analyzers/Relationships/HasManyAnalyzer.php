<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * HasManyAnalyzer
 *
 * Analyzes database tables to detect hasMany relationships based on foreign keys
 * and naming conventions. This analyzer identifies child tables that reference
 * the current table's primary key.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class HasManyAnalyzer implements AnalyzerInterface
{
    /**
     * The SchemaHelper instance.
     *
     * @var SchemaHelper
     */
    protected $schemaHelper;

    /**
     * The RelationshipHelper instance.
     *
     * @var RelationshipHelper
     */
    protected $relationshipHelper;

    /**
     * The database connection to use.
     *
     * @var string|null
     */
    protected $connection = null;

    /**
     * The analysis results.
     *
     * @var array
     */
    protected $results = [];

    /**
     * Create a new HasManyAnalyzer instance.
     *
     * @param SchemaHelper $schemaHelper
     * @param RelationshipHelper $relationshipHelper
     */
    public function __construct(SchemaHelper $schemaHelper, RelationshipHelper $relationshipHelper)
    {
        $this->schemaHelper = $schemaHelper;
        $this->relationshipHelper = $relationshipHelper;
    }

    /**
     * Analyze the specified database table for hasMany relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Find tables that have foreign keys pointing to this table
        $childTables = $this->identifyChildTables($table);
        
        // Map the one-to-many relationships
        $relationships = $this->mapOneToManyRelationships($table, $childTables);
        
        // Handle custom foreign keys defined in configuration
        $customRelationships = $this->handleCustomForeignKeys($table);
        
        // Merge custom relationships with detected ones
        $relationships = array_merge($relationships, $customRelationships);
        
        // Detect inverse belongsTo relationships
        $this->detectInverseBelongsTo($relationships);
        
        // Check for cascade options
        $this->implementCascadeOptions($relationships);
        
        // Check for soft deletes in child tables
        $this->detectSoftDeletesInChildren($relationships);
        
        // Map collection relationship helpers
        $collectionRelationships = $this->mapCollectionRelationships($relationships);
        
        // Create method definitions for each relationship
        $methodDefinitions = $this->createMethodDefinitions($relationships);
        
        $this->results = [
            'table' => $table,
            'relationships' => $relationships,
            'method_definitions' => $methodDefinitions,
            'collection_methods' => $collectionRelationships,
            'type' => 'hasMany'
        ];
        
        return $this;
    }

    /**
     * Identify child tables that reference the current table.
     *
     * @param string $table The name of the database table
     * @return array List of child tables with their foreign keys
     */
    public function identifyChildTables(string $table): array
    {
        $childTables = [];
        $allTables = $this->schemaHelper->getAllTables();
        $primaryKey = $this->schemaHelper->getPrimaryKey($table, $this->connection);
        
        if (!$primaryKey) {
            return $childTables;
        }
        
        foreach ($allTables as $otherTable) {
            if ($otherTable === $table) {
                continue;
            }
            
            $foreignKeys = $this->schemaHelper->getForeignKeys($otherTable, $this->connection);
            
            foreach ($foreignKeys as $fk) {
                $foreignTable = $fk['foreign_table'] ?? null;
                $foreignColumn = $fk['foreign_columns'][0] ?? null;
                $localColumn = $fk['local_columns'][0] ?? null;
                
                if ($foreignTable === $table && $foreignColumn === $primaryKey) {
                    // Check if this should be hasOne instead of hasMany
                    $isUnique = in_array($localColumn, $this->schemaHelper->getUniqueColumns($otherTable, $this->connection));
                    
                    if (!$isUnique) {
                        $childTables[] = [
                            'table' => $otherTable,
                            'foreign_key' => $localColumn,
                            'related_key' => $foreignColumn,
                            'on_delete' => $fk['on_delete'] ?? null,
                            'on_update' => $fk['on_update'] ?? null
                        ];
                    }
                }
            }
        }
        
        return $childTables;
    }

    /**
     * Map the one-to-many relationships between tables.
     *
     * @param string $table The parent table name
     * @param array $childTables The child tables information
     * @return array The mapped relationships
     */
    public function mapOneToManyRelationships(string $table, array $childTables): array
    {
        $relationships = [];
        
        foreach ($childTables as $childTable) {
            $methodName = RelationshipHelper::formatRelationshipMethod('hasMany', $childTable['table']);
            
            $relationships[] = [
                'type' => 'hasMany',
                'local_table' => $table,
                'related_table' => $childTable['table'],
                'foreign_key' => $childTable['foreign_key'],
                'related_key' => $childTable['related_key'],
                'method' => $methodName,
                'on_delete' => $childTable['on_delete'],
                'on_update' => $childTable['on_update'],
                'has_soft_deletes' => false, // Will be updated by detectSoftDeletesInChildren
                'comment' => "Get the {$childTable['table']} associated with this record",
            ];
        }
        
        return $relationships;
    }

    /**
     * Detect inverse belongsTo relationships for the hasMany relationships.
     *
     * @param array $relationships The hasMany relationships
     * @return void
     */
    public function detectInverseBelongsTo(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'hasMany') {
                $relationship['inverse_type'] = 'belongsTo';
                $relationship['inverse_method'] = RelationshipHelper::formatRelationshipMethod(
                    'belongsTo', 
                    $relationship['local_table']
                );
            }
        }
    }

    /**
     * Handle custom foreign key relationships defined in configuration.
     *
     * @param string $table The name of the database table
     * @return array Additional custom relationships
     */
    public function handleCustomForeignKeys(string $table): array
    {
        $customRelationships = [];
        $config = config('crud.relationships.custom_relationships', []);
        
        if (isset($config[$table])) {
            foreach ($config[$table] as $relation) {
                if ($relation['type'] === 'hasMany') {
                    $customRelationships[] = [
                        'type' => 'hasMany',
                        'local_table' => $table,
                        'related_table' => $relation['related_table'] ?? Str::snake($relation['model']),
                        'foreign_key' => $relation['foreign_key'],
                        'related_key' => $relation['related_key'] ?? 'id',
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod('hasMany', $relation['related_table'] ?? Str::snake($relation['model'])),
                        'is_custom' => true,
                        'has_soft_deletes' => false,
                        'comment' => $relation['comment'] ?? "Get the {$relation['model']} records associated with this record",
                    ];
                }
            }
        }
        
        return $customRelationships;
    }

    /**
     * Create method definitions for the detected relationships.
     *
     * @param array $relationships The relationships to create methods for
     * @return array The method definitions
     */
    public function createMethodDefinitions(array $relationships): array
    {
        $methodDefinitions = [];
        
        foreach ($relationships as $relationship) {
            if ($relationship['type'] === 'hasMany') {
                $methodName = $relationship['method'];
                $relatedTable = $relationship['related_table'];
                $foreignKey = $relationship['foreign_key'];
                $relatedKey = $relationship['related_key'];
                $modelClass = Str::studly(Str::singular($relatedTable));
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'hasMany',
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function {$methodName}()
    {
        return \$this->hasMany(\\App\\Models\\{$modelClass}::class, '{$foreignKey}', '{$relatedKey}');
    }
PHP
                ];
            }
        }
        
        return $methodDefinitions;
    }

    /**
     * Add cascade options to relationships based on foreign key constraints.
     *
     * @param array $relationships The relationships to check for cascade options
     * @return void
     */
    public function implementCascadeOptions(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'hasMany') {
                $relationship['cascade_delete'] = ($relationship['on_delete'] ?? '') === 'CASCADE';
                $relationship['cascade_update'] = ($relationship['on_update'] ?? '') === 'CASCADE';
                
                if ($relationship['cascade_delete']) {
                    $relationship['comment'] .= " (with cascade delete)";
                }
            }
        }
    }

    /**
     * Detect which child tables have soft delete functionality.
     *
     * @param array $relationships The relationships to check
     * @return void
     */
    public function detectSoftDeletesInChildren(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'hasMany') {
                $relatedTable = $relationship['related_table'];
                $relationship['has_soft_deletes'] = $this->schemaHelper->hasSoftDelete($relatedTable, 'deleted_at', $this->connection);
            }
        }
    }

    /**
     * Map collection relationship helpers for working with collections.
     *
     * @param array $relationships The relationships to map collection methods for
     * @return array Collection relationship helper methods
     */
    public function mapCollectionRelationships(array $relationships): array
    {
        $collectionMethods = [];
        
        foreach ($relationships as $relationship) {
            if ($relationship['type'] === 'hasMany') {
                $methodName = $relationship['method'];
                $singularMethod = Str::singular($methodName);
                
                // Add methods for counting
                $collectionMethods["count{$methodName}"] = [
                    'name' => "count{$methodName}",
                    'code' => "return \$this->{$methodName}()->count();"
                ];
                
                // Add methods for checking if has any
                $collectionMethods["has{$methodName}"] = [
                    'name' => "has{$methodName}",
                    'code' => "return \$this->{$methodName}()->exists();"
                ];
                
                // Add method for finding a specific child
                $collectionMethods["find{$singularMethod}"] = [
                    'name' => "find{$singularMethod}",
                    'code' => "return \$this->{$methodName}()->find(\$id);"
                ];
                
                // If the related table has soft deletes, add with trashed methods
                if ($relationship['has_soft_deletes']) {
                    $collectionMethods["{$methodName}WithTrashed"] = [
                        'name' => "{$methodName}WithTrashed",
                        'code' => "return \$this->{$methodName}()->withTrashed();"
                    ];
                }
            }
        }
        
        return $collectionMethods;
    }

    /**
     * Retrieve the analysis results.
     *
     * @return array The complete results of the analysis
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Set the database connection to use for analysis.
     *
     * @param string $connection The name of the database connection
     * @return self Returns the analyzer instance for method chaining
     */
    public function setConnection(string $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Get the database schema information.
     *
     * @return SchemaHelper The schema helper instance
     */
    public function getSchema()
    {
        return $this->schemaHelper;
    }

    /**
     * Get the current database name.
     *
     * @return string The database name
     */
    public function getDatabaseName(): string
    {
        return $this->schemaHelper->getDatabaseName($this->connection);
    }

    /**
     * Get a list of relationship types supported by this analyzer.
     *
     * @return array<string> List of supported relationship types
     */
    public function supportedRelationships(): array
    {
        return ['hasMany'];
    }
}