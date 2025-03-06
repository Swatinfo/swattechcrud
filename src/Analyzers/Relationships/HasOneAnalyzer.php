<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * HasOneAnalyzer
 *
 * Analyzes database tables to detect hasOne relationships based on foreign keys
 * and unique constraints. This analyzer identifies child tables that have exactly
 * one record referencing the parent table's primary key.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class HasOneAnalyzer implements AnalyzerInterface
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
     * Create a new HasOneAnalyzer instance.
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
     * Analyze the specified database table for hasOne relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Find tables that have unique foreign keys pointing to this table
        $childTables = $this->identifyChildTables($table);
        
        // Map the one-to-one relationships
        $relationships = $this->mapOneToOneRelationships($table, $childTables);
        
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
        
        // Create method definitions for each relationship
        $methodDefinitions = $this->createMethodDefinitions($relationships);
        
        $this->results = [
            'table' => $table,
            'relationships' => $relationships,
            'method_definitions' => $methodDefinitions,
            'type' => 'hasOne'
        ];
        
        return $this;
    }

    /**
     * Identify child tables that reference the current table with unique constraints.
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
                    // Check if this should be hasOne (has a unique constraint on the foreign key)
                    $isUnique = in_array($localColumn, $this->schemaHelper->getUniqueColumns($otherTable, $this->connection));
                    
                    if ($isUnique) {
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
     * Map the one-to-one relationships between tables.
     *
     * @param string $table The parent table name
     * @param array $childTables The child tables information
     * @return array The mapped relationships
     */
    public function mapOneToOneRelationships(string $table, array $childTables): array
    {
        $relationships = [];
        
        foreach ($childTables as $childTable) {
            $methodName = RelationshipHelper::formatRelationshipMethod('hasOne', $childTable['table']);
            
            $relationships[] = [
                'type' => 'hasOne',
                'local_table' => $table,
                'related_table' => $childTable['table'],
                'foreign_key' => $childTable['foreign_key'],
                'related_key' => $childTable['related_key'],
                'method' => $methodName,
                'on_delete' => $childTable['on_delete'],
                'on_update' => $childTable['on_update'],
                'has_soft_deletes' => false, // Will be updated by detectSoftDeletesInChildren
                'comment' => "Get the associated {$childTable['table']} record",
            ];
        }
        
        return $relationships;
    }

    /**
     * Detect inverse belongsTo relationships for the hasOne relationships.
     *
     * @param array $relationships The hasOne relationships
     * @return void
     */
    public function detectInverseBelongsTo(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'hasOne') {
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
                if ($relation['type'] === 'hasOne') {
                    $customRelationships[] = [
                        'type' => 'hasOne',
                        'local_table' => $table,
                        'related_table' => $relation['related_table'] ?? Str::snake($relation['model']),
                        'foreign_key' => $relation['foreign_key'],
                        'related_key' => $relation['related_key'] ?? 'id',
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod('hasOne', $relation['related_table'] ?? Str::snake($relation['model'])),
                        'is_custom' => true,
                        'has_soft_deletes' => false,
                        'comment' => $relation['comment'] ?? "Get the associated {$relation['model']} record",
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
            if ($relationship['type'] === 'hasOne') {
                $methodName = $relationship['method'];
                $relatedTable = $relationship['related_table'];
                $foreignKey = $relationship['foreign_key'];
                $relatedKey = $relationship['related_key'];
                $modelClass = Str::studly(Str::singular($relatedTable));
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'hasOne',
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function {$methodName}()
    {
        return \$this->hasOne(\\App\\Models\\{$modelClass}::class, '{$foreignKey}', '{$relatedKey}');
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
            if ($relationship['type'] === 'hasOne') {
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
            if ($relationship['type'] === 'hasOne') {
                $relatedTable = $relationship['related_table'];
                $relationship['has_soft_deletes'] = $this->schemaHelper->hasSoftDelete($relatedTable, 'deleted_at', $this->connection);
            }
        }
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
        return ['hasOne'];
    }
}