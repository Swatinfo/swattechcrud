<?php

namespace SwatTech\Crud\Helpers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Illuminate\Support\Arr;

/**
 * SchemaHelper
 *
 * This class provides utility methods to interact with database schema,
 * retrieve information about tables, columns, indexes, and relationships.
 * It abstracts the complexities of the underlying Schema and DB facades.
 *
 * @package SwatTech\Crud\Helpers
 */
class SchemaHelper
{
    /**
     * Get all columns for a specific table.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name (null for default)
     * @return array Array of column information with names, types, and properties
     */
    public static function getTableColumns(string $table, string $connection = null): array
    {
        $schema = Schema::connection($connection);

        if (!$schema->hasTable($table)) {
            return [];
        }

        $columns = [];
        $columnList = $schema->getColumnListing($table);

        foreach ($columnList as $columnName) {
            $columns[$columnName] = self::getColumnDetails($table, $columnName, $connection);
        }

        return $columns;
    }

    /**
     * Get detailed information about a specific column.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param string|null $connection The database connection name
     * @return array Column details including type, nullable status, default value, etc.
     */
    protected static function getColumnDetails(string $table, string $column, string $connection = null): array
    {
        $schema = Schema::connection($connection);
        $columnType = $schema->getColumnType($table, $column);

        return [
            'name' => $column,
            'type' => $columnType,
            'php_type' => self::columnTypeToPhpType($columnType),
            'form_type' => self::columnTypeToFormType($columnType),
            'nullable' => !$schema->getConnection()->getDoctrineColumn($table, $column)->getNotnull(),
            'default' => $schema->getConnection()->getDoctrineColumn($table, $column)->getDefault(),
            'autoincrement' => $schema->getConnection()->getDoctrineColumn($table, $column)->getAutoincrement(),
            'comment' => $schema->getConnection()->getDoctrineColumn($table, $column)->getComment() ?? '',
            'length' => $schema->getConnection()->getDoctrineColumn($table, $column)->getLength(),
        ];
    }

    /**
     * Get all indexes for a specific table.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return array Array of index information
     */
    public static function getTableIndexes(string $table, string $connection = null): array
    {
        try {
            $connection = Schema::getConnection($connection);
            $doctrineSchemaManager = $connection->getDoctrineSchemaManager();
            $indexes = $doctrineSchemaManager->listTableIndexes($table);

            $formattedIndexes = [];
            foreach ($indexes as $name => $index) {
                $formattedIndexes[$name] = [
                    'name' => $name,
                    'columns' => $index->getColumns(),
                    'unique' => $index->isUnique(),
                    'primary' => $index->isPrimary(),
                ];
            }

            return $formattedIndexes;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get foreign keys for a specific table.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return array Array of foreign key constraints
     */
    public static function getForeignKeys(string $table, string $connection = null): array
    {
        try {
            $connection = Schema::getConnection($connection);
            $doctrineSchemaManager = $connection->getDoctrineSchemaManager();
            $foreignKeys = $doctrineSchemaManager->listTableForeignKeys($table);

            $formattedForeignKeys = [];
            foreach ($foreignKeys as $name => $foreignKey) {
                $formattedForeignKeys[] = [
                    'name' => $foreignKey->getName(),
                    'local_columns' => $foreignKey->getLocalColumns(),
                    'foreign_table' => $foreignKey->getForeignTableName(),
                    'foreign_columns' => $foreignKey->getForeignColumns(),
                    'on_delete' => $foreignKey->getOption('onDelete'),
                    'on_update' => $foreignKey->getOption('onUpdate'),
                ];
            }

            return $formattedForeignKeys;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the primary key for a specific table.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return array|string|null Primary key column(s) or null if none found
     */
    public static function getPrimaryKey(string $table, string $connection = null)
    {
        $indexes = self::getTableIndexes($table, $connection);

        foreach ($indexes as $index) {
            if ($index['primary']) {
                return count($index['columns']) === 1 ? $index['columns'][0] : $index['columns'];
            }
        }

        return null;
    }

    /**
     * Check if a table has timestamp columns (created_at, updated_at).
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return bool True if the table has both timestamp columns
     */
    public static function hasTimestamps(string $table, string $connection = null): bool
    {
        return self::hasColumn($table, 'created_at', $connection) &&
            self::hasColumn($table, 'updated_at', $connection);
    }

    /**
     * Map database column type to PHP type.
     *
     * @param string $columnType Database column type
     * @return string Corresponding PHP type
     */
    public static function columnTypeToPhpType(string $columnType): string
    {
        $typeMap = [
            'bigint' => 'int',
            'int' => 'int',
            'integer' => 'int',
            'mediumint' => 'int',
            'smallint' => 'int',
            'tinyint' => 'bool',
            'boolean' => 'bool',
            'decimal' => 'float',
            'double' => 'float',
            'float' => 'float',
            'char' => 'string',
            'varchar' => 'string',
            'text' => 'string',
            'mediumtext' => 'string',
            'longtext' => 'string',
            'json' => 'array',
            'jsonb' => 'array',
            'binary' => 'resource',
            'blob' => 'resource',
            'mediumblob' => 'resource',
            'longblob' => 'resource',
            'date' => '\\Carbon\\Carbon',
            'datetime' => '\\Carbon\\Carbon',
            'timestamp' => '\\Carbon\\Carbon',
            'time' => '\\Carbon\\Carbon',
            'year' => 'int',
            'enum' => 'string',
            'set' => 'array',
            'geometry' => 'string',
            'point' => 'string',
            'linestring' => 'string',
            'polygon' => 'string',
            'uuid' => 'string',
        ];

        return $typeMap[$columnType] ?? 'mixed';
    }

    /**
     * Map database column type to HTML form input type.
     *
     * @param string $columnType Database column type
     * @return string HTML form input type
     */
    public static function columnTypeToFormType(string $columnType): string
    {
        $formTypeMap = [
            'bigint' => 'number',
            'int' => 'number',
            'integer' => 'number',
            'mediumint' => 'number',
            'smallint' => 'number',
            'tinyint' => 'checkbox',
            'boolean' => 'checkbox',
            'decimal' => 'number',
            'double' => 'number',
            'float' => 'number',
            'char' => 'text',
            'varchar' => 'text',
            'text' => 'textarea',
            'mediumtext' => 'textarea',
            'longtext' => 'textarea',
            'json' => 'textarea',
            'jsonb' => 'textarea',
            'binary' => 'file',
            'blob' => 'file',
            'mediumblob' => 'file',
            'longblob' => 'file',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'timestamp' => 'datetime-local',
            'time' => 'time',
            'year' => 'number',
            'enum' => 'select',
            'set' => 'select-multiple',
            'geometry' => 'textarea',
            'point' => 'text',
            'linestring' => 'textarea',
            'polygon' => 'textarea',
            'uuid' => 'text',
        ];

        return $formTypeMap[$columnType] ?? 'text';
    }

    /**
     * Check if a table has a specific column.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param string|null $connection The database connection name
     * @return bool True if the column exists in the table
     */
    public static function hasColumn(string $table, string $column, string $connection = null): bool
    {
        return Schema::connection($connection)->hasColumn($table, $column);
    }

    /**
     * Get columns with unique constraints.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return array Array of column names with unique constraints
     */
    public static function getUniqueColumns(string $table, string $connection = null): array
    {
        $indexes = self::getTableIndexes($table, $connection);
        $uniqueColumns = [];

        foreach ($indexes as $index) {
            if ($index['unique'] && !$index['primary']) {
                foreach ($index['columns'] as $column) {
                    $uniqueColumns[] = $column;
                }
            }
        }

        return array_unique($uniqueColumns);
    }

    /**
     * Get possible values for an enum column.
     *
     * @param string $table The name of the table
     * @param string $column The name of the enum column
     * @param string|null $connection The database connection name
     * @return array Array of possible enum values
     */
    public static function getEnumValues(string $table, string $column, string $connection = null): array
    {
        try {
            $connection = DB::connection($connection);
            $dbName = $connection->getDatabaseName();

            // This varies by database driver, this example works for MySQL/MariaDB
            if ($connection->getDriverName() === 'mysql') {
                $result = $connection->select("
                    SELECT COLUMN_TYPE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND DATA_TYPE = 'enum'
                ", [$dbName, $table, $column]);

                if (count($result) > 0) {
                    $enumDefinition = $result[0]->COLUMN_TYPE;
                    preg_match_all("/'(.*?)'/", $enumDefinition, $matches);
                    return $matches[1] ?? [];
                }
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all tables in the current database.
     *
     * @param string|null $connection The database connection name
     * @return array List of all table names
     */
    public static function getAllTables(string $connection = null): array
    {
        return Schema::connection($connection)->getAllTables();
    }

    /**
     * Check if a table exists in the database.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return bool True if the table exists
     */
    public static function tableExists(string $table, string $connection = null): bool
    {
        return Schema::connection($connection)->hasTable($table);
    }

    /**
     * Check if a table has a soft delete column.
     *
     * @param string $table The name of the table
     * @param string $softDeleteField The name of the soft delete field (default: deleted_at)
     * @param string|null $connection The database connection name
     * @return bool True if the table has the soft delete column
     */
    public static function hasSoftDelete(string $table, string $softDeleteField = 'deleted_at', string $connection = null): bool
    {
        return self::hasColumn($table, $softDeleteField, $connection);
    }

    /**
     * Check if column is auto-increment.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param string|null $connection The database connection name
     * @return bool True if the column is auto-increment
     */
    public static function isAutoIncrement(string $table, string $column, string $connection = null): bool
    {
        try {
            $doctrineColumn = Schema::getConnection($connection)
                ->getDoctrineColumn($table, $column);

            return $doctrineColumn->getAutoincrement();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the schema manager for the current connection.
     *
     * @param string|null $connection The database connection name
     * @return AbstractSchemaManager The schema manager instance
     */
    public static function getSchemaManager(string $connection = null): AbstractSchemaManager
    {
        return Schema::getConnection($connection)->getDoctrineSchemaManager();
    }
}
