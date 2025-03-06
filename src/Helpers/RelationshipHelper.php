<?php

namespace SwatTech\Crud\Helpers;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;

/**
 * RelationshipHelper
 *
 * This class provides utility methods for working with database relationships.
 * It helps with guessing relationship types, formatting method names,
 * identifying related tables, and analyzing relationship structures.
 *
 * @package SwatTech\Crud\Helpers
 */
class RelationshipHelper
{
    /**
     * Guess the appropriate relationship method name based on table and foreign key.
     *
     * Analyzes the foreign key and table name to determine the most likely
     * relationship type (belongsTo, hasMany, hasOne) and corresponding method name.
     *
     * @param string $table The name of the current table
     * @param string $foreignKey The foreign key name
     * @return array An array with keys 'type' and 'method' for the relationship
     */
    public static function guessRelationshipMethod(string $table, string $foreignKey): array
    {
        $config = config('crud.relationships.naming_conventions');

        // Check if foreign key is in current table (belongsTo relationship)
        if (SchemaHelper::hasColumn($table, $foreignKey)) {
            // Extract table name from foreign key (e.g., 'user_id' -> 'user')
            $relatedTable = self::getRelatedTable($foreignKey);

            if (!empty($relatedTable)) {
                $singularRelatedTable = StringHelper::singularize($relatedTable);
                return [
                    'type' => 'belongsTo',
                    'method' => StringHelper::camelCase(str_replace('_id', '', $foreignKey)),
                    'related_table' => $relatedTable
                ];
            }
        }

        // If we're looking at a potential parent table for hasMany/hasOne
        $tableIdFormat = $table . '_id';
        $singularTable = StringHelper::singularize($table);
        $singularIdFormat = $singularTable . '_id';

        if ($foreignKey === $tableIdFormat || $foreignKey === $singularIdFormat) {
            $relatedTable = str_replace('_id', '', $foreignKey);

            // Check if we should use hasOne or hasMany based on table naming
            $isPlural = StringHelper::pluralize($relatedTable) === $relatedTable;
            $type = $isPlural ? 'hasMany' : 'hasOne';

            return [
                'type' => $type,
                'method' => StringHelper::camelCase($isPlural ? StringHelper::pluralize($relatedTable) : $relatedTable),
                'related_table' => $relatedTable
            ];
        }

        // Default to unknown relationship
        return [
            'type' => 'unknown',
            'method' => StringHelper::camelCase($foreignKey),
            'related_table' => null
        ];
    }

    /**
     * Guess the inverse relationship type for a given relationship type.
     *
     * For a given relationship type, determine what the inverse relationship would be.
     * For example, the inverse of hasMany is belongsTo.
     *
     * @param string $relationshipType The original relationship type
     * @return string|null The inverse relationship type or null if no inverse exists
     */
    public static function guessInverseRelationship(string $relationshipType): ?string
    {
        $inverses = [
            'hasMany' => 'belongsTo',
            'hasOne' => 'belongsTo',
            'belongsTo' => 'hasMany', // Could be hasOne in some cases
            'belongsToMany' => 'belongsToMany',
            'morphMany' => 'morphTo',
            'morphOne' => 'morphTo',
            'morphTo' => 'morphMany', // Could be morphOne in some cases
        ];

        return $inverses[$relationshipType] ?? null;
    }

    /**
     * Format a relationship method name based on type and related table.
     *
     * Generates appropriate method names for relationships following Laravel conventions.
     *
     * @param string $type The type of relationship (belongsTo, hasMany, etc.)
     * @param string $relatedTable The name of the related table
     * @return string The formatted method name
     */
    public static function formatRelationshipMethod(string $type, string $relatedTable): string
    {
        $config = config('crud.relationships.naming_conventions');
        $tableName = StringHelper::singularize($relatedTable);

        switch ($type) {
            case 'belongsTo':
                $pattern = $config['belongs_to_method'] ?? '{model}';
                return StringHelper::camelCase(
                    str_replace('{model}', $tableName, $pattern)
                );

            case 'hasMany':
                $pattern = $config['has_many_method'] ?? '{models}';
                return StringHelper::camelCase(
                    str_replace('{models}', StringHelper::pluralize($tableName), $pattern)
                );

            case 'hasOne':
                $pattern = $config['has_one_method'] ?? '{model}';
                return StringHelper::camelCase(
                    str_replace('{model}', $tableName, $pattern)
                );

            case 'belongsToMany':
                $pattern = $config['belongs_to_many_method'] ?? '{models}';
                return StringHelper::camelCase(
                    str_replace('{models}', StringHelper::pluralize($tableName), $pattern)
                );

            case 'morphMany':
                $pattern = $config['morph_many_method'] ?? '{models}';
                return StringHelper::camelCase(
                    str_replace('{models}', StringHelper::pluralize($tableName), $pattern)
                );

            case 'morphTo':
                $pattern = $config['morph_to_method'] ?? '{name}';
                return StringHelper::camelCase(
                    str_replace('{name}', $tableName, $pattern)
                );

            default:
                return StringHelper::camelCase($relatedTable);
        }
    }

    /**
     * Extract the related table name from a foreign key.
     *
     * Attempts to determine the related table name by analyzing the foreign key
     * based on common naming conventions.
     *
     * @param string $foreignKey The foreign key column name (e.g., user_id)
     * @return string|null The related table name or null if it can't be determined
     */
    public static function getRelatedTable(string $foreignKey): ?string
    {
        // Most common convention: {table}_id
        if (Str::endsWith($foreignKey, '_id')) {
            return substr($foreignKey, 0, -3);
        }

        // Try to handle custom foreign keys by checking if tables exist
        $possibleTables = [
            $foreignKey,                  // If the key is the table name itself
            StringHelper::pluralize($foreignKey),  // If the key is singular form
            StringHelper::singularize($foreignKey),  // If the key is plural form
        ];

        foreach ($possibleTables as $table) {
            if (SchemaHelper::tableExists($table)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Get the appropriate method arguments for a relationship type.
     *
     * Generates the arguments needed for a specific relationship method in Laravel
     * Eloquent models.
     *
     * @param string $type The type of relationship (belongsTo, hasMany, etc.)
     * @param string $relatedTable The name of the related table
     * @param string $foreignKey The foreign key column name
     * @return array The arguments for the relationship method
     */
    public static function getRelationshipArguments(string $type, string $relatedTable, string $foreignKey): array
    {
        $modelName = StringHelper::studlyCase(StringHelper::singularize($relatedTable));
        $args = ["\\App\\Models\\{$modelName}::class"];

        switch ($type) {
            case 'belongsTo':
                $args[] = "'{$foreignKey}'";
                $args[] = "'id'";
                break;

            case 'hasMany':
            case 'hasOne':
                $tableName = StringHelper::snakeCase(StringHelper::singularize($relatedTable));
                $args[] = "'{$foreignKey}'";
                $args[] = "'{$tableName}_id'";
                break;

            case 'belongsToMany':
                $pivotTableName = self::guessPivotTableName(
                    StringHelper::singularize($relatedTable),
                    StringHelper::singularize($foreignKey)
                );
                $args[] = "\\App\\Models\\{$modelName}::class";
                $args[] = "'{$pivotTableName}'";
                $args[] = "'" . StringHelper::singularize($foreignKey) . "_id'";
                $args[] = "'" . StringHelper::singularize($relatedTable) . "_id'";
                break;

            case 'morphMany':
            case 'morphOne':
                $args[] = "'{$foreignKey}'";
                break;

            case 'morphTo':
                $args[] = "'{$foreignKey}'";
                $args[] = "'{$foreignKey}_type'";
                break;
        }

        return $args;
    }

    /**
     * Build a relationship tree for a set of tables.
     *
     * Analyzes the given tables and builds a comprehensive tree of relationships
     * between them.
     *
     * @param array $tables An array of table names to analyze
     * @return array The relationship tree with all connections
     */
    public static function buildRelationshipTree(array $tables): array
    {
        $relationships = [];

        foreach ($tables as $table) {
            $foreignKeys = SchemaHelper::getForeignKeys($table);
            foreach ($foreignKeys as $fk) {
                $localColumn = $fk['local_columns'][0] ?? null;
                $foreignTable = $fk['foreign_table'] ?? null;
                $foreignColumn = $fk['foreign_columns'][0] ?? null;

                if ($localColumn && $foreignTable && $foreignColumn) {
                    // Add the detected relationship
                    $relationships[$table][] = [
                        'type' => 'belongsTo',
                        'local_table' => $table,
                        'related_table' => $foreignTable,
                        'foreign_key' => $localColumn,
                        'related_key' => $foreignColumn,
                        'method' => self::formatRelationshipMethod('belongsTo', $foreignTable)
                    ];

                    // Add the inverse relationship
                    $inverseType = self::guessInverseRelationship('belongsTo');
                    $relationships[$foreignTable][] = [
                        'type' => $inverseType,
                        'local_table' => $foreignTable,
                        'related_table' => $table,
                        'foreign_key' => $foreignColumn,
                        'related_key' => $localColumn,
                        'method' => self::formatRelationshipMethod($inverseType, $table)
                    ];
                }
            }

            // Look for pivot tables (for many-to-many relationships)
            $pivotTables = self::detectPotentialPivotTables($tables, $table);
            foreach ($pivotTables as $pivotTable) {
                $pivotForeignKeys = SchemaHelper::getForeignKeys($pivotTable);
                $relations = self::analyzePivotTable($pivotTable, $pivotForeignKeys);

                foreach ($relations as $relation) {
                    if ($relation['first_table'] === $table) {
                        $relationships[$table][] = [
                            'type' => 'belongsToMany',
                            'local_table' => $table,
                            'related_table' => $relation['second_table'],
                            'pivot_table' => $pivotTable,
                            'pivot_foreign_key' => $relation['first_foreign_key'],
                            'pivot_related_key' => $relation['second_foreign_key'],
                            'method' => self::formatRelationshipMethod('belongsToMany', $relation['second_table'])
                        ];
                    }
                }
            }

            // Detect potential polymorphic relationships
            $polymorphicRelations = self::detectPolymorphicRelations($table);
            foreach ($polymorphicRelations as $relation) {
                $relationships[$table][] = $relation;
            }
        }

        return $relationships;
    }

    /**
     * Detect circular relationships in a relationship structure.
     *
     * Identifies and flags circular relationships which could cause issues
     * with cascade operations or infinite loops.
     *
     * @param array $relationships The relationship structure to analyze
     * @return array The circular relationships detected
     */
    public static function detectCircularRelationships(array $relationships): array
    {
        $circular = [];

        foreach ($relationships as $table => $tableRelations) {
            foreach ($tableRelations as $relation) {
                if ($relation['type'] === 'belongsTo') {
                    $path = self::findPath($relationships, $relation['related_table'], $table, [$table]);
                    if (!empty($path)) {
                        $circular[] = [
                            'start_table' => $table,
                            'end_table' => $relation['related_table'],
                            'path' => $path
                        ];
                    }
                }
            }
        }

        return $circular;
    }

    /**
     * Helper method to find a path between tables in relationships.
     *
     * @param array $relationships The full relationship structure
     * @param string $start The starting table
     * @param string $end The target table
     * @param array $visited Already visited tables
     * @return array The path found or empty array if none
     */
    protected static function findPath(array $relationships, string $start, string $end, array $visited = []): array
    {
        if ($start === $end) {
            return $visited;
        }

        if (!isset($relationships[$start])) {
            return [];
        }

        foreach ($relationships[$start] as $relation) {
            if ($relation['type'] === 'belongsTo' && !in_array($relation['related_table'], $visited)) {
                $path = self::findPath(
                    $relationships,
                    $relation['related_table'],
                    $end,
                    array_merge($visited, [$relation['related_table']])
                );

                if (!empty($path)) {
                    return $path;
                }
            }
        }

        return [];
    }

    /**
     * Generate Eloquent relationship methods code from a relationship array.
     *
     * Creates the actual PHP code for relationship methods that can be added to models.
     *
     * @param array $relationships The relationships to generate methods for
     * @return array The generated relationship method codes
     */
    public static function generateRelationshipMethods(array $relationships): array
    {
        $methods = [];

        foreach ($relationships as $relation) {
            $methodName = $relation['method'];
            $relationType = $relation['type'];
            $relatedModel = StringHelper::studlyCase(StringHelper::singularize($relation['related_table']));

            $args = [];
            switch ($relationType) {
                case 'belongsTo':
                    $args = [
                        "\\App\\Models\\{$relatedModel}::class",
                        "'{$relation['foreign_key']}'",
                        "'{$relation['related_key']}'"
                    ];
                    break;

                case 'hasMany':
                case 'hasOne':
                    $args = [
                        "\\App\\Models\\{$relatedModel}::class",
                        "'{$relation['related_key']}'",
                        "'{$relation['foreign_key']}'"
                    ];
                    break;

                case 'belongsToMany':
                    $args = [
                        "\\App\\Models\\{$relatedModel}::class",
                        "'{$relation['pivot_table']}'",
                        "'{$relation['pivot_foreign_key']}'",
                        "'{$relation['pivot_related_key']}'"
                    ];
                    break;

                case 'morphMany':
                case 'morphOne':
                    $args = [
                        "\\App\\Models\\{$relatedModel}::class",
                        "'{$relation['morph_name']}'",
                    ];
                    break;

                case 'morphTo':
                    $args = [
                        "'{$relation['morph_name']}'",
                    ];
                    break;
            }

            $argsStr = implode(', ', $args);
            $methods[$methodName] = <<<PHP
/**
 * Get the {$methodName} {$relationType} relationship.
 *
 * @return \\Illuminate\\Database\\Eloquent\\Relations\\{$relationType}
 */
public function {$methodName}()
{
    return \$this->{$relationType}({$argsStr});
}
PHP;
        }

        return $methods;
    }

    /**
     * Validate a set of relationships for correctness and consistency.
     *
     * Checks relationships for errors such as missing tables, invalid foreign keys,
     * or inconsistencies in the relationship structure.
     *
     * @param array $relationships The relationships to validate
     * @return array Validation errors found
     */
    public static function validateRelationships(array $relationships): array
    {
        $errors = [];

        foreach ($relationships as $table => $tableRelations) {
            if (!SchemaHelper::tableExists($table)) {
                $errors[] = "Table '{$table}' does not exist in the database.";
                continue;
            }

            foreach ($tableRelations as $relation) {
                $relatedTable = $relation['related_table'] ?? null;

                if (!SchemaHelper::tableExists($relatedTable)) {
                    $errors[] = "Related table '{$relatedTable}' for '{$table}' does not exist.";
                }

                switch ($relation['type']) {
                    case 'belongsTo':
                        $foreignKey = $relation['foreign_key'] ?? null;
                        if (!SchemaHelper::hasColumn($table, $foreignKey)) {
                            $errors[] = "Foreign key '{$foreignKey}' does not exist in table '{$table}'.";
                        }
                        break;

                    case 'hasMany':
                    case 'hasOne':
                        $foreignKey = $relation['related_key'] ?? null;
                        if (!SchemaHelper::hasColumn($relatedTable, $foreignKey)) {
                            $errors[] = "Foreign key '{$foreignKey}' does not exist in table '{$relatedTable}'.";
                        }
                        break;

                    case 'belongsToMany':
                        $pivotTable = $relation['pivot_table'] ?? null;
                        if (!SchemaHelper::tableExists($pivotTable)) {
                            $errors[] = "Pivot table '{$pivotTable}' does not exist.";
                        } else {
                            $firstKey = $relation['pivot_foreign_key'] ?? null;
                            $secondKey = $relation['pivot_related_key'] ?? null;

                            if (!SchemaHelper::hasColumn($pivotTable, $firstKey)) {
                                $errors[] = "Pivot key '{$firstKey}' does not exist in table '{$pivotTable}'.";
                            }

                            if (!SchemaHelper::hasColumn($pivotTable, $secondKey)) {
                                $errors[] = "Pivot key '{$secondKey}' does not exist in table '{$pivotTable}'.";
                            }
                        }
                        break;
                }
            }
        }

        return $errors;
    }

    /**
     * Get all potential relationships for a specific table.
     *
     * Analyzes the database to find all possible relationships for the given table.
     *
     * @param string $table The table to find relationships for
     * @return array All potential relationships
     */
    public static function getPotentialRelations(string $table): array
    {
        $relations = [];

        // Find belongsTo relationships (foreign keys in this table)
        $foreignKeys = SchemaHelper::getForeignKeys($table);
        foreach ($foreignKeys as $fk) {
            $localColumn = $fk['local_columns'][0] ?? null;
            $foreignTable = $fk['foreign_table'] ?? null;
            $foreignColumn = $fk['foreign_columns'][0] ?? null;

            if ($localColumn && $foreignTable) {
                $relations[] = [
                    'type' => 'belongsTo',
                    'related_table' => $foreignTable,
                    'foreign_key' => $localColumn,
                    'related_key' => $foreignColumn,
                    'method' => self::formatRelationshipMethod('belongsTo', $foreignTable)
                ];
            }
        }

        // Find hasMany/hasOne relationships (references to this table's primary key)
        $primaryKey = SchemaHelper::getPrimaryKey($table);
        if ($primaryKey) {
            $tables = SchemaHelper::getAllTables();
            foreach ($tables as $otherTable) {
                if ($otherTable === $table) {
                    continue;
                }

                $otherForeignKeys = SchemaHelper::getForeignKeys($otherTable);
                foreach ($otherForeignKeys as $fk) {
                    $localColumn = $fk['local_columns'][0] ?? null;
                    $foreignTable = $fk['foreign_table'] ?? null;
                    $foreignColumn = $fk['foreign_columns'][0] ?? null;

                    if ($foreignTable === $table && $foreignColumn === $primaryKey) {
                        // Check if this should be hasOne or hasMany
                        $type = self::shouldBeHasOne($table, $otherTable, $localColumn) ? 'hasOne' : 'hasMany';

                        $relations[] = [
                            'type' => $type,
                            'related_table' => $otherTable,
                            'foreign_key' => $primaryKey,
                            'related_key' => $localColumn,
                            'method' => self::formatRelationshipMethod($type, $otherTable)
                        ];
                    }
                }
            }
        }

        // Find belongsToMany relationships (pivot tables)
        $potentialPivotTables = self::detectPotentialPivotTables(SchemaHelper::getAllTables(), $table);
        foreach ($potentialPivotTables as $pivotTable) {
            $pivotForeignKeys = SchemaHelper::getForeignKeys($pivotTable);
            $pivotRelations = self::analyzePivotTable($pivotTable, $pivotForeignKeys);

            foreach ($pivotRelations as $relation) {
                if ($relation['first_table'] === $table) {
                    $relations[] = [
                        'type' => 'belongsToMany',
                        'related_table' => $relation['second_table'],
                        'pivot_table' => $pivotTable,
                        'pivot_foreign_key' => $relation['first_foreign_key'],
                        'pivot_related_key' => $relation['second_foreign_key'],
                        'method' => self::formatRelationshipMethod('belongsToMany', $relation['second_table'])
                    ];
                }
            }
        }

        // Find polymorphic relationships
        $polymorphicRelations = self::detectPolymorphicRelations($table);
        foreach ($polymorphicRelations as $relation) {
            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Helper method to determine if a relationship should be hasOne instead of hasMany.
     *
     * @param string $table The main table
     * @param string $relatedTable The related table
     * @param string $foreignKey The foreign key in the related table
     * @return bool True if it should be a hasOne relationship
     */
    protected static function shouldBeHasOne(string $table, string $relatedTable, string $foreignKey): bool
    {
        // Check for unique constraint on foreign key
        $uniqueColumns = SchemaHelper::getUniqueColumns($relatedTable);
        if (in_array($foreignKey, $uniqueColumns)) {
            return true;
        }

        // Check naming convention
        $expectedSingular = StringHelper::singularize($relatedTable);
        return StringHelper::singularize($table) === $expectedSingular;
    }

    /**
     * Helper method to detect potential pivot tables for a given table.
     *
     * @param array $allTables All tables in the database
     * @param string $table The table to find pivots for
     * @return array Potential pivot tables
     */
    protected static function detectPotentialPivotTables(array $allTables, string $table): array
    {
        $potentialPivots = [];
        $tableSingular = StringHelper::singularize($table);

        foreach ($allTables as $otherTable) {
            // Skip if same table
            if ($otherTable === $table) {
                continue;
            }

            // Check if table name contains both names
            $foreignKeys = SchemaHelper::getForeignKeys($otherTable);
            $tableNames = array_map(function ($fk) {
                return $fk['foreign_table'];
            }, $foreignKeys);

            $uniqueTableNames = array_unique($tableNames);

            // If this table has exactly 2 foreign keys and one points to our table
            if (count($foreignKeys) === 2 && count($uniqueTableNames) === 2 && in_array($table, $uniqueTableNames)) {
                $potentialPivots[] = $otherTable;
                continue;
            }

            // Check common naming conventions for pivot tables
            $patterns = [
                "{$tableSingular}_",
                "_{$tableSingular}",
                "{$table}_",
                "_{$table}"
            ];

            foreach ($patterns as $pattern) {
                if (strpos($otherTable, $pattern) !== false) {
                    $potentialPivots[] = $otherTable;
                    break;
                }
            }
        }

        return $potentialPivots;
    }

    /**
     * Analyze a potential pivot table to extract relationship information.
     *
     * @param string $pivotTable The pivot table name
     * @param array $foreignKeys Foreign keys in the pivot table
     * @return array The extracted relationships
     */
    protected static function analyzePivotTable(string $pivotTable, array $foreignKeys): array
    {
        if (count($foreignKeys) !== 2) {
            return [];
        }

        $relations = [];

        $firstFk = $foreignKeys[0];
        $secondFk = $foreignKeys[1];

        // Extract information for the first relationship
        $firstTable = $firstFk['foreign_table'];
        $firstForeignKey = $firstFk['local_columns'][0];

        // Extract information for the second relationship
        $secondTable = $secondFk['foreign_table'];
        $secondForeignKey = $secondFk['local_columns'][0];

        // Create relationships in both directions
        $relations[] = [
            'first_table' => $firstTable,
            'second_table' => $secondTable,
            'first_foreign_key' => $firstForeignKey,
            'second_foreign_key' => $secondForeignKey,
        ];

        $relations[] = [
            'first_table' => $secondTable,
            'second_table' => $firstTable,
            'first_foreign_key' => $secondForeignKey,
            'second_foreign_key' => $firstForeignKey,
        ];

        return $relations;
    }

    /**
     * Detect polymorphic relationships for a table.
     *
     * @param string $table The table to analyze
     * @return array Detected polymorphic relationships
     */
    protected static function detectPolymorphicRelations(string $table): array
    {
        $relations = [];
        $config = config('crud.relationships.naming_conventions');
        $columns = SchemaHelper::getTableColumns($table);

        // Check for potential morphTo relationship (this table has type and id columns)
        foreach ($columns as $column => $details) {
            if (Str::endsWith($column, '_type')) {
                $morphName = substr($column, 0, -5); // Remove _type suffix
                $idColumn = "{$morphName}_id";

                if (isset($columns[$idColumn])) {
                    $relations[] = [
                        'type' => 'morphTo',
                        'related_table' => null, // Can't know this for morphTo
                        'morph_name' => $morphName,
                        'method' => self::formatRelationshipMethod('morphTo', $morphName)
                    ];
                }
            }
        }

        // Check for potential morphMany/morphOne relationships
        $tables = SchemaHelper::getAllTables();
        foreach ($tables as $otherTable) {
            if ($otherTable === $table) {
                continue;
            }

            $otherColumns = SchemaHelper::getTableColumns($otherTable);
            foreach ($otherColumns as $column => $details) {
                if (Str::endsWith($column, '_type')) {
                    $morphName = substr($column, 0, -5); // Remove _type suffix
                    $idColumn = "{$morphName}_id";

                    if (isset($otherColumns[$idColumn])) {
                        // Check if this morphable refers to this table
                        $values = SchemaHelper::getEnumValues($otherTable, $column);
                        $modelClass = StringHelper::studlyCase(StringHelper::singularize($table));

                        if (empty($values) || in_array($modelClass, $values)) {
                            // Determine if this should be morphOne or morphMany
                            $type = 'morphMany'; // Default to many

                            // Check for unique constraint on morphable columns
                            $uniqueColumns = SchemaHelper::getUniqueColumns($otherTable);
                            if (in_array($idColumn, $uniqueColumns) && in_array($column, $uniqueColumns)) {
                                $type = 'morphOne';
                            }

                            $relations[] = [
                                'type' => $type,
                                'related_table' => $otherTable,
                                'morph_name' => $morphName,
                                'method' => self::formatRelationshipMethod($type, $otherTable)
                            ];
                        }
                    }
                }
            }
        }

        return $relations;
    }
    /**
     * Guess the pivot table name for a belongsToMany relationship.
     *
     * Follows Laravel's convention of using the two table names in singular form,
     * in alphabetical order, separated by an underscore.
     *
     * @param string $firstTable The first table name
     * @param string $secondTable The second table name
     * @return string The pivot table name
     */
    protected static function guessPivotTableName(string $firstTable, string $secondTable): string
    {
        $firstTable = StringHelper::singularize($firstTable);
        $secondTable = StringHelper::singularize($secondTable);

        // Arrange in alphabetical order
        $tables = [$firstTable, $secondTable];
        sort($tables);

        return implode('_', $tables);
    }
}
