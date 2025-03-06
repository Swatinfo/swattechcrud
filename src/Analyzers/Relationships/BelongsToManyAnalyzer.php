<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * BelongsToManyAnalyzer
 *
 * Analyzes database tables to detect belongsToMany (many-to-many) relationships
 * based on pivot tables. This analyzer identifies pivot tables that connect
 * two entity tables and extracts the relationship metadata.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class BelongsToManyAnalyzer implements AnalyzerInterface
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
     * Create a new BelongsToManyAnalyzer instance.
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
     * Analyze the specified database table for belongsToMany relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Find pivot tables that connect to this table
        $pivotTables = $this->detectPivotTables($table);
        
        // Map the many-to-many relationships
        $relationships = $this->mapManyToManyRelationships($table, $pivotTables);
        
        // Identify pivot table attributes (additional columns)
        $this->identifyPivotAttributes($relationships);
        
        // Handle custom pivot table configurations
        $customRelationships = $this->handleCustomPivotTableNames($table);
        
        // Merge custom relationships with detected ones
        $relationships = array_merge($relationships, $customRelationships);
        
        // Check for timestamps in pivot tables
        $this->mapTimestampsInPivots($relationships);
        
        // Check for soft deletes in pivot tables
        $this->detectSoftDeletesInPivots($relationships);
        
        // Identify inverse relationships
        $this->identifyInverseRelationships($relationships);
        
        // Create method definitions for each relationship
        $methodDefinitions = $this->createMethodDefinitions($relationships);
        
        $this->results = [
            'table' => $table,
            'relationships' => $relationships,
            'method_definitions' => $methodDefinitions,
            'type' => 'belongsToMany'
        ];
        
        return $this;
    }

    /**
     * Detect pivot tables connecting to the specified table.
     *
     * @param string $table The name of the database table
     * @return array List of pivot tables with their foreign keys
     */
    public function detectPivotTables(string $table): array
    {
        $pivotTables = [];
        $allTables = $this->schemaHelper->getAllTables();
        $tableSingular = Str::singular($table);
        
        foreach ($allTables as $potentialPivot) {
            // Skip the table itself
            if ($potentialPivot === $table) {
                continue;
            }
            
            // Get foreign keys for the potential pivot table
            $foreignKeys = $this->schemaHelper->getForeignKeys($potentialPivot, $this->connection);
            
            // A pivot table should have at least two foreign keys
            if (count($foreignKeys) < 2) {
                continue;
            }
            
            // Check if one of the foreign keys points to our table
            $hasForeignKeyToTable = false;
            $otherForeignKey = null;
            $primaryKeyToTable = null;
            
            foreach ($foreignKeys as $fk) {
                if ($fk['foreign_table'] === $table) {
                    $hasForeignKeyToTable = true;
                    $primaryKeyToTable = [
                        'local_column' => $fk['local_columns'][0],
                        'foreign_column' => $fk['foreign_columns'][0]
                    ];
                } else {
                    $otherForeignKey = [
                        'local_column' => $fk['local_columns'][0],
                        'foreign_table' => $fk['foreign_table'],
                        'foreign_column' => $fk['foreign_columns'][0]
                    ];
                }
            }
            
            // If this is indeed a pivot table for our table
            if ($hasForeignKeyToTable && $otherForeignKey) {
                // Check for naming convention matches
                $namingMatch = $this->isPivotTableByNamingConvention($potentialPivot, $table, $otherForeignKey['foreign_table']);
                
                $pivotTables[] = [
                    'pivot_table' => $potentialPivot,
                    'primary_key' => $primaryKeyToTable,
                    'related_key' => $otherForeignKey,
                    'by_naming_convention' => $namingMatch
                ];
            }
        }
        
        return $pivotTables;
    }

    /**
     * Check if a table follows pivot table naming conventions.
     *
     * @param string $pivotTable The potential pivot table name
     * @param string $table1 The first table name
     * @param string $table2 The second table name
     * @return bool True if naming convention matches
     */
    protected function isPivotTableByNamingConvention(string $pivotTable, string $table1, string $table2): bool
    {
        // Check common naming conventions like table1_table2 or table2_table1
        $table1Singular = Str::singular($table1);
        $table2Singular = Str::singular($table2);
        
        $patterns = [
            "{$table1}_{$table2}",
            "{$table2}_{$table1}",
            "{$table1Singular}_{$table2}",
            "{$table2}_{$table1Singular}",
            "{$table1}_{$table2Singular}",
            "{$table2Singular}_{$table1}",
            "{$table1Singular}_{$table2Singular}",
            "{$table2Singular}_{$table1Singular}"
        ];
        
        foreach ($patterns as $pattern) {
            if ($pivotTable === $pattern) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Map the many-to-many relationships between tables.
     *
     * @param string $table The table name
     * @param array $pivotTables The pivot tables information
     * @return array The mapped relationships
     */
    public function mapManyToManyRelationships(string $table, array $pivotTables): array
    {
        $relationships = [];
        
        foreach ($pivotTables as $pivotInfo) {
            $pivotTable = $pivotInfo['pivot_table'];
            $relatedTable = $pivotInfo['related_key']['foreign_table'];
            $methodName = RelationshipHelper::formatRelationshipMethod('belongsToMany', $relatedTable);
            
            // Build the relationship data
            $relationships[] = [
                'type' => 'belongsToMany',
                'local_table' => $table,
                'related_table' => $relatedTable,
                'pivot_table' => $pivotTable,
                'pivot_foreign_key' => $pivotInfo['primary_key']['local_column'],
                'pivot_related_key' => $pivotInfo['related_key']['local_column'],
                'foreign_key' => $pivotInfo['primary_key']['foreign_column'],
                'related_key' => $pivotInfo['related_key']['foreign_column'],
                'method' => $methodName,
                'pivot_fields' => [],
                'has_timestamps' => false,
                'has_soft_deletes' => false,
                'by_naming_convention' => $pivotInfo['by_naming_convention'],
                'comment' => "The {$relatedTable} that belong to this {$table}",
            ];
        }
        
        return $relationships;
    }

    /**
     * Identify additional pivot attributes (columns) for each relationship.
     *
     * @param array $relationships The relationships to analyze
     * @return void
     */
    public function identifyPivotAttributes(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsToMany') {
                $pivotTable = $relationship['pivot_table'];
                $columns = $this->schemaHelper->getTableColumns($pivotTable, $this->connection);
                
                // Remove the foreign key columns from the list
                unset($columns[$relationship['pivot_foreign_key']]);
                unset($columns[$relationship['pivot_related_key']]);
                
                // Remove timestamp columns
                $timestampColumns = ['created_at', 'updated_at'];
                foreach ($timestampColumns as $timestampColumn) {
                    unset($columns[$timestampColumn]);
                }
                
                // Remove soft delete column
                unset($columns['deleted_at']);
                
                // The remaining columns are pivot attributes
                $pivotAttributes = array_keys($columns);
                $relationship['pivot_fields'] = $pivotAttributes;
            }
        }
    }

    /**
     * Handle custom pivot table names defined in configuration.
     *
     * @param string $table The name of the database table
     * @return array Additional custom relationships
     */
    public function handleCustomPivotTableNames(string $table): array
    {
        $customRelationships = [];
        $config = config('crud.relationships.custom_relationships', []);
        
        if (isset($config[$table])) {
            foreach ($config[$table] as $relation) {
                if ($relation['type'] === 'belongsToMany') {
                    $customRelationships[] = [
                        'type' => 'belongsToMany',
                        'local_table' => $table,
                        'related_table' => $relation['related_table'] ?? Str::snake($relation['model']),
                        'pivot_table' => $relation['pivot_table'] ?? null,
                        'pivot_foreign_key' => $relation['pivot_foreign_key'] ?? Str::singular($table) . '_id',
                        'pivot_related_key' => $relation['pivot_related_key'] ?? Str::singular($relation['related_table'] ?? Str::snake($relation['model'])) . '_id',
                        'foreign_key' => $relation['foreign_key'] ?? 'id',
                        'related_key' => $relation['related_key'] ?? 'id',
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod('belongsToMany', $relation['related_table'] ?? Str::snake($relation['model'])),
                        'pivot_fields' => $relation['pivot_fields'] ?? [],
                        'has_timestamps' => $relation['has_timestamps'] ?? false,
                        'has_soft_deletes' => $relation['has_soft_deletes'] ?? false,
                        'is_custom' => true,
                        'comment' => $relation['comment'] ?? "The {$relation['related_table']} that belong to this {$table}",
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
            if ($relationship['type'] === 'belongsToMany') {
                $methodName = $relationship['method'];
                $relatedTable = $relationship['related_table'];
                $pivotTable = $relationship['pivot_table'];
                $foreignKey = $relationship['pivot_foreign_key'];
                $relatedKey = $relationship['pivot_related_key'];
                $modelClass = Str::studly(Str::singular($relatedTable));
                
                // Start building the method code
                $code = <<<PHP
    /**
     * {$relationship['comment']}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function {$methodName}()
    {
        return \$this->belongsToMany(\\App\\Models\\{$modelClass}::class, '{$pivotTable}', '{$foreignKey}', '{$relatedKey}')
PHP;
                
                // Add withPivot if there are pivot fields
                if (!empty($relationship['pivot_fields'])) {
                    $pivotFieldsStr = "'" . implode("', '", $relationship['pivot_fields']) . "'";
                    $code .= "\n            ->withPivot({$pivotFieldsStr})";
                }
                
                // Add withTimestamps if the pivot has timestamps
                if ($relationship['has_timestamps']) {
                    $code .= "\n            ->withTimestamps()";
                }
                
                // Add withTrashed if the pivot has soft deletes
                if ($relationship['has_soft_deletes']) {
                    $code .= "\n            ->withTrashed()";
                }
                
                // Close the method
                $code .= ";\n    }";
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'belongsToMany',
                    'code' => $code
                ];
            }
        }
        
        return $methodDefinitions;
    }

    /**
     * Check for timestamps in pivot tables.
     *
     * @param array $relationships The relationships to check
     * @return void
     */
    public function mapTimestampsInPivots(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsToMany') {
                $pivotTable = $relationship['pivot_table'];
                $columns = $this->schemaHelper->getTableColumns($pivotTable, $this->connection);
                
                // Check for created_at and updated_at columns
                $hasCreatedAt = isset($columns['created_at']);
                $hasUpdatedAt = isset($columns['updated_at']);
                
                $relationship['has_timestamps'] = $hasCreatedAt && $hasUpdatedAt;
            }
        }
    }

    /**
     * Check for soft deletes in pivot tables.
     *
     * @param array $relationships The relationships to check
     * @return void
     */
    public function detectSoftDeletesInPivots(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsToMany') {
                $pivotTable = $relationship['pivot_table'];
                $relationship['has_soft_deletes'] = $this->schemaHelper->hasSoftDelete($pivotTable, 'deleted_at', $this->connection);
            }
        }
    }

    /**
     * Identify inverse relationships for the detected belongsToMany relationships.
     *
     * @param array $relationships The belongsToMany relationships
     * @return void
     */
    public function identifyInverseRelationships(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'belongsToMany') {
                $localTable = $relationship['local_table'];
                $relatedTable = $relationship['related_table'];
                
                $relationship['inverse_type'] = 'belongsToMany';
                $relationship['inverse_method'] = RelationshipHelper::formatRelationshipMethod(
                    'belongsToMany', 
                    $localTable
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
        return ['belongsToMany'];
    }
}