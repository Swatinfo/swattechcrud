<?php

namespace SwatTech\Crud\Analyzers;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use SwatTech\Crud\Analyzers\Relationships\HasManyAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\HasOneAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\BelongsToAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\BelongsToManyAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\MorphToAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\MorphManyAnalyzer;
use Illuminate\Support\Str;

/**
 * RelationshipAnalyzer
 *
 * Master relationship detection class that coordinates all the specialized
 * relationship analyzers to provide comprehensive relationship analysis.
 * This class aggregates and normalizes results from different analyzers.
 *
 * @package SwatTech\Crud\Analyzers
 */
class RelationshipAnalyzer implements AnalyzerInterface
{
    /**
     * The HasManyAnalyzer instance.
     *
     * @var HasManyAnalyzer
     */
    protected $hasManyAnalyzer;

    /**
     * The HasOneAnalyzer instance.
     *
     * @var HasOneAnalyzer
     */
    protected $hasOneAnalyzer;

    /**
     * The BelongsToAnalyzer instance.
     *
     * @var BelongsToAnalyzer
     */
    protected $belongsToAnalyzer;

    /**
     * The BelongsToManyAnalyzer instance.
     *
     * @var BelongsToManyAnalyzer
     */
    protected $belongsToManyAnalyzer;

    /**
     * The MorphToAnalyzer instance.
     *
     * @var MorphToAnalyzer
     */
    protected $morphToAnalyzer;

    /**
     * The MorphManyAnalyzer instance.
     *
     * @var MorphManyAnalyzer
     */
    protected $morphManyAnalyzer;

    /**
     * The RelationshipHelper instance.
     *
     * @var RelationshipHelper
     */
    protected $relationshipHelper;

    /**
     * The SchemaHelper instance.
     *
     * @var SchemaHelper
     */
    protected $schemaHelper;

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
     * Create a new RelationshipAnalyzer instance with all specialized analyzers.
     *
     * @param HasManyAnalyzer $hasManyAnalyzer
     * @param HasOneAnalyzer $hasOneAnalyzer
     * @param BelongsToAnalyzer $belongsToAnalyzer
     * @param BelongsToManyAnalyzer $belongsToManyAnalyzer
     * @param MorphToAnalyzer $morphToAnalyzer
     * @param MorphManyAnalyzer $morphManyAnalyzer
     * @param RelationshipHelper $relationshipHelper
     * @param SchemaHelper $schemaHelper
     */
    public function __construct(
        HasManyAnalyzer $hasManyAnalyzer,
        HasOneAnalyzer $hasOneAnalyzer,
        BelongsToAnalyzer $belongsToAnalyzer,
        BelongsToManyAnalyzer $belongsToManyAnalyzer,
        MorphToAnalyzer $morphToAnalyzer,
        MorphManyAnalyzer $morphManyAnalyzer,
        RelationshipHelper $relationshipHelper,
        SchemaHelper $schemaHelper
    ) {
        $this->hasManyAnalyzer = $hasManyAnalyzer;
        $this->hasOneAnalyzer = $hasOneAnalyzer;
        $this->belongsToAnalyzer = $belongsToAnalyzer;
        $this->belongsToManyAnalyzer = $belongsToManyAnalyzer;
        $this->morphToAnalyzer = $morphToAnalyzer;
        $this->morphManyAnalyzer = $morphManyAnalyzer;
        $this->relationshipHelper = $relationshipHelper;
        $this->schemaHelper = $schemaHelper;
    }

    /**
     * Analyze relationships for the specified table.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Set the connection for all analyzers
        if ($this->connection) {
            $this->hasManyAnalyzer->setConnection($this->connection);
            $this->hasOneAnalyzer->setConnection($this->connection);
            $this->belongsToAnalyzer->setConnection($this->connection);
            $this->belongsToManyAnalyzer->setConnection($this->connection);
            $this->morphToAnalyzer->setConnection($this->connection);
            $this->morphManyAnalyzer->setConnection($this->connection);
        }

        // Use specific analyzers to detect different relationship types
        $relationshipTypes = $this->detectRelationshipTypes($table);

        // Map relationship metadata for better organization
        $metadata = $this->mapRelationshipMetadata($relationshipTypes);

        // Create unified relationship definitions
        $definitions = $this->createRelationshipDefinitions($metadata);

        // Create bidirectional relationship mappings
        $bidirectional = $this->createBidirectionalMappings($definitions);

        // Handle complex relationship scenarios
        $complexRelations = $this->handleComplexRelationships($table);

        $this->results = [
            'table' => $table,
            'relationships' => $definitions,
            'bidirectional' => $bidirectional,
            'complex' => $complexRelations,
            'method_definitions' => $this->extractMethodDefinitions($relationshipTypes),
            'collection_methods' => $this->extractCollectionMethods($relationshipTypes),
            'naming_conventions' => $this->identifyNamingConventions()
        ];

        return $this;
    }

    /**
     * Detect different types of relationships for a table using specialized analyzers.
     *
     * @param string $table The name of the database table
     * @return array Array of relationship analysis results by type
     */
    public function detectRelationshipTypes(string $table): array
    {
        $relationships = [];

        // Analyze hasMany relationships
        $this->hasManyAnalyzer->analyze($table);
        $relationships['hasMany'] = $this->hasManyAnalyzer->getResults();

        // Analyze hasOne relationships
        $this->hasOneAnalyzer->analyze($table);
        $relationships['hasOne'] = $this->hasOneAnalyzer->getResults();

        // Analyze belongsTo relationships
        $this->belongsToAnalyzer->analyze($table);
        $relationships['belongsTo'] = $this->belongsToAnalyzer->getResults();

        // Analyze belongsToMany relationships
        $this->belongsToManyAnalyzer->analyze($table);
        $relationships['belongsToMany'] = $this->belongsToManyAnalyzer->getResults();

        // Analyze morphTo relationships
        $this->morphToAnalyzer->analyze($table);
        $relationships['morphTo'] = $this->morphToAnalyzer->getResults();

        // Analyze morphMany/morphOne relationships
        $this->morphManyAnalyzer->analyze($table);
        $relationships['morphMany'] = $this->morphManyAnalyzer->getResults();

        return $relationships;
    }

    /**
     * Map relationship metadata from various analyzers into a unified structure.
     *
     * @param array $relationships The relationships detected by specialized analyzers
     * @return array The mapped relationship metadata
     */
    public function mapRelationshipMetadata(array $relationships): array
    {
        $metadata = [];

        foreach ($relationships as $type => $result) {
            if (!empty($result['relationships'])) {
                foreach ($result['relationships'] as $relationship) {
                    $metadata[] = array_merge(['type' => $type], $relationship);
                }
            }
        }

        // Sort relationships by type for better organization
        usort($metadata, function ($a, $b) {
            $typeOrder = [
                'belongsTo' => 1,
                'hasOne' => 2,
                'hasMany' => 3,
                'belongsToMany' => 4,
                'morphTo' => 5,
                'morphOne' => 6,
                'morphMany' => 7
            ];

            $aOrder = $typeOrder[$a['type']] ?? 999;
            $bOrder = $typeOrder[$b['type']] ?? 999;

            return $aOrder <=> $bOrder;
        });

        return $metadata;
    }

    /**
     * Create standardized relationship definitions from the metadata.
     *
     * @param array $metadata The relationship metadata
     * @return array Standardized relationship definitions
     */
    public function createRelationshipDefinitions(array $metadata): array
    {
        $definitions = [];

        foreach ($metadata as $relation) {
            $definition = [
                'type' => $relation['type'],
                'method' => $relation['method'],
                'related_table' => $relation['related_table'] ?? $relation['foreign_table'] ?? null,
            ];

            // Add type-specific attributes
            switch ($relation['type']) {
                case 'hasMany':
                case 'hasOne':
                    $definition['foreign_key'] = $relation['foreign_key'] ?? null;
                    $definition['local_key'] = $relation['related_key'] ?? 'id';
                    $definition['has_soft_deletes'] = $relation['has_soft_deletes'] ?? false;
                    break;

                case 'belongsTo':
                    $definition['foreign_key'] = $relation['local_column'] ?? $relation['foreign_key'] ?? null;
                    $definition['owner_key'] = $relation['foreign_column'] ?? $relation['related_key'] ?? null;
                    $definition['required'] = $relation['required'] ?? false;
                    break;

                case 'belongsToMany':
                    $definition['pivot_table'] = $relation['pivot_table'] ?? null;
                    $definition['pivot_foreign_key'] = $relation['pivot_foreign_key'] ?? null;
                    $definition['pivot_related_key'] = $relation['pivot_related_key'] ?? null;
                    $definition['pivot_fields'] = $relation['pivot_fields'] ?? [];
                    $definition['has_timestamps'] = $relation['has_timestamps'] ?? false;
                    break;

                case 'morphTo':
                    $definition['morph_name'] = $relation['morph_name'] ?? null;
                    $definition['type_column'] = $relation['type_column'] ?? null;
                    $definition['id_column'] = $relation['id_column'] ?? null;
                    $definition['target_models'] = $relation['target_models'] ?? [];
                    break;

                case 'morphMany':
                case 'morphOne':
                    $definition['morph_name'] = $relation['morph_name'] ?? null;
                    $definition['type_column'] = $relation['type_column'] ?? null;
                    $definition['id_column'] = $relation['id_column'] ?? null;
                    break;
            }

            // Add common attributes
            $definition['is_custom'] = $relation['is_custom'] ?? false;
            $definition['comment'] = $relation['comment'] ?? '';

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * Analyze foreign key constraints to extract additional relationship metadata.
     *
     * @param string $table The name of the database table
     * @return array Foreign key constraint analysis results
     */
    public function analyzeForeignKeyConstraints(string $table): array
    {
        $foreignKeys = $this->schemaHelper->getForeignKeys($table, $this->connection);
        $constraints = [];

        foreach ($foreignKeys as $fk) {
            $localColumn = $fk['local_columns'][0] ?? null;
            $foreignTable = $fk['foreign_table'] ?? null;
            $foreignColumn = $fk['foreign_columns'][0] ?? null;

            if ($localColumn && $foreignTable && $foreignColumn) {
                $constraints[] = [
                    'local_column' => $localColumn,
                    'foreign_table' => $foreignTable,
                    'foreign_column' => $foreignColumn,
                    'on_delete' => $fk['on_delete'] ?? null,
                    'on_update' => $fk['on_update'] ?? null,
                    'cascade_delete' => ($fk['on_delete'] ?? '') === 'CASCADE',
                    'cascade_update' => ($fk['on_update'] ?? '') === 'CASCADE',
                ];
            }
        }

        return $constraints;
    }

    /**
     * Map polymorphic relationships for the specified table.
     *
     * @param string $table The name of the database table
     * @return array The mapped polymorphic relationships
     */
    public function mapPolymorphicRelationships(string $table): array
    {
        $columns = $this->schemaHelper->getTableColumns($table, $this->connection);
        $polymorphic = [];

        // Look for *_type columns and check if matching *_id columns exist
        foreach ($columns as $column => $details) {
            if (Str::endsWith($column, '_type')) {
                $morphName = Str::beforeLast($column, '_type');
                $idColumn = "{$morphName}_id";

                if (isset($columns[$idColumn])) {
                    $morphTo = [
                        'type' => 'morphTo',
                        'morph_name' => $morphName,
                        'type_column' => $column,
                        'id_column' => $idColumn,
                        'method' => $this->relationshipHelper->formatRelationshipMethod('morphTo', $morphName)
                    ];

                    $polymorphic['morphTo'][] = $morphTo;
                }
            }
        }

        // Look for tables that might have polymorphic relations to this table
        $allTables = $this->schemaHelper->getAllTables($this->connection);
        $modelName = Str::studly(Str::singular($table));

        foreach ($allTables as $potentialTarget) {
            if ($potentialTarget === $table) {
                continue;
            }

            $targetColumns = $this->schemaHelper->getTableColumns($potentialTarget, $this->connection);

            // Look for *_type and *_id pairs
            foreach ($targetColumns as $column => $details) {
                if (Str::endsWith($column, '_type')) {
                    $morphName = Str::beforeLast($column, '_type');
                    $idColumn = "{$morphName}_id";

                    if (isset($targetColumns[$idColumn])) {
                        // Check if this table is referenced in type column values
                        $typeValues = [$modelName, "App\\Models\\{$modelName}", $table];

                        foreach ($typeValues as $typeValue) {
                            if ($this->schemaHelper->tableHasValue($potentialTarget, $column, $typeValue, $this->connection)) {
                                // Check if this should be morphOne or morphMany
                                $isUnique = $this->schemaHelper->hasUniqueConstraint(
                                    $potentialTarget,
                                    [$column, $idColumn],
                                    $this->connection
                                );

                                $type = $isUnique ? 'morphOne' : 'morphMany';

                                $polymorphic[$type][] = [
                                    'type' => $type,
                                    'related_table' => $potentialTarget,
                                    'morph_name' => $morphName,
                                    'type_column' => $column,
                                    'id_column' => $idColumn,
                                    'type_value' => $typeValue,
                                    'method' => $this->relationshipHelper->formatRelationshipMethod($type, $potentialTarget)
                                ];

                                break;
                            }
                        }
                    }
                }
            }
        }

        return $polymorphic;
    }

    /**
     * Detect pivot tables for many-to-many relationships.
     *
     * @param string $table The name of the database table
     * @return array The detected pivot tables
     */
    public function detectPivotTables(string $table): array
    {
        $allTables = $this->schemaHelper->getAllTables($this->connection);
        $pivotTables = [];
        $tableSingular = Str::singular($table);

        foreach ($allTables as $potentialPivot) {
            // Skip the table itself
            if ($potentialPivot === $table) {
                continue;
            }

            // Get foreign keys for the potential pivot table
            $foreignKeys = $this->schemaHelper->getForeignKeys($potentialPivot, $this->connection);

            // Check if this table has the characteristics of a pivot table
            if (count($foreignKeys) >= 2) {
                $referencesTable = false;
                $otherTables = [];

                foreach ($foreignKeys as $fk) {
                    $foreignTable = $fk['foreign_table'] ?? null;

                    if ($foreignTable === $table) {
                        $referencesTable = true;
                    } elseif ($foreignTable) {
                        $otherTables[] = $foreignTable;
                    }
                }

                if ($referencesTable && count($otherTables) > 0) {
                    // Check naming convention
                    $isPivotByNaming = false;
                    foreach ($otherTables as $otherTable) {
                        $otherSingular = Str::singular($otherTable);
                        $patterns = [
                            "{$tableSingular}_{$otherSingular}",
                            "{$otherSingular}_{$tableSingular}",
                            "{$table}_{$otherTable}",
                            "{$otherTable}_{$table}"
                        ];

                        if (in_array($potentialPivot, $patterns)) {
                            $isPivotByNaming = true;
                            break;
                        }
                    }

                    $pivotTables[] = [
                        'table' => $potentialPivot,
                        'related_tables' => $otherTables,
                        'by_naming_convention' => $isPivotByNaming,
                        'foreign_keys' => $foreignKeys
                    ];
                }
            }
        }

        return $pivotTables;
    }

    /**
     * Identify naming conventions used in the database.
     *
     * @return array The naming conventions identified
     */
    public function identifyNamingConventions(): array
    {
        $conventions = [
            'foreign_keys' => [],
            'pivot_tables' => [],
            'polymorphic' => []
        ];

        // Check common foreign key naming patterns
        $allTables = $this->schemaHelper->getAllTables($this->connection);
        $foreignKeySamples = [];

        // Sample some foreign keys
        $sampleSize = min(count($allTables), 5);
        $sampleTables = array_slice($allTables, 0, $sampleSize);

        foreach ($sampleTables as $table) {
            $fks = $this->schemaHelper->getForeignKeys($table, $this->connection);
            foreach ($fks as $fk) {
                $foreignKeySamples[] = [
                    'local_column' => $fk['local_columns'][0] ?? null,
                    'foreign_table' => $fk['foreign_table'] ?? null,
                ];
            }
        }

        // Analyze foreign key patterns
        $idSuffix = 0;
        $tablePrefixed = 0;
        $tableUnderscoreId = 0;

        foreach ($foreignKeySamples as $fk) {
            $localColumn = $fk['local_column'];
            $foreignTable = $fk['foreign_table'];
            $singularTable = Str::singular($foreignTable);

            if ($localColumn === 'id' . Str::studly($singularTable)) {
                $idSuffix++;
            }

            if ($localColumn === $singularTable . '_id') {
                $tableUnderscoreId++;
            }

            if (Str::startsWith($localColumn, $singularTable)) {
                $tablePrefixed++;
            }
        }

        // Determine dominant pattern
        $total = count($foreignKeySamples);
        if ($total > 0) {
            if ($idSuffix / $total > 0.5) {
                $conventions['foreign_keys'] = 'idSuffixed'; // idUser
            } elseif ($tableUnderscoreId / $total > 0.5) {
                $conventions['foreign_keys'] = 'table_id'; // user_id
            } elseif ($tablePrefixed / $total > 0.5) {
                $conventions['foreign_keys'] = 'tablePrefixed'; // user_something
            }
        }

        // Analyze polymorphic naming
        $columns = [];
        foreach ($sampleTables as $table) {
            $tableColumns = $this->schemaHelper->getTableColumns($table, $this->connection);
            foreach ($tableColumns as $column => $details) {
                $columns[] = $column;
            }
        }

        $typeColumns = array_filter($columns, function ($column) {
            return Str::endsWith($column, '_type');
        });

        foreach ($typeColumns as $typeColumn) {
            $morphName = Str::beforeLast($typeColumn, '_type');
            $idColumn = "{$morphName}_id";

            if (in_array($idColumn, $columns)) {
                $conventions['polymorphic'][] = $morphName;
            }
        }

        return $conventions;
    }

    /**
     * Create bidirectional mappings of relationships.
     *
     * @param array $relationships The relationship definitions
     * @return array The bidirectional mappings
     */
    public function createBidirectionalMappings(array $relationships): array
    {
        $mappings = [];

        foreach ($relationships as $relationship) {
            $sourceTable = $relationship['local_table'] ?? $this->results['table'] ?? null;
            $targetTable = $relationship['related_table'] ?? $relationship['foreign_table'] ?? null;

            if (!$sourceTable || !$targetTable) {
                continue;
            }

            $key = "{$sourceTable}_{$targetTable}";

            $mapping = [
                'source_table' => $sourceTable,
                'target_table' => $targetTable,
                'forward' => [
                    'type' => $relationship['type'],
                    'method' => $relationship['method']
                ]
            ];

            // Add inverse relationship if available
            if (isset($relationship['inverse_type']) && isset($relationship['inverse_method'])) {
                $mapping['inverse'] = [
                    'type' => $relationship['inverse_type'],
                    'method' => $relationship['inverse_method']
                ];
            }

            $mappings[$key] = $mapping;
        }

        return $mappings;
    }

    /**
     * Handle complex relationship scenarios.
     *
     * @param string $table The name of the database table
     * @return array Complex relationships analysis
     */
    public function handleComplexRelationships(string $table): array
    {
        $complex = [];

        // Detect circular references
        $circularRelations = $this->detectCircularReferences($table);
        if (!empty($circularRelations)) {
            $complex['circular'] = $circularRelations;
        }

        // Detect self-referential relationships
        $selfReferences = $this->detectSelfReferences($table);
        if (!empty($selfReferences)) {
            $complex['self_referential'] = $selfReferences;
        }

        // Detect polymorphic with multiple types
        $polymorphicTypes = $this->detectPolymorphicWithMultipleTypes($table);
        if (!empty($polymorphicTypes)) {
            $complex['polymorphic_multi_type'] = $polymorphicTypes;
        }

        return $complex;
    }

    /**
     * Detect circular references in relationships.
     *
     * @param string $table The name of the database table
     * @return array The circular references detected
     */
    protected function detectCircularReferences(string $table): array
    {
        $circular = [];
        $allTables = $this->schemaHelper->getAllTables($this->connection);
        $visited = [];

        // Build a simple directed graph of foreign key relationships
        $graph = [];
        foreach ($allTables as $sourceTable) {
            $graph[$sourceTable] = [];
            $foreignKeys = $this->schemaHelper->getForeignKeys($sourceTable, $this->connection);

            foreach ($foreignKeys as $fk) {
                $targetTable = $fk['foreign_table'] ?? null;
                if ($targetTable) {
                    $graph[$sourceTable][] = $targetTable;
                }
            }
        }

        // DFS to find cycles starting from our table
        $path = [];
        $this->findCycles($graph, $table, $visited, $path, $circular);

        return $circular;
    }

    /**
     * DFS helper function to find cycles in a directed graph.
     *
     * @param array $graph The directed graph
     * @param string $current The current node being visited
     * @param array $visited Nodes that have been visited
     * @param array $path The current path being explored
     * @param array $cycles Reference to array where cycles are stored
     * @return void
     */
    protected function findCycles(array $graph, string $current, array &$visited, array $path, array &$cycles): void
    {
        $visited[$current] = true;
        $path[] = $current;

        foreach ($graph[$current] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $this->findCycles($graph, $neighbor, $visited, $path, $cycles);
            } elseif (in_array($neighbor, $path)) {
                // Found a cycle
                $cyclePath = [];
                $start = array_search($neighbor, $path);
                for ($i = $start; $i < count($path); $i++) {
                    $cyclePath[] = $path[$i];
                }
                $cyclePath[] = $neighbor; // Close the cycle

                $cycles[] = [
                    'path' => $cyclePath,
                    'start_table' => $neighbor,
                    'tables_involved' => array_unique($cyclePath)
                ];
            }
        }

        array_pop($path);
        unset($visited[$current]);
    }

    /**
     * Detect self-referential relationships in a table.
     *
     * @param string $table The name of the database table
     * @return array The self-referential relationships detected
     */
    protected function detectSelfReferences(string $table): array
    {
        $selfReferences = [];
        $foreignKeys = $this->schemaHelper->getForeignKeys($table, $this->connection);

        foreach ($foreignKeys as $fk) {
            $targetTable = $fk['foreign_table'] ?? null;
            if ($targetTable === $table) {
                $selfReferences[] = [
                    'column' => $fk['local_columns'][0] ?? 'unknown',
                    'foreign_column' => $fk['foreign_columns'][0] ?? 'unknown',
                    'relationship_type' => 'self-reference'
                ];
            }
        }

        return $selfReferences;
    }

    /**
     * Detect polymorphic relationships with multiple types.
     *
     * @param string $table The name of the database table
     * @return array The polymorphic relationships with multiple types
     */
    protected function detectPolymorphicWithMultipleTypes(string $table): array
    {
        $multiType = [];
        $columns = $this->schemaHelper->getTableColumns($table, $this->connection);

        foreach ($columns as $column => $details) {
            if (Str::endsWith($column, '_type')) {
                $morphName = Str::beforeLast($column, '_type');
                $idColumn = "{$morphName}_id";

                if (isset($columns[$idColumn])) {
                    $distinctTypes = [];

                    try {
                        $distinctTypes = $this->schemaHelper->getDistinctValues($table, $column, $this->connection);
                    } catch (\Exception $e) {
                        // Table might not exist or be empty
                    }

                    if (count($distinctTypes) > 1) {
                        $multiType[] = [
                            'morph_name' => $morphName,
                            'type_column' => $column,
                            'id_column' => $idColumn,
                            'distinct_types' => $distinctTypes
                        ];
                    }
                }
            }
        }

        return $multiType;
    }

    /**
     * Extract all method definitions from relationship results.
     *
     * @param array $relationshipTypes The relationship results by type
     * @return array The method definitions from all analyzers
     */
    protected function extractMethodDefinitions(array $relationshipTypes): array
    {
        $methodDefinitions = [];

        foreach ($relationshipTypes as $type => $result) {
            if (!empty($result['method_definitions'])) {
                $methodDefinitions = array_merge($methodDefinitions, $result['method_definitions']);
            }
        }

        return $methodDefinitions;
    }

    /**
     * Extract collection methods from relationship results.
     *
     * @param array $relationshipTypes The relationship results by type
     * @return array The collection methods from all analyzers
     */
    protected function extractCollectionMethods(array $relationshipTypes): array
    {
        $collectionMethods = [];

        foreach ($relationshipTypes as $type => $result) {
            if (!empty($result['collection_methods'])) {
                $collectionMethods = array_merge($collectionMethods, $result['collection_methods']);
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
     * Get the database schema instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getSchema()
    {
        return $this->schemaHelper->getSchema($this->connection);
    }

    /**
     * Get the database name.
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        return $this->schemaHelper->getDatabaseName($this->connection);
    }

    /**
     * Get the supported relationship types.
     *
     * @return array
     */
    public function supportedRelationships() : array
    {
        return [
            'hasMany',
            'hasOne',
            'belongsTo',
            'belongsToMany',
            'morphTo',
            'morphMany',
            'morphOne',
            'hasManyThrough',
            'hasOneThrough',
            'morphToMany',
            'morphedByMany'
        ];
    }
}
