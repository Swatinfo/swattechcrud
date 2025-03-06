<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * MorphManyAnalyzer
 *
 * Analyzes database tables to detect morphMany relationships based on polymorphic
 * naming conventions. This analyzer identifies tables that can have multiple
 * polymorphic associations with other tables.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class MorphManyAnalyzer implements AnalyzerInterface
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
     * Create a new MorphManyAnalyzer instance.
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
     * Analyze the specified database table for morphMany relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Find tables that might be polymorphic targets for this table
        $targets = $this->identifyPolymorphicTargets($table);
        
        // Map the polymorphic one-to-many relationships
        $relationships = $this->mapOneToManyPolymorphic($table, $targets);
        
        // Handle custom morph name configurations
        $customRelationships = $this->handleCustomMorphNames($table);
        
        // Merge custom relationships with detected ones
        $relationships = array_merge($relationships, $customRelationships);
        
        // Detect inverse morphTo relationships
        $this->detectInverseMorphTo($relationships);
        
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
            'type' => 'morphMany'
        ];
        
        return $this;
    }

    /**
     * Identify tables that could be polymorphic targets for this table.
     *
     * @param string $table The name of the database table
     * @return array List of potential polymorphic targets
     */
    public function identifyPolymorphicTargets(string $table): array
    {
        $targets = [];
        $allTables = $this->schemaHelper->getAllTables($this->connection);
        $modelName = Str::studly(Str::singular($table));
        
        foreach ($allTables as $potentialTarget) {
            if ($potentialTarget === $table) {
                continue;
            }
            
            $columns = $this->schemaHelper->getTableColumns($potentialTarget, $this->connection);
            
            // Look for *_type and *_id pairs
            foreach ($columns as $column => $details) {
                if (Str::endsWith($column, '_type')) {
                    $morphName = Str::beforeLast($column, '_type');
                    $idColumn = "{$morphName}_id";
                    
                    if (isset($columns[$idColumn])) {
                        // Check if this table is used as a type value in the polymorphic relationship
                        // In a real app, we'd query the database to check for actual type values
                        // For now, we'll make a reasonable guess based on conventions
                        
                        $typeValuesToCheck = [
                            $modelName, // Model name only
                            "App\\Models\\{$modelName}", // Fully qualified class name
                            $table, // Table name
                            Str::singular($table) // Singular table name
                        ];
                        
                        foreach ($typeValuesToCheck as $typeValue) {
                            $hasTypeValue = $this->schemaHelper->tableHasValue(
                                $potentialTarget, 
                                $column, 
                                $typeValue,
                                $this->connection
                            );
                            
                            if ($hasTypeValue) {
                                $targets[] = [
                                    'table' => $potentialTarget,
                                    'morph_name' => $morphName,
                                    'type_column' => $column,
                                    'id_column' => $idColumn,
                                    'type_value' => $typeValue,
                                    'column_details' => [
                                        'type' => $details,
                                        'id' => $columns[$idColumn]
                                    ]
                                ];
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        return $targets;
    }

    /**
     * Map polymorphic targets to one-to-many relationships.
     *
     * @param string $table The name of the database table
     * @param array $targets The polymorphic targets
     * @return array The mapped relationships
     */
    public function mapOneToManyPolymorphic(string $table, array $targets): array
    {
        $relationships = [];
        
        foreach ($targets as $target) {
            $morphName = $target['morph_name'];
            $targetTable = $target['table'];
            
            // Check if this should be morphOne or morphMany
            // If there's a unique constraint on the polymorphic columns, it's morphOne
            $isUnique = $this->schemaHelper->hasUniqueConstraint(
                $targetTable,
                [$target['type_column'], $target['id_column']],
                $this->connection
            );
            
            $type = $isUnique ? 'morphOne' : 'morphMany';
            $methodName = RelationshipHelper::formatRelationshipMethod($type, $targetTable);
            
            $relationships[] = [
                'type' => $type,
                'local_table' => $table,
                'related_table' => $targetTable,
                'morph_name' => $morphName,
                'type_column' => $target['type_column'],
                'id_column' => $target['id_column'],
                'method' => $methodName,
                'type_value' => $target['type_value'],
                'has_soft_deletes' => false, // Will be updated by detectSoftDeletesInChildren
                'comment' => $type === 'morphOne' 
                    ? "Get the associated {$targetTable} record as a polymorphic relation"
                    : "Get the associated {$targetTable} records as a polymorphic relation",
            ];
        }
        
        return $relationships;
    }

    /**
     * Detect inverse morphTo relationships for the detected morphMany relationships.
     *
     * @param array $relationships The morphMany relationships
     * @return void
     */
    public function detectInverseMorphTo(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'morphMany' || $relationship['type'] === 'morphOne') {
                $morphName = $relationship['morph_name'];
                
                $relationship['inverse_type'] = 'morphTo';
                $relationship['inverse_method'] = RelationshipHelper::formatRelationshipMethod(
                    'morphTo', 
                    $morphName
                );
            }
        }
    }

    /**
     * Handle custom morph name configurations from config.
     *
     * @param string $table The name of the database table
     * @return array Additional custom relationships
     */
    public function handleCustomMorphNames(string $table): array
    {
        $customRelationships = [];
        $config = config('crud.relationships.custom_relationships', []);
        
        if (isset($config[$table])) {
            foreach ($config[$table] as $relation) {
                if ($relation['type'] === 'morphMany' || $relation['type'] === 'morphOne') {
                    $morphName = $relation['morph_name'];
                    $targetTable = $relation['related_table'] ?? Str::snake($relation['model']);
                    
                    $customRelationships[] = [
                        'type' => $relation['type'],
                        'local_table' => $table,
                        'related_table' => $targetTable,
                        'morph_name' => $morphName,
                        'type_column' => $relation['type_column'] ?? "{$morphName}_type",
                        'id_column' => $relation['id_column'] ?? "{$morphName}_id",
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod(
                            $relation['type'], 
                            $targetTable
                        ),
                        'type_value' => $relation['type_value'] ?? Str::studly(Str::singular($table)),
                        'has_soft_deletes' => $relation['has_soft_deletes'] ?? false,
                        'is_custom' => true,
                        'comment' => $relation['comment'] ?? ($relation['type'] === 'morphOne' 
                            ? "Get the associated {$targetTable} record as a polymorphic relation"
                            : "Get the associated {$targetTable} records as a polymorphic relation"),
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
            if ($relationship['type'] === 'morphMany' || $relationship['type'] === 'morphOne') {
                $methodName = $relationship['method'];
                $relatedTable = $relationship['related_table'];
                $morphName = $relationship['morph_name'];
                $modelClass = Str::studly(Str::singular($relatedTable));
                $relationType = $relationship['type'];
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => $relationType,
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\\{$relationType}
     */
    public function {$methodName}()
    {
        return \$this->{$relationType}(\\App\\Models\\{$modelClass}::class, '{$morphName}');
    }
PHP
                ];
            }
        }
        
        return $methodDefinitions;
    }

    /**
     * Add cascade options to relationships based on configuration or conventions.
     *
     * @param array $relationships The relationships to check for cascade options
     * @return void
     */
    public function implementCascadeOptions(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'morphMany' || $relationship['type'] === 'morphOne') {
                // For polymorphic relationships, we usually need to handle cascade manually
                // since foreign keys don't enforce it at the database level
                
                // Check configuration for cascade preferences
                $config = config('crud.relationships.polymorphic_cascade', []);
                $tablePair = $relationship['local_table'] . '.' . $relationship['related_table'];
                
                $relationship['cascade_delete'] = $config[$tablePair]['cascade_delete'] ?? 
                                                 $config[$relationship['morph_name']]['cascade_delete'] ?? 
                                                 false;
                
                // If configured for cascade delete, update the comment
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
            if ($relationship['type'] === 'morphMany' || $relationship['type'] === 'morphOne') {
                $relatedTable = $relationship['related_table'];
                $relationship['has_soft_deletes'] = $this->schemaHelper->hasSoftDelete(
                    $relatedTable, 
                    'deleted_at', 
                    $this->connection
                );
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
        return ['morphMany', 'morphOne'];
    }
}