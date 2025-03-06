<?php

namespace SwatTech\Crud\Analyzers\Relationships;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Str;

/**
 * MorphToAnalyzer
 *
 * Analyzes database tables to detect morphTo polymorphic relationships based on
 * naming conventions of *_type and *_id column pairs. This analyzer identifies
 * polymorphic relationships where the current model belongs to multiple other models.
 *
 * @package SwatTech\Crud\Analyzers\Relationships
 */
class MorphToAnalyzer implements AnalyzerInterface
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
     * Create a new MorphToAnalyzer instance.
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
     * Analyze the specified database table for morphTo relationships.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        // Detect polymorphic columns in the table (e.g., commentable_type and commentable_id)
        $polymorphicColumns = $this->detectPolymorphicColumns($table);
        
        // Map the polymorphic relationships
        $relationships = $this->mapPolymorphicRelationships($table, $polymorphicColumns);
        
        // Handle custom polymorphic column configurations from config
        $customRelationships = $this->handleCustomTypeColumns($table);
        
        // Merge custom relationships with detected ones
        $relationships = array_merge($relationships, $customRelationships);
        
        // Identify potential target models for the polymorphic relationships
        $this->identifyTargetModels($relationships);
        
        // Map inverse relationships (morphMany/morphOne on the other side)
        $this->mapInverseRelationships($relationships);
        
        // Check for soft deletes in potential parent tables
        $this->detectSoftDeletesInPolymorphic($relationships);
        
        // Create method definitions for each relationship
        $methodDefinitions = $this->createMethodDefinitions($relationships);
        
        $this->results = [
            'table' => $table,
            'relationships' => $relationships,
            'method_definitions' => $methodDefinitions,
            'type' => 'morphTo'
        ];
        
        return $this;
    }

    /**
     * Detect polymorphic columns in the specified table.
     *
     * @param string $table The name of the database table
     * @return array The detected polymorphic column pairs
     */
    public function detectPolymorphicColumns(string $table): array
    {
        $polymorphicColumns = [];
        $columns = $this->schemaHelper->getTableColumns($table, $this->connection);
        
        // Look for *_type columns and check if matching *_id columns exist
        foreach ($columns as $column => $details) {
            if (Str::endsWith($column, '_type')) {
                $morphName = Str::beforeLast($column, '_type');
                $idColumn = "{$morphName}_id";
                
                // If we have both {name}_type and {name}_id columns, it's likely a polymorphic relationship
                if (isset($columns[$idColumn])) {
                    $polymorphicColumns[] = [
                        'morph_name' => $morphName,
                        'type_column' => $column,
                        'id_column' => $idColumn,
                        'type_column_details' => $details,
                        'id_column_details' => $columns[$idColumn]
                    ];
                }
            }
        }
        
        return $polymorphicColumns;
    }

    /**
     * Map polymorphic column pairs to relationship metadata.
     *
     * @param string $table The name of the database table
     * @param array $polymorphicColumns The detected polymorphic column pairs
     * @return array The mapped relationships
     */
    public function mapPolymorphicRelationships(string $table, array $polymorphicColumns): array
    {
        $relationships = [];
        
        foreach ($polymorphicColumns as $polymorphic) {
            $morphName = $polymorphic['morph_name'];
            $methodName = RelationshipHelper::formatRelationshipMethod('morphTo', $morphName);
            
            $relationships[] = [
                'type' => 'morphTo',
                'local_table' => $table,
                'morph_name' => $morphName,
                'type_column' => $polymorphic['type_column'],
                'id_column' => $polymorphic['id_column'],
                'method' => $methodName,
                'target_models' => [],
                'has_soft_deletes' => false, // Will be updated by detectSoftDeletesInPolymorphic
                'required' => !$polymorphic['type_column_details']['nullable'] && !$polymorphic['id_column_details']['nullable'],
                'inverse_relationships' => [], // Will be populated by mapInverseRelationships
                'comment' => "Get the owning {$morphName} model",
            ];
        }
        
        return $relationships;
    }

    /**
     * Identify potential target models for polymorphic relationships.
     *
     * @param array $relationships The polymorphic relationships
     * @return void
     */
    public function identifyTargetModels(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'morphTo') {
                $morphName = $relationship['morph_name'];
                $targetModels = [];
                
                // Get all tables that might have morphMany/morphOne to this table
                $allTables = $this->schemaHelper->getAllTables($this->connection);
                
                foreach ($allTables as $potentialParentTable) {
                    // Check if the potential parent table has a column for storing morph type values
                    // In a real app, we would check the actual values in the {name}_type column
                    // Here we're just making educated guesses based on naming conventions
                    
                    $modelName = Str::studly(Str::singular($potentialParentTable));
                    
                    // Check if there are records in the database with this model as the type
                    $typeValue = $this->getMorphTypeValue($relationship, $modelName);
                    
                    if ($typeValue) {
                        $targetModels[] = [
                            'table' => $potentialParentTable,
                            'model' => $modelName,
                            'type_value' => $typeValue
                        ];
                    }
                }
                
                $relationship['target_models'] = $targetModels;
            }
        }
    }

    /**
     * Get the value stored in the type column for a given model.
     *
     * @param array $relationship The relationship data
     * @param string $modelName The potential model name
     * @return string|null The type value or null if not found
     */
    protected function getMorphTypeValue(array $relationship, string $modelName): ?string
    {
        // In a real implementation, we might query the database to find actual values
        // For simplicity, we'll assume the type value follows Laravel's convention
        // of storing the fully qualified class name or just the model name
        
        // Check if the database has this model name as a type value
        $table = $relationship['local_table'];
        $typeColumn = $relationship['type_column'];
        
        try {
            // This is a simplified approach - in a real app you might check for distinct values
            $modelNamespace = "App\\Models\\{$modelName}";
            
            // Check for the table with this type value
            $result = $this->schemaHelper->tableHasValue($table, $typeColumn, $modelNamespace, $this->connection);
            
            if ($result) {
                return $modelNamespace;
            }
            
            // Sometimes just the model name is stored
            $result = $this->schemaHelper->tableHasValue($table, $typeColumn, $modelName, $this->connection);
            
            if ($result) {
                return $modelName;
            }
        } catch (\Exception $e) {
            // If there's an error (e.g., table doesn't exist), just return null
        }
        
        return null;
    }

    /**
     * Handle custom polymorphic column configurations from config.
     *
     * @param string $table The name of the database table
     * @return array Additional custom relationships
     */
    public function handleCustomTypeColumns(string $table): array
    {
        $customRelationships = [];
        $config = config('crud.relationships.custom_relationships', []);
        
        if (isset($config[$table])) {
            foreach ($config[$table] as $relation) {
                if ($relation['type'] === 'morphTo') {
                    $morphName = $relation['morph_name'];
                    $typeColumn = $relation['type_column'] ?? "{$morphName}_type";
                    $idColumn = $relation['id_column'] ?? "{$morphName}_id";
                    
                    $customRelationships[] = [
                        'type' => 'morphTo',
                        'local_table' => $table,
                        'morph_name' => $morphName,
                        'type_column' => $typeColumn,
                        'id_column' => $idColumn,
                        'method' => $relation['method'] ?? RelationshipHelper::formatRelationshipMethod('morphTo', $morphName),
                        'target_models' => $relation['target_models'] ?? [],
                        'has_soft_deletes' => false,
                        'required' => $relation['required'] ?? false,
                        'is_custom' => true,
                        'comment' => $relation['comment'] ?? "Get the owning {$morphName} model",
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
            if ($relationship['type'] === 'morphTo') {
                $methodName = $relationship['method'];
                $morphName = $relationship['morph_name'];
                
                // Build method code with appropriate typehints for potential target models
                $returnTypes = [];
                foreach ($relationship['target_models'] as $targetModel) {
                    $modelClass = $targetModel['model'];
                    $returnTypes[] = "\\App\\Models\\{$modelClass}";
                }
                
                $returnTypeHint = !empty($returnTypes) ? 
                    " * @return " . implode('|', $returnTypes) . "|\\Illuminate\\Database\\Eloquent\\Model" : 
                    " * @return \\Illuminate\\Database\\Eloquent\\Model";
                
                $methodDefinitions[$methodName] = [
                    'name' => $methodName,
                    'type' => 'morphTo',
                    'code' => <<<PHP
    /**
     * {$relationship['comment']}.
     *
{$returnTypeHint}
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
     * Map inverse relationships for detected polymorphic relationships.
     *
     * @param array $relationships The polymorphic relationships
     * @return void
     */
    public function mapInverseRelationships(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'morphTo') {
                $morphName = $relationship['morph_name'];
                $inverseRelationships = [];
                
                // For each target model, add the inverse relationship
                foreach ($relationship['target_models'] as $targetModel) {
                    $targetTable = $targetModel['table'];
                    
                    // Check if this should be morphOne or morphMany
                    // For this, we'd need to check if there's a unique constraint on the polymorphic columns
                    $isUnique = $this->schemaHelper->hasUniqueConstraint(
                        $relationship['local_table'],
                        [$relationship['type_column'], $relationship['id_column']],
                        $this->connection
                    );
                    
                    $inverseType = $isUnique ? 'morphOne' : 'morphMany';
                    $inverseMethod = RelationshipHelper::formatRelationshipMethod(
                        $inverseType,
                        $relationship['local_table']
                    );
                    
                    $inverseRelationships[] = [
                        'table' => $targetTable,
                        'model' => $targetModel['model'],
                        'type' => $inverseType,
                        'method' => $inverseMethod
                    ];
                }
                
                $relationship['inverse_relationships'] = $inverseRelationships;
            }
        }
    }

    /**
     * Check for soft deletes in potential parent tables of polymorphic relationships.
     *
     * @param array $relationships The polymorphic relationships
     * @return void
     */
    public function detectSoftDeletesInPolymorphic(array &$relationships): void
    {
        foreach ($relationships as &$relationship) {
            if ($relationship['type'] === 'morphTo') {
                $hasSoftDelete = false;
                
                // Check each target model's table for soft delete
                foreach ($relationship['target_models'] as $targetModel) {
                    $targetTable = $targetModel['table'];
                    if ($this->schemaHelper->hasSoftDelete($targetTable, 'deleted_at', $this->connection)) {
                        $hasSoftDelete = true;
                        break; // If any target has soft deletes, mark as true
                    }
                }
                
                $relationship['has_soft_deletes'] = $hasSoftDelete;
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
        return ['morphTo'];
    }
}