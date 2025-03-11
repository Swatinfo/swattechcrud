<?php

namespace SwatTech\Crud\Helpers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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
     * Get all indexes for a specific table.
     *
     * @param string $table The name of the table
     * @param string|null $connection The database connection name
     * @return array Array of index information
     */
    public static function getTableIndexes(string $table, string $connection = null): array
    {
        try {
            $connection = DB::connection($connection);
            $driverName = $connection->getDriverName();
            $formattedIndexes = [];

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
                    $indexes = $connection->select("SHOW INDEXES FROM `$table`");
                    $indexGroups = [];

                    foreach ($indexes as $index) {
                        $name = $index->Key_name;
                        if (!isset($indexGroups[$name])) {
                            $indexGroups[$name] = [
                                'name' => $name,
                                'columns' => [],
                                'unique' => $index->Non_unique == 0,
                                'primary' => $name === 'PRIMARY',
                            ];
                        }
                        $indexGroups[$name]['columns'][] = $index->Column_name;
                    }

                    $formattedIndexes = $indexGroups;
                    break;

                case 'pgsql':
                    $dbName = $connection->getDatabaseName();
                    $indexes = $connection->select("
                        SELECT
                            i.relname as index_name,
                            a.attname as column_name,
                            ix.indisunique as is_unique,
                            ix.indisprimary as is_primary
                        FROM
                            pg_class t,
                            pg_class i,
                            pg_index ix,
                            pg_attribute a
                        WHERE
                            t.oid = ix.indrelid
                            AND i.oid = ix.indexrelid
                            AND a.attrelid = t.oid
                            AND a.attnum = ANY(ix.indkey)
                            AND t.relkind = 'r'
                            AND t.relname = ?
                    ", [$table]);

                    $indexGroups = [];
                    foreach ($indexes as $index) {
                        $name = $index->index_name;
                        if (!isset($indexGroups[$name])) {
                            $indexGroups[$name] = [
                                'name' => $name,
                                'columns' => [],
                                'unique' => (bool)$index->is_unique,
                                'primary' => (bool)$index->is_primary,
                            ];
                        }
                        $indexGroups[$name]['columns'][] = $index->column_name;
                    }

                    $formattedIndexes = $indexGroups;
                    break;

                case 'sqlite':
                    $indexes = $connection->select("PRAGMA index_list(`$table`)");
                    foreach ($indexes as $index) {
                        $indexColumns = $connection->select("PRAGMA index_info('{$index->name}')");
                        $columns = array_map(function ($col) {
                            return $col->name;
                        }, $indexColumns);

                        $formattedIndexes[$index->name] = [
                            'name' => $index->name,
                            'columns' => $columns,
                            'unique' => (bool)$index->unique,
                            'primary' => $index->name === 'sqlite_autoindex_' . $table . '_1', // This is an approximation
                        ];
                    }
                    break;

                case 'sqlsrv':
                    $indexes = $connection->select("
                        SELECT 
                            i.name AS index_name,
                            COL_NAME(ic.object_id, ic.column_id) AS column_name,
                            i.is_unique,
                            i.is_primary_key
                        FROM 
                            sys.indexes AS i
                        INNER JOIN 
                            sys.index_columns AS ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                        INNER JOIN 
                            sys.objects AS o ON i.object_id = o.object_id
                        WHERE 
                            o.name = ?
                    ", [$table]);

                    $indexGroups = [];
                    foreach ($indexes as $index) {
                        $name = $index->index_name;
                        if (!isset($indexGroups[$name])) {
                            $indexGroups[$name] = [
                                'name' => $name,
                                'columns' => [],
                                'unique' => (bool)$index->is_unique,
                                'primary' => (bool)$index->is_primary_key,
                            ];
                        }
                        $indexGroups[$name]['columns'][] = $index->column_name;
                    }

                    $formattedIndexes = $indexGroups;
                    break;
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
            $connection = DB::connection($connection);
            $driverName = $connection->getDriverName();
            $formattedForeignKeys = [];

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
                    $dbName = $connection->getDatabaseName();
                    $foreignKeys = $connection->select("
                        SELECT
                            CONSTRAINT_NAME as name,
                            COLUMN_NAME as column_name,
                            REFERENCED_TABLE_NAME as foreign_table,
                            REFERENCED_COLUMN_NAME as foreign_column,
                            UPDATE_RULE as on_update,
                            DELETE_RULE as on_delete
                        FROM
                            INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
                            ON INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS.CONSTRAINT_NAME = INFORMATION_SCHEMA.KEY_COLUMN_USAGE.CONSTRAINT_NAME
                        WHERE
                            INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_SCHEMA = ?
                            AND INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME = ?
                            AND INFORMATION_SCHEMA.KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME IS NOT NULL
                    ", [$dbName, $table]);

                    $fkGroups = [];
                    foreach ($foreignKeys as $fk) {
                        if (!isset($fkGroups[$fk->name])) {
                            $fkGroups[$fk->name] = [
                                'name' => $fk->name,
                                'local_columns' => [],
                                'foreign_table' => $fk->foreign_table,
                                'foreign_columns' => [],
                                'on_delete' => $fk->on_delete,
                                'on_update' => $fk->on_update,
                            ];
                        }
                        $fkGroups[$fk->name]['local_columns'][] = $fk->column_name;
                        $fkGroups[$fk->name]['foreign_columns'][] = $fk->foreign_column;
                    }

                    $formattedForeignKeys = array_values($fkGroups);
                    break;

                case 'pgsql':
                    $foreignKeys = $connection->select("
                        SELECT
                            tc.constraint_name as name,
                            kcu.column_name as column_name,
                            ccu.table_name AS foreign_table,
                            ccu.column_name AS foreign_column,
                            rc.update_rule as on_update,
                            rc.delete_rule as on_delete
                        FROM
                            information_schema.table_constraints AS tc
                        JOIN information_schema.key_column_usage AS kcu
                            ON tc.constraint_name = kcu.constraint_name
                        JOIN information_schema.constraint_column_usage AS ccu
                            ON ccu.constraint_name = tc.constraint_name
                        JOIN information_schema.referential_constraints AS rc
                            ON rc.constraint_name = tc.constraint_name
                        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?
                    ", [$table]);

                    $fkGroups = [];
                    foreach ($foreignKeys as $fk) {
                        if (!isset($fkGroups[$fk->name])) {
                            $fkGroups[$fk->name] = [
                                'name' => $fk->name,
                                'local_columns' => [],
                                'foreign_table' => $fk->foreign_table,
                                'foreign_columns' => [],
                                'on_delete' => $fk->on_delete,
                                'on_update' => $fk->on_update,
                            ];
                        }
                        $fkGroups[$fk->name]['local_columns'][] = $fk->column_name;
                        $fkGroups[$fk->name]['foreign_columns'][] = $fk->foreign_column;
                    }

                    $formattedForeignKeys = array_values($fkGroups);
                    break;

                case 'sqlite':
                    $foreignKeys = $connection->select("PRAGMA foreign_key_list(`$table`)");
                    $fkGroups = [];

                    foreach ($foreignKeys as $fk) {
                        $name = "fk_{$table}_{$fk->id}";
                        if (!isset($fkGroups[$name])) {
                            $fkGroups[$name] = [
                                'name' => $name,
                                'local_columns' => [],
                                'foreign_table' => $fk->table,
                                'foreign_columns' => [],
                                'on_delete' => $fk->on_delete,
                                'on_update' => $fk->on_update,
                            ];
                        }
                        $fkGroups[$name]['local_columns'][] = $fk->from;
                        $fkGroups[$name]['foreign_columns'][] = $fk->to;
                    }

                    $formattedForeignKeys = array_values($fkGroups);
                    break;

                case 'sqlsrv':
                    $foreignKeys = $connection->select("
                        SELECT
                            f.name AS constraint_name,
                            COL_NAME(fc.parent_object_id, fc.parent_column_id) AS column_name,
                            OBJECT_NAME(f.referenced_object_id) AS foreign_table,
                            COL_NAME(fc.referenced_object_id, fc.referenced_column_id) AS foreign_column,
                            update_referential_action_desc AS on_update,
                            delete_referential_action_desc AS on_delete
                        FROM
                            sys.foreign_keys AS f
                        INNER JOIN
                            sys.foreign_key_columns AS fc ON f.object_id = fc.constraint_object_id
                        INNER JOIN
                            sys.objects AS o ON f.parent_object_id = o.object_id
                        WHERE
                            o.name = ?
                    ", [$table]);

                    $fkGroups = [];
                    foreach ($foreignKeys as $fk) {
                        $name = $fk->constraint_name;
                        if (!isset($fkGroups[$name])) {
                            $fkGroups[$name] = [
                                'name' => $name,
                                'local_columns' => [],
                                'foreign_table' => $fk->foreign_table,
                                'foreign_columns' => [],
                                'on_delete' => $fk->on_delete,
                                'on_update' => $fk->on_update,
                            ];
                        }
                        $fkGroups[$name]['local_columns'][] = $fk->column_name;
                        $fkGroups[$name]['foreign_columns'][] = $fk->foreign_column;
                    }

                    $formattedForeignKeys = array_values($fkGroups);
                    break;
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
            $driverName = $connection->getDriverName();
            $dbName = $connection->getDatabaseName();

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
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
                    break;

                case 'pgsql':
                    // PostgreSQL uses CHECK constraints for enum-like behavior
                    $result = $connection->select("
                        SELECT pg_get_constraintdef(c.oid) as check_def
                        FROM pg_constraint c
                        JOIN pg_namespace n ON n.oid = c.connamespace
                        JOIN pg_class t ON t.oid = c.conrelid
                        JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                        WHERE t.relname = ? AND a.attname = ? AND c.contype = 'c'
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        $checkDef = $result[0]->check_def;
                        // Extract values from CHECK constraint
                        preg_match_all("/'(.*?)'/", $checkDef, $matches);
                        return $matches[1] ?? [];
                    }
                    break;

                case 'sqlite':
                    // SQLite doesn't have native enum types
                    // We can try to find CHECK constraints
                    $tableInfo = $connection->select("PRAGMA table_info(`$table`)");
                    foreach ($tableInfo as $info) {
                        if ($info->name === $column && !empty($info->dflt_value)) {
                            preg_match_all("/'(.*?)'/", $info->dflt_value, $matches);
                            return $matches[1] ?? [];
                        }
                    }
                    break;

                case 'sqlsrv':
                    // SQL Server uses CHECK constraints for enum-like behavior
                    $result = $connection->select("
                        SELECT cc.definition
                        FROM sys.check_constraints cc
                        JOIN sys.objects o ON cc.parent_object_id = o.object_id
                        JOIN sys.columns c ON cc.parent_object_id = c.object_id
                        WHERE o.name = ? AND c.name = ?
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        $checkDef = $result[0]->definition;
                        preg_match_all("/'(.*?)'/", $checkDef, $matches);
                        return $matches[1] ?? [];
                    }
                    break;
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
        try {
            $connection = DB::connection($connection);
            $driverName = $connection->getDriverName();
            $dbName = $connection->getDatabaseName();

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
                    $tables = $connection->select("
                        SELECT table_name 
                        FROM information_schema.tables 
                        WHERE table_schema = ?
                    ", [$dbName]);
                    break;

                case 'pgsql':
                    $tables = $connection->select("
                        SELECT tablename as table_name
                        FROM pg_catalog.pg_tables
                        WHERE schemaname = 'public'
                    ");
                    break;

                case 'sqlite':
                    $tables = $connection->select("
                        SELECT name as table_name
                        FROM sqlite_master
                        WHERE type='table' AND name != 'sqlite_sequence'
                    ");
                    break;

                case 'sqlsrv':
                    $tables = $connection->select("
                        SELECT TABLE_NAME as table_name
                        FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_TYPE = 'BASE TABLE'
                    ");
                    break;

                default:
                    return Schema::connection($connection)->getTables();
            }

            return array_map(function ($table) {
                return $table->table_name;
            }, $tables);
        } catch (\Exception $e) {
            return [];
        }
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
            $connection = DB::connection($connection);
            $driverName = $connection->getDriverName();
            $dbName = $connection->getDatabaseName();

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
                    $result = $connection->select("
                        SELECT EXTRA
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                    ", [$dbName, $table, $column]);

                    if (count($result) > 0) {
                        return strpos($result[0]->EXTRA, 'auto_increment') !== false;
                    }
                    break;

                case 'pgsql':
                    $result = $connection->select("
                        SELECT pg_get_serial_sequence(?, ?) IS NOT NULL as is_serial
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        return (bool)$result[0]->is_serial;
                    }
                    break;

                case 'sqlite':
                    $result = $connection->select("PRAGMA table_info(`$table`)");
                    foreach ($result as $col) {
                        if ($col->name === $column) {
                            return (bool)$col->pk;
                        }
                    }
                    break;

                case 'sqlsrv':
                    $result = $connection->select("
                        SELECT COLUMNPROPERTY(OBJECT_ID(?), ?, 'IsIdentity') as is_identity
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        return (bool)$result[0]->is_identity;
                    }
                    break;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the schema manager functionality.
     *
     * @param string|null $connection The database connection name
     * @return Schema The schema builder instance
     */
    public static function getSchemaManager(string $connection = null)
    {
        return Schema::connection($connection);
    }

    /**
     * Get the current database name.
     *
     * @param string|null $connection The database connection name
     * @return string The database name
     */
    public static function getDatabaseName(string $connection = null): string
    {
        return DB::connection($connection)->getDatabaseName();
    }

    /**
     * Get the schema version information.
     *
     * @param string|null $connection The database connection name
     * @return string The database schema version
     */
    public static function getSchemaVersion(string $connection = null): string
    {
        $conn = DB::connection($connection);
        return $conn->select('SELECT version()')[0]->{'version()'} ?? '';
    }

    /**
     * Check if a column is nullable.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param string|null $connection The database connection name
     * @return bool True if the column is nullable
     */
    public static function isNullable(string $table, string $column, string $connection = null): bool
    {
        try {
            $connection = DB::connection($connection);
            $driverName = $connection->getDriverName();
            $dbName = $connection->getDatabaseName();

            switch ($driverName) {
                case 'mysql':
                case 'mariadb':
                    $result = $connection->select("
                        SELECT IS_NULLABLE
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                    ", [$dbName, $table, $column]);

                    if (count($result) > 0) {
                        return $result[0]->IS_NULLABLE === 'YES';
                    }
                    break;

                case 'pgsql':
                    $result = $connection->select("
                        SELECT is_nullable
                        FROM information_schema.columns
                        WHERE table_name = ? AND column_name = ?
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        return $result[0]->is_nullable === 'YES';
                    }
                    break;

                case 'sqlite':
                    $result = $connection->select("PRAGMA table_info(`$table`)");
                    foreach ($result as $col) {
                        if ($col->name === $column) {
                            return $col->notnull == 0;
                        }
                    }
                    break;

                case 'sqlsrv':
                    $result = $connection->select("
                        SELECT is_nullable
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_NAME = ? AND COLUMN_NAME = ?
                    ", [$table, $column]);

                    if (count($result) > 0) {
                        return $result[0]->is_nullable === 'YES';
                    }
                    break;
            }

            return false;
        } catch (\Exception $e) {
            // Fallback method using Laravel's Schema
            try {
                $columnInfo = DB::connection($connection)
                    ->select("SHOW COLUMNS FROM `$table` WHERE Field = ?", [$column]);

                if (count($columnInfo) > 0) {
                    return $columnInfo[0]->Null === 'YES';
                }

                return false;
            } catch (\Exception $ex) {
                return false;
            }
        }
    }

    /**
     * Check if a table column has a specific value in any row.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param mixed $value The value to check for
     * @param string|null $connection The database connection name
     * @return bool True if the value exists in the column
     */
    public static function tableHasValue(string $table, string $column, $value, string $connection = null): bool
    {
        try {
            $count = DB::connection($connection)
                ->table($table)
                ->where($column, $value)
                ->count();

            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if columns have a unique constraint.
     *
     * @param string $table The name of the table
     * @param array $columns Array of column names
     * @param string|null $connection The database connection name
     * @return bool True if the columns have a unique constraint
     */
    public static function hasUniqueConstraint(string $table, array $columns, string $connection = null): bool
    {
        $indexes = self::getTableIndexes($table, $connection);

        foreach ($indexes as $index) {
            if ($index['unique'] && !$index['primary']) {
                $indexColumns = $index['columns'];
                sort($indexColumns);
                sort($columns);

                if ($indexColumns === $columns) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get distinct values from a column in a table.
     *
     * @param string $table The name of the table
     * @param string $column The name of the column
     * @param string|null $connection The database connection name
     * @return array Array of distinct values
     */
    public static function getDistinctValues(string $table, string $column, string $connection = null): array
    {
        try {
            $results = DB::connection($connection)
                ->table($table)
                ->distinct()
                ->select($column)
                ->whereNotNull($column)
                ->get()
                ->pluck($column)
                ->toArray();

            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the database schema instance.
     *
     * @param string|null $connection The database connection name
     * @return \Illuminate\Database\Schema\Builder The schema builder instance
     */
    public static function getSchema(string $connection = null)
    {
        return Schema::connection($connection);
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
        $conn = DB::connection($connection);
        $driverName = $conn->getDriverName();

        // Initialize with basic details we can get from Laravel's Schema
        $details = [
            'name' => $column,
            'type' => $columnType,
            'php_type' => self::columnTypeToPhpType($columnType),
            'form_type' => self::columnTypeToFormType($columnType),
            'nullable' => false,
            'default' => null,
            'autoincrement' => false,
            'comment' => '',
            'length' => null,
        ];

        // Get more detailed information based on database type
        if ($driverName === 'mysql' || $driverName === 'mariadb') {
            $dbName = $conn->getDatabaseName();
            $columnInfo = $conn->select("
            SELECT 
                COLUMN_NAME, 
                COLUMN_DEFAULT, 
                IS_NULLABLE, 
                DATA_TYPE,
                CHARACTER_MAXIMUM_LENGTH,
                EXTRA,
                COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ", [$dbName, $table, $column]);

            if (!empty($columnInfo)) {
                $info = $columnInfo[0];
                $details['nullable'] = $info->IS_NULLABLE === 'YES';
                $details['default'] = $info->COLUMN_DEFAULT;
                $details['autoincrement'] = strpos($info->EXTRA ?? '', 'auto_increment') !== false;
                $details['comment'] = $info->COLUMN_COMMENT ?? '';
                $details['length'] = $info->CHARACTER_MAXIMUM_LENGTH;
            }
        } elseif ($driverName === 'pgsql') {
            // PostgreSQL specific query
            $columnInfo = $conn->select("
            SELECT 
                column_name,
                column_default,
                is_nullable,
                data_type,
                character_maximum_length,
                pg_catalog.col_description(
                    (table_schema || '.' || table_name)::regclass::oid, 
                    ordinal_position
                ) as column_comment
            FROM information_schema.columns
            WHERE table_name = ? AND column_name = ?
        ", [$table, $column]);

            if (!empty($columnInfo)) {
                $info = $columnInfo[0];
                $details['nullable'] = $info->is_nullable === 'YES';
                $details['default'] = $info->column_default;
                $details['autoincrement'] = strpos($info->column_default ?? '', 'nextval') !== false;
                $details['comment'] = $info->column_comment ?? '';
                $details['length'] = $info->character_maximum_length;
            }
        } elseif ($driverName === 'sqlite') {
            // SQLite specific approach
            $tableInfo = $conn->select("PRAGMA table_info($table)");
            foreach ($tableInfo as $info) {
                if ($info->name === $column) {
                    $details['nullable'] = $info->notnull == 0;
                    $details['default'] = $info->dflt_value;
                    $details['autoincrement'] = $info->pk == 1;
                    $details['comment'] = '';
                    break;
                }
            }
        } elseif ($driverName === 'sqlsrv') {
            // SQL Server specific query
            $columnInfo = $conn->select("
            SELECT 
                c.name as column_name,
                c.is_nullable,
                c.default_object_id,
                t.name as data_type,
                c.max_length,
                ep.value as column_comment,
                COLUMNPROPERTY(object_id(sc.name + '.' + so.name), c.name, 'IsIdentity') as is_identity
            FROM sys.columns c
            INNER JOIN sys.objects so ON c.object_id = so.object_id
            INNER JOIN sys.schemas sc ON so.schema_id = sc.schema_id
            LEFT JOIN sys.types t ON c.user_type_id = t.user_type_id
            LEFT JOIN sys.extended_properties ep ON ep.major_id = c.object_id AND ep.minor_id = c.column_id AND ep.name = 'MS_Description'
            WHERE so.name = ? AND c.name = ?
        ", [$table, $column]);

            if (!empty($columnInfo)) {
                $info = $columnInfo[0];
                $details['nullable'] = $info->is_nullable == 1;
                $details['default'] = null; // Need additional query for default
                $details['autoincrement'] = $info->is_identity == 1;
                $details['comment'] = $info->column_comment ?? '';
                $details['length'] = $info->max_length;
            }
        }

        // If the specific database query didn't get nullable status, fall back to Laravel's method
        if (!isset($details['nullable'])) {
            try {
                $details['nullable'] = self::isNullable($table, $column, $connection);
            } catch (\Exception $e) {
                // Keep the default false
            }
        }

        return $details;
    }
}
