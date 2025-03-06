<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * BelongsToAnalyzer
 *
 * Analyzes database tables to detect belongsTo relationships based on foreign keys
 * and naming conventions. This analyzer helps identify parent-child relationships
 * where the current table belongs to another table.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class BelongsToAnalyzer implements AnalyzerInterface
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
     * Create a new BelongsToAnalyzer instance.
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
     * Analyze the specified database table for belongsTo relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        $foreignKeys = $this->detectForeignKeyColumns($table);
        $relationships = $this->mapParentChildRelationships($foreignKeys);
        
        // Handle custom foreign keys defined in the configuration
        $customRelationships = $this->handleCustomForeignKeys($table);
        
        // Merge custom relationships with detected ones
        $relationships = array_merge($relationships, $customRelationships);
        
        // Detect polymorphic belongsTo relationships
        $polymorphicRelationships = $this->mapPolymorphicBelongsTo($table);
        $relationships = array_merge($relationships, $polymorphicRelationships);
        
        // Identify reverse relationships
        $this->identifyReverseRelationships($relationships);
        
        // Check for soft deletes in parent tables
        $this->detectSoftDeletesInParents($relationships);
        
        // Identify which relationships are required (non-nullable foreign keys)
        $this->identifyRequiredRelationships($relationships);
        
        // Create method definitions for each relationship
        $methodDefinitions = $this->createMethodDefinitions($relationships);
        
        $this->results = [
            'table' => $table,
            'relationships' => $relationships,
            'method_definitions' => $methodDefinitions,
            'type' => 'belongsTo'
        ];
        
        return $this;
    }

    /**
     * Detect foreign key columns in the specified table.
     *
     * @param string $table The name of the database table
     * @return array The detected foreign key information
     */
    public function detectForeignKeyColumns(string $table): array
    {
        return $this->schemaHelper->getForeignKeys($table, $this->connection);
    }

    /**
     * Map foreign keys to parent-child relationships.
     *
     * @param array $foreignKeys The foreign keys to map
     * @return array The mapped relationships
     */
    public function mapParentChildRelationships(array $foreignKeys): array
    {
        $relationships = [];
        
        foreach ($foreignKeys as $fk) {
            $localColumn = $fk['local_columns'][0] ?? null;
            $foreignTable = $fk['foreign_table'] ?? null;
            $foreignColumn = $fk['foreign_columns'][0] ?? null;
            
            if ($localColumn && $foreignTable && $foreignColumn) {
                $methodName = RelationshipHelper::formatRelationshipMethod('belongsTo', $foreignTable);
                
                $relationships[] = [
                    'type' => 'belongsTo',
                    'local_column' => $localColumn,
                    'foreign_table' => $foreignTable,
                    'foreign_column' => $foreignColumn,
                    'method' => $methodName,
                    'required' => false, // Will be updated by identifyRequiredRelationships
                    'on_delete' => $fk['on_delete'] ?? null,
                    'on_update' => $fk['on_update'] ?? null,
                    'has_soft_deletes' => false, // Will be updated by detectSoftDeletesInParents
                    'comment' => "Get the {$foreignTable} that this record belongs to",
                ];
            }
        }
        
        return $relationships;
    }

    /**
     * Identify reverse relationships for the detected belongsTo relationships.
     *
     * @param array $relationships The belongsTo relationships
     * @return void
     */
    public function identifyReverseRelationships(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsTo') {
                $localTable = $relationship['local_table'] ?? $this->results['table'] ?? null;
                $foreignTable = $relationship['foreign_table'];
                
                // Check if this should be a hasOne or hasMany on the parent side
                $isUnique = in_array($relationship['local_column'], $this->schemaHelper->getUniqueColumns($localTable));
                
                $relationship['inverse_type'] = $isUnique ? 'hasOne' : 'hasMany';
                $relationship['inverse_method'] = RelationshipHelper::formatRelationshipMethod(
                    $relationship['inverse_type'], 
                    $localTable
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
                if ($relation['type'] === 'belongsTo') {
                    $customRelationships[] = [
                        'type' => 'belongsTo',
                        'local_column' => $relation['foreign_key'],
                        'foreign_table' => $relation['related_table'] ?? Str::snake($relation['model']),
                        'foreign_column' => $relation['related_key'] ?? 'id',
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod('belongsTo', $relation['related_table'] ?? Str::snake($relation['model'])),
                        'required' => $relation['required'] ?? false,
                        'is_custom' => true,
                        'comment' => $relation['comment'] ?? "Get the related {$relation['model']} that this record belongs to",
                    ];
                }
            }
        }
        
        return $customRelationships;
    }

    /**
     * Map polymorphic belongsTo relationships for the specified table.
     *
     * @param string $table The name of the database table
     * @return array The detected polymorphic relationships
     */
    public function mapPolymorphicBelongsTo(string $table): array
    {
        $relationships = [];
        $columns = $this->schemaHelper->getTableColumns($table, $this->connection);
        
        // Check for potential morphTo columns (e.g., commentable_id + commentable_type)
        foreach ($columns as $column => $details) {
            if (Str::endsWith($column, '_type')) {
                $morphName = Str::beforeLast($column, '_type');
                $idColumn = $morphName . '_id';
                
                if (isset($columns[$idColumn])) {
                    $methodName = RelationshipHelper::formatRelationshipMethod('morphTo', $morphName);
                    
                    $relationships[] = [
                        'type' => 'morphTo',
                        'morph_name' => $morphName,
                        'type_column' => $column,
                        'id_column' => $idColumn,
                        'method' => $methodName,
                        'required' => false, // Will be updated by identifyRequiredRelationships
                        'comment' => "Get the parent model that this record morphs to",
                    ];
                }
            }
        }
        
        return $relationships;
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
            if ($relationship['type'] === 'belongsTo') {
                $methodName = $relationship['method'];
                $foreignTable = $relationship['foreign_table'];
                $localColumn = $relationship['local_column'];
                $foreignColumn = $relationship['foreign_column'];
                $modelClass = Str::studly(Str::singular($foreignTable));
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'belongsTo',
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function {$methodName}()
    {
        return \$this->belongsTo(\\App\\Models\\{$modelClass}::class, '{$localColumn}', '{$foreignColumn}');
    }
PHP
                ];
            } elseif ($relationship['type'] === 'morphTo') {
                $methodName = $relationship['method'];
                $morphName = $relationship['morph_name'];
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'morphTo',
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function {$methodName}()
    {
        return \$this->morphTo('{$morphName}');
    }
PHP
                ];
            }
        }
        
        return $methodDefinitions;
    }

    /**
     * Detect which parent tables have soft delete functionality.
     *
     * @param array $relationships The relationships to check
     * @return void
     */
    public function detectSoftDeletesInParents(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsTo') {
                $foreignTable = $relationship['foreign_table'];
                $relationship['has_soft_deletes'] = $this->schemaHelper->hasSoftDelete($foreignTable, 'deleted_at', $this->connection);
            }
        }
    }

    /**
     * Identify which relationships are required based on nullable status.
     *
     * @param array $relationships The relationships to check
     * @return void
     */
    public function identifyRequiredRelationships(array &$relationships): void
    {
        $table = $this->results['table'] ?? null;
        
        if ($table) {
            $columns = $this->schemaHelper->getTableColumns($table, $this->connection);
            
            foreach ($relationships as &$relationship) {
                if (isset($relationship['local_column']) && isset($columns[$relationship['local_column']])) {
                    $columnInfo = $columns[$relationship['local_column']];
                    $relationship['required'] = !$columnInfo['nullable'] && $columnInfo['default'] === null;
                } elseif (isset($relationship['id_column']) && isset($relationship['type_column'])) {
                    // For polymorphic relationships, both the ID and type columns must be required
                    $idColumnInfo = $columns[$relationship['id_column']] ?? null;
                    $typeColumnInfo = $columns[$relationship['type_column']] ?? null;
                    
                    $idRequired = $idColumnInfo && !$idColumnInfo['nullable'] && $idColumnInfo['default'] === null;
                    $typeRequired = $typeColumnInfo && !$typeColumnInfo['nullable'] && $typeColumnInfo['default'] === null;
                    
                    $relationship['required'] = $idRequired && $typeRequired;
                }
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
        return ['belongsTo', 'morphTo'];
    }
}