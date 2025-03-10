<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * FactoryGenerator
 *
 * Generates model factory classes for database tables, providing test data generation
 * capabilities. This generator creates factory classes that use Faker to create
 * realistic test data based on column types and relationships.
 *
 * @package SwatTech\Crud\Generators
 */
class FactoryGenerator implements GeneratorInterface
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
     * Factory configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new FactoryGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param DatabaseAnalyzer $databaseAnalyzer
     */
    public function __construct(StringHelper $stringHelper, DatabaseAnalyzer $databaseAnalyzer)
    {
        $this->stringHelper = $stringHelper;
        $this->databaseAnalyzer = $databaseAnalyzer;

        // Load default configuration options
        $this->options = Config::get('crud.factories', []);
    }

    /**
     * Generate factory files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for factory generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Analyze the table to get columns and relationships
        $tableSchema = $this->databaseAnalyzer->analyze($table)->getResults();

        // Build the factory class
        $className = $this->getClassName($table);
        $factoryContent = $this->buildClass($table, $tableSchema);

        // Generate the file path
        $filePath = $this->getPath() . '/' . $className . '.php';

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $factoryContent);

        $this->generatedFiles[] = $filePath;

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the factory.
     *
     * @param string $table The database table name
     * @return string The factory class name
     */
    public function getClassName(string $table, string $action = ""): string
    {
        return Str::studly(Str::singular($table)) . 'Factory';
    }

    /**
     * Get the namespace for the factory.
     *
     * @return string The factory namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.factories', 'Database\\Factories');
    }

    /**
     * Get the file path for the factory.
     *
     * @return string The factory file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.factories', 'database/factories'));
    }

    /**
     * Get the stub template content for factory generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path('stubs/crud/factory.stub');

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . '/../stubs/factory.stub');
    }

    /**
     * Build the factory class based on the table schema.
     *
     * @param string $table The database table name
     * @param array $schema The table schema information
     * @return string The generated factory content
     */
    public function buildClass(string $table, array $schema): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub();

        // Generate fake data definitions for columns
        $fakerData = $this->generateFakerData($schema['columns'] ?? []);

        // Setup relationship handling
        $relationships = $this->setupRelationshipHandling($schema['relationships'] ?? []);

        // Generate state methods
        $states = $this->generateStateMethods($this->options['states'] ?? []);

        // Implement sequence generation
        $sequences = $this->implementSequenceGeneration();

        // Setup after creating hooks
        $afterCreating = $this->setupAfterCreatingHooks();

        // Generate complex attribute handling
        $complexAttributes = $this->generateComplexAttributes($this->options['complex_columns'] ?? []);

        // Create recycle methods
        $recycleMethods = $this->createRecycleMethods();

        // Implement count methods
        $countMethods = $this->implementCountMethods();

        // Generate traits
        $traits = $this->generateTraits($this->options['traits'] ?? []);

        // Replace stub placeholders
        $factoryContent = str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{fakerData}}',
            '{{relationships}}',
            '{{states}}',
            '{{sequences}}',
            '{{afterCreating}}',
            '{{complexAttributes}}',
            '{{recycleMethods}}',
            '{{countMethods}}',
            '{{traits}}'
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $fakerData,
            $relationships,
            $states,
            $sequences,
            $afterCreating,
            $complexAttributes,
            $recycleMethods,
            $countMethods,
            $traits
        ], $stub);

        return $factoryContent;
    }

    /**
     * Generate Faker data definitions for table columns.
     *
     * @param array $columns The table columns
     * @return string The Faker data definitions
     */
    public function generateFakerData(array $columns): string
    {
        $fakerStatements = [];

        foreach ($columns as $column => $details) {
            // Skip primary key, timestamps, and soft deletes columns
            if (
                $column === 'id' ||
                in_array($column, ['created_at', 'updated_at', 'deleted_at'])
            ) {
                continue;
            }

            $fakerMethod = $this->mapColumnTypeToFaker($column, $details['type'] ?? 'string');

            if ($fakerMethod) {
                $fakerStatements[] = "            '{$column}' => {$fakerMethod},";
            }
        }

        return implode("\n", $fakerStatements);
    }

    /**
     * Set up relationship handling in the factory.
     *
     * @param array $relationships The table relationships
     * @return string The relationship handling code
     */
    public function setupRelationshipHandling(array $relationships): string
    {
        $relationshipCode = '';

        if (empty($relationships)) {
            return $relationshipCode;
        }

        // Process belongsTo relationships to create foreign keys
        if (!empty($relationships['belongs_to'])) {
            foreach ($relationships['belongs_to'] as $relation) {
                $relatedModel = Str::studly(Str::singular($relation['related_table']));
                $foreignKey = $relation['foreign_key'];

                $relationshipCode .= "
        // Foreign key for {$relatedModel}
        if (\$this->faker->boolean(80)) {
            \$data['{$foreignKey}'] = \\{$this->getNamespace()}\\{$relatedModel}Factory::new()->create()->id;
        }";
            }
        }

        return $relationshipCode;
    }

    /**
     * Generate state methods for the factory.
     *
     * @param array $states The factory states to generate
     * @return string The state methods code
     */
    public function generateStateMethods(array $states): string
    {
        $stateMethods = '';

        foreach ($states as $state => $attributes) {
            $stateMethods .= "
    /**
     * Indicate that the model is in {$state} state.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function {$state}()
    {
        return \$this->state(function (array \$attributes) {
            return [";

            foreach ($attributes as $attribute => $value) {
                if (is_string($value)) {
                    $value = "'{$value}'";
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_null($value)) {
                    $value = 'null';
                }

                $stateMethods .= "
                '{$attribute}' => {$value},";
            }

            $stateMethods .= "
            ];
        });
    }
";
        }

        return $stateMethods;
    }

    /**
     * Implement sequence generation for the factory.
     *
     * @return string The sequence generation code
     */
    public function implementSequenceGeneration(): string
    {
        if (!isset($this->options['sequences']) || empty($this->options['sequences'])) {
            return '';
        }

        $sequences = '';

        foreach ($this->options['sequences'] as $attribute => $values) {
            $values = array_map(function ($value) {
                return is_string($value) ? "'{$value}'" : $value;
            }, $values);

            $valuesString = implode(', ', $values);

            $sequences .= "
    /**
     * Configure the model factory to cycle through a sequence of values for {$attribute}.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function sequence{$this->stringHelper->studlyCase($attribute)}()
    {
        return \$this->sequence(function (\$sequence) {
            \$values = [{$valuesString}];
            return [
                '{$attribute}' => \$values[\$sequence % count(\$values)],
            ];
        });
    }
";
        }

        return $sequences;
    }

    /**
     * Set up after creating hooks for the factory.
     *
     * @return string The after creating hooks code
     */
    public function setupAfterCreatingHooks(): string
    {
        if (!isset($this->options['after_creating']) || empty($this->options['after_creating'])) {
            return '';
        }

        $afterCreating = "
    /**
     * Configure the after creating hook.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function configure()
    {
        return \$this->afterCreating(function (\$model) {
            // After creating hook logic";

        foreach ($this->options['after_creating'] as $hook) {
            $afterCreating .= "
            {$hook}";
        }

        $afterCreating .= "
        });
    }";

        return $afterCreating;
    }

    /**
     * Generate complex attribute handling for the factory.
     *
     * @param array $complexColumns The complex columns to handle
     * @return string The complex attribute handling code
     */
    public function generateComplexAttributes(array $complexColumns): string
    {
        $complexAttributesCode = '';

        foreach ($complexColumns as $column => $handler) {
            $method = 'with' . Str::studly($column);

            $complexAttributesCode .= "
    /**
     * Set a custom {$column} value.
     *
     * @param mixed \$value The value to set
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function {$method}(\$value = null)
    {
        return \$this->state(function (array \$attributes) use (\$value) {
            return [
                '{$column}' => \$value ?: {$handler},
            ];
        });
    }
";
        }

        return $complexAttributesCode;
    }

    /**
     * Create recycle methods for the factory.
     *
     * @return string The recycle methods code
     */
    public function createRecycleMethods(): string
    {
        if (!isset($this->options['enable_recycling']) || $this->options['enable_recycling'] !== true) {
            return '';
        }

        return "
    /**
     * Recycle an existing model from the database if available.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function recycle()
    {
        return \$this->state(function (array \$attributes) {
            \$model = \$this->modelName()::inRandomOrder()->first();
            
            if (\$model) {
                return [
                    'id' => \$model->id,
                ];
            }
            
            return [];
        });
    }
";
    }

    /**
     * Implement count methods for the factory.
     *
     * @return string The count methods code
     */
    public function implementCountMethods(): string
    {
        if (!isset($this->options['count_methods']) || empty($this->options['count_methods'])) {
            return '';
        }

        $countMethods = '';

        foreach ($this->options['count_methods'] as $method => $count) {
            $countMethods .= "
    /**
     * Create {$count} model instances.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function {$method}()
    {
        return \$this->count({$count})->create();
    }
";
        }

        return $countMethods;
    }

    /**
     * Generate traits for the factory.
     *
     * @param array $traits The traits to include
     * @return string The traits code
     */
    public function generateTraits(array $traits): string
    {
        if (empty($traits)) {
            return '';
        }

        $traitsCode = "    /**
     * The traits to mix into the factory.
     *
     * @var array
     */
    protected \$traits = [";

        foreach ($traits as $trait) {
            $traitsCode .= "
        \\{$trait}::class,";
        }

        $traitsCode .= "
    ];";

        return $traitsCode;
    }

    /**
     * Map database column type to appropriate Faker method.
     *
     * @param string $column The column name
     * @param string $type The column type
     * @return string The Faker method call
     */
    protected function mapColumnTypeToFaker(string $column, string $type): string
    {
        // Check for common column names first
        $columnNameMap = [
            'name' => '$this->faker->name()',
            'first_name' => '$this->faker->firstName()',
            'last_name' => '$this->faker->lastName()',
            'email' => '$this->faker->unique()->safeEmail()',
            'phone' => '$this->faker->phoneNumber()',
            'address' => '$this->faker->address()',
            'city' => '$this->faker->city()',
            'state' => '$this->faker->state()',
            'country' => '$this->faker->country()',
            'zip' => '$this->faker->postcode()',
            'postcode' => '$this->faker->postcode()',
            'title' => '$this->faker->sentence(3)',
            'subtitle' => '$this->faker->sentence(5)',
            'summary' => '$this->faker->paragraph(1)',
            'description' => '$this->faker->paragraphs(3, true)',
            'content' => '$this->faker->paragraphs(5, true)',
            'slug' => '$this->faker->unique()->slug()',
            'url' => '$this->faker->url()',
            'image' => '$this->faker->imageUrl()',
            'avatar' => '$this->faker->imageUrl(150, 150)',
            'password' => 'bcrypt(\'password\')',
            'remember_token' => 'Str::random(10)',
            'is_active' => '$this->faker->boolean()',
            'status' => '$this->faker->randomElement([\'active\', \'inactive\', \'pending\'])',
            'ip_address' => '$this->faker->ipv4',
            'user_agent' => '$this->faker->userAgent()',
        ];

        if (isset($columnNameMap[$column])) {
            return $columnNameMap[$column];
        }

        // Then check by column type
        $typeMap = [
            'string' => '$this->faker->word()',
            'text' => '$this->faker->paragraph()',
            'longtext' => '$this->faker->paragraphs(3, true)',
            'char' => '$this->faker->randomLetter()',
            'int' => '$this->faker->numberBetween(1, 1000)',
            'integer' => '$this->faker->numberBetween(1, 1000)',
            'bigint' => '$this->faker->numberBetween(1, 10000)',
            'tinyint' => '$this->faker->boolean()',
            'boolean' => '$this->faker->boolean()',
            'decimal' => '$this->faker->randomFloat(2, 1, 1000)',
            'float' => '$this->faker->randomFloat(2, 1, 1000)',
            'double' => '$this->faker->randomFloat(2, 1, 1000)',
            'date' => '$this->faker->date()',
            'datetime' => '$this->faker->dateTime()',
            'timestamp' => '$this->faker->dateTime()',
            'time' => '$this->faker->time()',
            'year' => '$this->faker->numberBetween(2000, ' . date('Y') . ')',
            'enum' => '$this->faker->randomElement([\'value1\', \'value2\'])', // Default, will need to be customized
            'json' => 'json_encode([$this->faker->word() => $this->faker->word()])',
            'uuid' => '$this->faker->uuid()',
        ];

        // Check for specific column name patterns
        if (Str::endsWith($column, '_id') && $type === 'integer') {
            return '$this->faker->numberBetween(1, 100)';
        }

        if (Str::endsWith($column, '_at') && in_array($type, ['datetime', 'timestamp'])) {
            return '$this->faker->dateTime()';
        }

        if (Str::endsWith($column, '_date') && $type === 'date') {
            return '$this->faker->date()';
        }

        if (Str::contains($column, 'email')) {
            return '$this->faker->unique()->safeEmail()';
        }

        if (Str::contains($column, 'name')) {
            return '$this->faker->name()';
        }

        if (Str::contains($column, 'phone')) {
            return '$this->faker->phoneNumber()';
        }

        if (Str::contains($column, 'address')) {
            return '$this->faker->address()';
        }

        if (Str::contains($column, 'price')) {
            return '$this->faker->randomFloat(2, 10, 1000)';
        }

        return $typeMap[$type] ?? '$this->faker->word()';
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
