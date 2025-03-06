<?php

namespace SwatTech\Crud\Analyzers;

use SwatTech\Crud\Contracts\AnalyzerInterface;
use SwatTech\Crud\Helpers\SchemaHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DatabaseAnalyzer
 *
 * Analyzes database tables to extract schema information including columns,
 * primary keys, foreign keys, indexes, and other metadata needed for
 * code generation.
 *
 * @package SwatTech\Crud\Analyzers
 */
class DatabaseAnalyzer implements AnalyzerInterface
{
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
     * Create a new DatabaseAnalyzer instance.
     *
     * @param SchemaHelper $schemaHelper
     */
    public function __construct(SchemaHelper $schemaHelper)
    {
        $this->schemaHelper = $schemaHelper;
    }

    /**
     * Analyze the specified database table structure.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table)
    {
        if (!Schema::connection($this->connection)->hasTable($table)) {
            $this->results = [
                'exists' => false,
                'table' => $table,
            ];
            
            return $this;
        }

        $this->results = [
            'exists' => true,
            'table' => $table,
            'structure' => $this->getTableStructure($table),
            'columns' => $this->analyzeColumns($table),
            'primary_key' => $this->detectPrimaryKey($table),
            'foreign_keys' => $this->detectForeignKeys($table),
            'indexes' => $this->mapIndexes($table),
            'unique_columns' => $this->identifyUniqueColumns($table),
            'has_timestamps' => $this->detectTimestampColumns($table),
            'enums' => $this->mapEnumValues($table),
            'has_soft_delete' => $this->detectSoftDeleteColumn($table),
            'metadata' => $this->generateSchemaMetadata($table),
        ];

        return $this;
    }

    /**
     * Get the table structure details.
     *
     * @param string $table The name of the database table
     * @return array Table structure information
     */
    public function getTableStructure(string $table): array
    {
        return [
            'name' => $table,
            'display_name' => Str::title(str_replace('_', ' ', $table)),
            'singular_name' => Str::singular($table),
            'model_name' => Str::studly(Str::singular($table)),
            'database' => $this->getDatabaseName(),
            'exists' => Schema::connection($this->connection)->hasTable($table),
            'connection' => $this->connection ?: config('database.default'),
            'engine' => $this->getDatabaseEngine($table),
            'collation' => $this->getDatabaseCollation($table),
            'comment' => $this->getTableComment($table),
        ];
    }

    /**
     * Analyze columns of the specified table.
     *
     * @param string $table The name of the database table
     * @return array Column details
     */
    public function analyzeColumns(string $table): array
    {
        return $this->schemaHelper->getTableColumns($table, $this->connection);
    }

    /**
     * Detect the primary key of the specified table.
     *
     * @param string $table The name of the database table
     * @return string|array|null The primary key column(s) or null if none
     */
    public function detectPrimaryKey(string $table)
    {
        return $this->schemaHelper->getPrimaryKey($table, $this->connection);
    }

    /**
     * Detect foreign keys in the specified table.
     *
     * @param string $table The name of the database table
     * @return array Foreign key information
     */
    public function detectForeignKeys(string $table): array
    {
        return $this->schemaHelper->getForeignKeys($table, $this->connection);
    }

    /**
     * Map all indexes in the specified table.
     *
     * @param string $table The name of the database table
     * @return array Index information
     */
    public function mapIndexes(string $table): array
    {
        return $this->schemaHelper->getTableIndexes($table, $this->connection);
    }

    /**
     * Identify unique columns in the specified table.
     *
     * @param string $table The name of the database table
     * @return array Unique column names
     */
    public function identifyUniqueColumns(string $table): array
    {
        return $this->schemaHelper->getUniqueColumns($table, $this->connection);
    }

    /**
     * Detect if the table has timestamp columns (created_at, updated_at).
     *
     * @param string $table The name of the database table
     * @return bool True if the table has timestamp columns
     */
    public function detectTimestampColumns(string $table): bool
    {
        return $this->schemaHelper->hasTimestamps($table, $this->connection);
    }

    /**
     * Map enum values for all enum columns in the specified table.
     *
     * @param string $table The name of the database table
     * @return array Enum column information with values
     */
    public function mapEnumValues(string $table): array
    {
        $columns = $this->analyzeColumns($table);
        $enumColumns = [];

        foreach ($columns as $column => $details) {
            if ($details['type'] === 'enum') {
                $enumColumns[$column] = $this->schemaHelper->getEnumValues(
                    $table,
                    $column,
                    $this->connection
                );
            }
        }

        return $enumColumns;
    }

    /**
     * Detect if the table has a soft delete column (deleted_at).
     *
     * @param string $table The name of the database table
     * @param string $softDeleteField The name of the soft delete field
     * @return bool True if the table has a soft delete column
     */
    public function detectSoftDeleteColumn(string $table, string $softDeleteField = 'deleted_at'): bool
    {
        return $this->schemaHelper->hasColumn($table, $softDeleteField, $this->connection);
    }

    /**
     * Generate additional metadata about the table schema.
     *
     * @param string $table The name of the database table
     * @return array Additional metadata
     */
    public function generateSchemaMetadata(string $table): array
    {
        $columns = $this->analyzeColumns($table);
        $columnTypes = [];
        $requiredColumns = [];
        $nullableColumns = [];
        $defaultValues = [];
        $autoIncrement = null;

        foreach ($columns as $column => $details) {
            $columnTypes[$column] = $details['type'];
            
            if (!$details['nullable'] && $details['default'] === null && !$details['autoincrement']) {
                $requiredColumns[] = $column;
            }
            
            if ($details['nullable']) {
                $nullableColumns[] = $column;
            }
            
            if ($details['default'] !== null) {
                $defaultValues[$column] = $details['default'];
            }
            
            if ($details['autoincrement']) {
                $autoIncrement = $column;
            }
        }

        return [
            'column_types' => $columnTypes,
            'required_columns' => $requiredColumns,
            'nullable_columns' => $nullableColumns,
            'default_values' => $defaultValues,
            'auto_increment' => $autoIncrement,
            'fillable_columns' => $this->getFillableColumns($columns),
            'guarded_columns' => $this->getGuardedColumns($columns),
            'date_columns' => $this->getDateColumns($columns),
            'searchable_columns' => $this->getSearchableColumns($columns),
        ];
    }

    /**
     * Get columns that should be fillable in the model.
     *
     * @param array $columns Column information
     * @return array Array of fillable column names
     */
    protected function getFillableColumns(array $columns): array
    {
        // Exclude common non-fillable columns
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at'];
        
        $fillable = [];
        foreach ($columns as $column => $details) {
            if (!in_array($column, $excluded) && !$details['autoincrement']) {
                $fillable[] = $column;
            }
        }
        
        return $fillable;
    }

    /**
     * Get columns that should be guarded in the model.
     *
     * @param array $columns Column information
     * @return array Array of guarded column names
     */
    protected function getGuardedColumns(array $columns): array
    {
        // Common guarded columns
        $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
        
        foreach ($columns as $column => $details) {
            if ($details['autoincrement']) {
                $guarded[] = $column;
            }
        }
        
        return array_unique($guarded);
    }

    /**
     * Get columns that should be cast as dates in the model.
     *
     * @param array $columns Column information
     * @return array Array of date column names
     */
    protected function getDateColumns(array $columns): array
    {
        $dateColumns = ['created_at', 'updated_at', 'deleted_at'];
        
        foreach ($columns as $column => $details) {
            if (in_array($details['type'], ['date', 'datetime', 'timestamp']) && !in_array($column, $dateColumns)) {
                $dateColumns[] = $column;
            }
        }
        
        return $dateColumns;
    }

    /**
     * Get columns that should be included in search functionality.
     *
     * @param array $columns Column information
     * @return array Array of searchable column names
     */
    protected function getSearchableColumns(array $columns): array
    {
        $searchable = [];
        
        foreach ($columns as $column => $details) {
            // Only include string-type fields in searchable columns
            if (in_array($details['type'], ['char', 'varchar', 'text', 'mediumtext', 'longtext'])) {
                $searchable[] = $column;
            }
        }
        
        return $searchable;
    }

    /**
     * Get the database engine for the specified table.
     *
     * @param string $table The name of the database table
     * @return string|null The database engine or null if not available
     */
    protected function getDatabaseEngine(string $table): ?string
    {
        try {
            $connection = DB::connection($this->connection);
            $databaseName = $connection->getDatabaseName();
            $result = $connection->select(
                "SHOW TABLE STATUS FROM `{$databaseName}` WHERE Name = ?",
                [$table]
            );
            
            return $result[0]->Engine ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the database collation for the specified table.
     *
     * @param string $table The name of the database table
     * @return string|null The database collation or null if not available
     */
    protected function getDatabaseCollation(string $table): ?string
    {
        try {
            $connection = DB::connection($this->connection);
            $databaseName = $connection->getDatabaseName();
            $result = $connection->select(
                "SHOW TABLE STATUS FROM `{$databaseName}` WHERE Name = ?",
                [$table]
            );
            
            return $result[0]->Collation ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the table comment if available.
     *
     * @param string $table The name of the database table
     * @return string|null The table comment or null if not available
     */
    protected function getTableComment(string $table): ?string
    {
        try {
            $connection = DB::connection($this->connection);
            $databaseName = $connection->getDatabaseName();
            $result = $connection->select(
                "SHOW TABLE STATUS FROM `{$databaseName}` WHERE Name = ?",
                [$table]
            );
            
            return $result[0]->Comment ?? null;
        } catch (\Exception $e) {
            return null;
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
     * @return mixed The schema information or helper
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
        return DB::connection($this->connection)->getDatabaseName();
    }

    /**
     * Get a list of relationship types supported by this analyzer.
     *
     * @return array<string> List of supported relationship types
     */
    public function supportedRelationships(): array
    {
        return []; // This analyzer doesn't directly handle relationships
    }
}