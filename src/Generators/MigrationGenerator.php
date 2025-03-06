<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * MigrationGenerator
 *
 * This class is responsible for generating database migration files
 * based on table definitions. It can create migrations for new tables,
 * alter existing tables, or create table dropping migrations.
 *
 * @package SwatTech\Crud\Generators
 */
class MigrationGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;
    
    /**
     * The database analyzer instance.
     *
     * @var DatabaseAnalyzer
     */
    protected $databaseAnalyzer;
    
    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];
    
    /**
     * Migration configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new MigrationGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param DatabaseAnalyzer $databaseAnalyzer
     */
    public function __construct(StringHelper $stringHelper, DatabaseAnalyzer $databaseAnalyzer)
    {
        $this->stringHelper = $stringHelper;
        $this->databaseAnalyzer = $databaseAnalyzer;
        
        // Load default configuration options
        $this->options = Config::get('crud.migrations', []);
    }
    
    /**
     * Generate migration files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for migration generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);
        
        // Reset generated files
        $this->generatedFiles = [];
        
        // Get action (create, alter, or drop)
        $action = $this->options['action'] ?? 'create';
        
        // Get table schema from analyzer if table exists
        $tableSchema = $this->databaseAnalyzer->analyze($table)->getResults();
        
        // Build the migration content
        $className = $this->getClassName($table, $action);
        $migrationContent = $this->buildClass($table, $action, $tableSchema);
        
        // Generate migration filename with timestamp
        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . Str::snake($className) . '.php';
        
        // Generate the file path
        $filePath = $this->getPath() . '/' . $filename;
        
        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Write the file
        file_put_contents($filePath, $migrationContent);
        
        $this->generatedFiles[] = $filePath;
        
        return $this->generatedFiles;
    }
    
    /**
     * Get the class name for the migration.
     *
     * @param string $table The database table name
     * @param string $action The migration action (create, alter, drop)
     * @return string The migration class name
     */
    public function getClassName(string $table, string $action = 'create'): string
    {
        $tableName = Str::studly($table);
        
        switch ($action) {
            case 'create':
                return "Create{$tableName}Table";
            case 'alter':
                return "Alter{$tableName}Table";
            case 'drop':
                return "Drop{$tableName}Table";
            default:
                return "Create{$tableName}Table";
        }
    }
    
    /**
     * Get the namespace for the migration.
     *
     * @return string The migration namespace
     */
    public function getNamespace(): string
    {
        // Migrations don't typically have namespaces
        return '';
    }
    
    /**
     * Get the file path for the migration.
     *
     * @return string The migration file path
     */
    public function getPath(): string
    {
        return database_path($this->options['path'] ?? 'migrations');
    }
    
    /**
     * Get the stub template content for migration generation.
     *
     * @param string $action The migration action (create, alter, drop)
     * @return string The stub template content
     */
    public function getStub(string $action = 'create'): string
    {
        $customStubPath = resource_path("stubs/crud/migration_{$action}.stub");
        
        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }
        
        return file_get_contents(__DIR__ . "/../stubs/migration_{$action}.stub");
    }
    
    /**
     * Build the migration class based on the table schema and action.
     *
     * @param string $table The database table name
     * @param string $action The migration action (create, alter, drop)
     * @param array $schema The table schema information
     * @return string The generated migration content
     */
    public function buildClass(string $table, string $action, array $schema): string
    {
        $className = $this->getClassName($table, $action);
        $stub = $this->getStub($action);
        
        $columns = $schema['columns'] ?? [];
        $relationships = $schema['relationships'] ?? [];
        
        // Generate schema up and down methods based on action
        $schemaUp = $this->generateSchemaUp($table, $columns, $relationships, $action);
        $schemaDown = $this->generateSchemaDown($table, $action);
        
        // Replace stub placeholders
        $migrationContent = str_replace([
            '{{class}}',
            '{{table}}',
            '{{schemaUp}}',
            '{{schemaDown}}'
        ], [
            $className,
            $table,
            $schemaUp,
            $schemaDown
        ], $stub);
        
        return $migrationContent;
    }
    
    /**
     * Generate the schema up method content.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @param string $action The migration action
     * @return string The up method content
     */
    public function generateSchemaUp(string $table, array $columns, array $relationships, string $action): string
    {
        switch ($action) {
            case 'create':
                return $this->implementTableCreation($table, $columns, $relationships);
            case 'alter':
                return $this->implementTableAlteration($table, $columns, $relationships);
            case 'drop':
                return "Schema::dropIfExists('{$table}');";
            default:
                return $this->implementTableCreation($table, $columns, $relationships);
        }
    }
    
    /**
     * Generate the schema down method content.
     *
     * @param string $table The database table name
     * @param string $action The migration action
     * @return string The down method content
     */
    public function generateSchemaDown(string $table, string $action): string
    {
        switch ($action) {
            case 'create':
                return "Schema::dropIfExists('{$table}');";
            case 'alter':
                return $this->generateTableReversion($table);
            case 'drop':
                return $this->implementTableCreation($table, [], []);
            default:
                return "Schema::dropIfExists('{$table}');";
        }
    }
    
    /**
     * Generate column definitions for schema builder.
     *
     * @param array $columns The table columns
     * @return string The column definitions
     */
    public function generateColumnDefinitions(array $columns): string
    {
        $columnDefinitions = [];
        
        foreach ($columns as $name => $column) {
            $type = $column['type'] ?? 'string';
            $modifiers = [];
            
            // Generate the base column definition
            $definition = "\$table->{$type}('{$name}')";
            
            // Add length or precision if specified
            if (isset($column['length']) && in_array($type, ['string', 'char'])) {
                $definition = "\$table->{$type}('{$name}', {$column['length']})";
            } elseif (isset($column['precision']) && in_array($type, ['decimal', 'float', 'double'])) {
                $scale = $column['scale'] ?? 0;
                $definition = "\$table->{$type}('{$name}', {$column['precision']}, {$scale})";
            }
            
            // Add column modifiers
            if (isset($column['nullable']) && $column['nullable']) {
                $modifiers[] = 'nullable()';
            }
            
            if (isset($column['default'])) {
                $defaultValue = $column['default'];
                if (is_string($defaultValue) && !in_array($defaultValue, ['CURRENT_TIMESTAMP'])) {
                    $defaultValue = "'{$defaultValue}'";
                }
                $modifiers[] = "default({$defaultValue})";
            }
            
            if (isset($column['unsigned']) && $column['unsigned']) {
                $modifiers[] = 'unsigned()';
            }
            
            if (isset($column['comment'])) {
                $comment = addslashes($column['comment']);
                $modifiers[] = "comment('{$comment}')";
            }
            
            if (isset($column['autoIncrement']) && $column['autoIncrement']) {
                $modifiers[] = 'autoIncrement()';
            }
            
            // Add all modifiers to the definition
            if (!empty($modifiers)) {
                $definition .= '->' . implode('->', $modifiers);
            }
            
            $columnDefinitions[] = $definition . ';';
        }
        
        return implode("\n            ", $columnDefinitions);
    }
    
    /**
     * Set up indexes and foreign keys for the table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @return string The indexes and foreign keys definitions
     */
    public function setupIndexesAndForeignKeys(string $table, array $columns, array $relationships): string
    {
        $definitions = [];
        
        // Add primary key if specified
        if (isset($columns['primary_key']) && $columns['primary_key'] !== 'id') {
            $primaryKey = $columns['primary_key'];
            $definitions[] = "\$table->primary('{$primaryKey}');";
        }
        
        // Add indexes
        foreach ($columns as $name => $column) {
            if (isset($column['index']) && $column['index']) {
                $definitions[] = "\$table->index('{$name}');";
            }
            
            if (isset($column['unique']) && $column['unique']) {
                $definitions[] = "\$table->unique('{$name}');";
            }
        }
        
        // Add foreign keys based on relationships
        if (!empty($relationships)) {
            foreach ($relationships as $relationship) {
                if ($relationship['type'] === 'belongsTo') {
                    $foreignTable = $relationship['related_table'];
                    $foreignKey = $relationship['foreign_key'];
                    $localKey = $relationship['local_key'] ?? 'id';
                    
                    $onDelete = $relationship['on_delete'] ?? 'cascade';
                    $onUpdate = $relationship['on_update'] ?? 'cascade';
                    
                    $definitions[] = "\$table->foreign('{$foreignKey}')
                ->references('{$localKey}')
                ->on('{$foreignTable}')
                ->onDelete('{$onDelete}')
                ->onUpdate('{$onUpdate}');";
                }
            }
        }
        
        return !empty($definitions) ? "\n\n            " . implode("\n            ", $definitions) : '';
    }
    
    /**
     * Implement table creation schema.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @return string The table creation schema
     */
    public function implementTableCreation(string $table, array $columns, array $relationships): string
    {
        // Add timestamps if enabled
        $timestamps = $this->setupTimestampColumns($this->options);
        
        // Add soft deletes if enabled
        $softDeletes = $this->setupSoftDeleteColumn($this->options);
        
        // Add polymorphic fields if needed
        $polymorphic = $this->setupPolymorphicFields($relationships);
        
        // Generate column definitions
        $columnDefinitions = $this->generateColumnDefinitions($columns);
        
        // Add indexes and foreign keys
        $indexesAndForeignKeys = $this->setupIndexesAndForeignKeys($table, $columns, $relationships);
        
        // Combine all schema elements
        $schema = "Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            {$columnDefinitions}
            {$polymorphic}
            {$timestamps}
            {$softDeletes}
            {$indexesAndForeignKeys}
        });";
        
        // Add data seeding if enabled
        $seeding = $this->setupDataSeeding($this->options);
        if (!empty($seeding)) {
            $schema .= "\n\n        {$seeding}";
        }
        
        return $schema;
    }
    
    /**
     * Implement table alteration schema.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $relationships The table relationships
     * @return string The table alteration schema
     */
    public function implementTableAlteration(string $table, array $columns, array $relationships): string
    {
        $alterations = [];
        
        // Process column changes/additions
        foreach ($columns as $name => $column) {
            $action = $column['action'] ?? 'add';
            
            switch ($action) {
                case 'add':
                    $type = $column['type'] ?? 'string';
                    $alterations[] = "\$table->{$type}('{$name}');";
                    break;
                case 'change':
                    $type = $column['type'] ?? 'string';
                    $alterations[] = "\$table->{$type}('{$name}')->change();";
                    break;
                case 'rename':
                    $oldName = $column['old_name'];
                    $alterations[] = "\$table->renameColumn('{$oldName}', '{$name}');";
                    break;
                case 'drop':
                    $alterations[] = "\$table->dropColumn('{$name}');";
                    break;
            }
        }
        
        // Add foreign key changes
        if (!empty($relationships)) {
            foreach ($relationships as $relationship) {
                if ($relationship['action'] ?? 'add' === 'add' && $relationship['type'] === 'belongsTo') {
                    $foreignTable = $relationship['related_table'];
                    $foreignKey = $relationship['foreign_key'];
                    $localKey = $relationship['local_key'] ?? 'id';
                    
                    $onDelete = $relationship['on_delete'] ?? 'cascade';
                    $onUpdate = $relationship['on_update'] ?? 'cascade';
                    
                    $alterations[] = "\$table->foreign('{$foreignKey}')
                ->references('{$localKey}')
                ->on('{$foreignTable}')
                ->onDelete('{$onDelete}')
                ->onUpdate('{$onUpdate}');";
                } elseif (($relationship['action'] ?? 'add') === 'drop' && $relationship['type'] === 'belongsTo') {
                    $foreignKey = $relationship['foreign_key'];
                    $alterations[] = "\$table->dropForeign(['{$foreignKey}']);";
                }
            }
        }
        
        // Generate final schema
        $schema = "Schema::table('{$table}', function (Blueprint \$table) {
            " . implode("\n            ", $alterations) . "
        });";
        
        return $schema;
    }
    
    /**
     * Generate table reversion for down method in alter migrations.
     *
     * @param string $table The database table name
     * @return string The table reversion schema
     */
    protected function generateTableReversion(string $table): string
    {
        // This would ideally revert the changes made in the up method
        // For simplicity, we'll return a comment explaining what should be done
        return "// Revert the changes made to '{$table}' table
        // For each column/index added in the up() method, you should drop it here
        // For each column/index dropped in the up() method, you should recreate it here
        // For example:
        // Schema::table('{$table}', function (Blueprint \$table) {
        //     \$table->dropColumn('new_column_name');
        //     \$table->string('old_column_name');
        // });";
    }
    
    /**
     * Set up soft delete column for the table.
     *
     * @param array $options Options for migration generation
     * @return string The soft delete column definition
     */
    public function setupSoftDeleteColumn(array $options): string
    {
        if (isset($options['soft_deletes']) && $options['soft_deletes'] === true) {
            return '$table->softDeletes();';
        }
        
        return '';
    }
    
    /**
     * Set up timestamp columns for the table.
     *
     * @param array $options Options for migration generation
     * @return string The timestamp columns definition
     */
    public function setupTimestampColumns(array $options): string
    {
        if (!isset($options['timestamps']) || $options['timestamps'] !== false) {
            return '$table->timestamps();';
        }
        
        return '';
    }
    
    /**
     * Set up polymorphic fields for relationships.
     *
     * @param array $relationships The table relationships
     * @return string The polymorphic field definitions
     */
    public function setupPolymorphicFields(array $relationships): string
    {
        $polymorphicFields = [];
        
        foreach ($relationships as $relationship) {
            if (in_array($relationship['type'], ['morphTo'])) {
                $morphName = $relationship['morph_name'];
                $polymorphicFields[] = "\$table->morphs('{$morphName}');";
            } elseif (in_array($relationship['type'], ['morphOne', 'morphMany'])) {
                $morphName = $relationship['morph_name'];
                if (isset($relationship['add_to_migration']) && $relationship['add_to_migration']) {
                    $polymorphicFields[] = "\$table->morphs('{$morphName}');";
                }
            }
        }
        
        return !empty($polymorphicFields) ? implode("\n            ", $polymorphicFields) : '';
    }
    
    /**
     * Implement unique constraints for columns.
     *
     * @param array $columns The table columns
     * @return string The unique constraint definitions
     */
    public function implementUniqueConstraints(array $columns): string
    {
        $constraints = [];
        $uniqueGroups = [];
        
        // First pass - identify columns with shared unique group names
        foreach ($columns as $name => $column) {
            if (isset($column['unique_group'])) {
                $groupName = $column['unique_group'];
                if (!isset($uniqueGroups[$groupName])) {
                    $uniqueGroups[$groupName] = [];
                }
                $uniqueGroups[$groupName][] = $name;
            }
        }
        
        // Second pass - add composite unique constraints
        foreach ($uniqueGroups as $groupName => $columnNames) {
            if (count($columnNames) > 1) {
                $columnsStr = "['" . implode("', '", $columnNames) . "']";
                $constraints[] = "\$table->unique({$columnsStr}, '{$groupName}_unique');";
            }
        }
        
        return !empty($constraints) ? implode("\n            ", $constraints) : '';
    }
    
    /**
     * Set up data seeding in migration if enabled.
     *
     * @param array $options Options for migration generation
     * @return string The data seeding code
     */
    public function setupDataSeeding(array $options): string
    {
        if (isset($options['seed_data']) && is_array($options['seed_data']) && !empty($options['seed_data'])) {
            $tableName = $options['table'] ?? null;
            if (!$tableName) {
                return '';
            }
            
            $seedData = var_export($options['seed_data'], true);
            
            return "// Seed initial data
        DB::table('{$tableName}')->insert({$seedData});";
        }
        
        return '';
    }
    
    /**
     * Set configuration options for the generator.
     *
     * @param array $options Configuration options
     * @return self Returns the generator instance for method chaining
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
    
    /**
     * Get a list of all generated file paths.
     *
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }
    
    /**
     * Determine if the generator supports customization.
     *
     * @return bool True if the generator supports customization
     */
    public function supportsCustomization(): bool
    {
        return true;
    }
}