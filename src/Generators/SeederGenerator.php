<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * SeederGenerator
 *
 * This class generates database seeder files for Laravel applications.
 * It can create seeders that use factories, handle relationships, and
 * provide different data sets for development and production environments.
 *
 * @package SwatTech\Crud\Generators
 */
class SeederGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;
    
    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];
    
    /**
     * Seeder configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new SeederGenerator instance.
     *
     * @param StringHelper $stringHelper The string helper instance
     */
    public function __construct(StringHelper $stringHelper)
    {
        $this->stringHelper = $stringHelper;
        
        // Load default configuration options
        $this->options = Config::get('crud.seeders', []);
    }
    
    /**
     * Generate seeder files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for seeder generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);
        
        // Reset generated files
        $this->generatedFiles = [];
        
        // Build the seeder class
        $className = $this->getClassName($table);
        $seederContent = $this->buildClass($table, $this->options);
        
        // Generate the file path
        $filePath = $this->getPath() . '/' . $className . '.php';
        
        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Write the file
        file_put_contents($filePath, $seederContent);
        
        $this->generatedFiles[] = $filePath;
        
        // Generate a DatabaseSeeder if requested
        if (isset($this->options['generate_database_seeder']) && $this->options['generate_database_seeder']) {
            $this->generateDatabaseSeeder($table);
        }
        
        return $this->generatedFiles;
    }
    
    /**
     * Get the class name for the seeder.
     *
     * @param string $table The database table name
     * @return string The seeder class name
     */
    public function getClassName(string $table): string
    {
        return Str::studly(Str::singular($table)) . 'Seeder';
    }
    
    /**
     * Get the namespace for the seeder.
     *
     * @return string The seeder namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.seeders', 'Database\\Seeders');
    }
    
    /**
     * Get the file path for the seeder.
     *
     * @return string The seeder file path
     */
    public function getPath(): string
    {
        return base_path(Config::get('crud.paths.seeders', 'database/seeders'));
    }
    
    /**
     * Get the stub template content for seeder generation.
     *
     * @return string The stub template content
     */
    public function getStub(): string
    {
        $customStubPath = resource_path('stubs/crud/seeder.stub');
        
        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }
        
        return file_get_contents(__DIR__ . '/../stubs/seeder.stub');
    }
    
    /**
     * Build the seeder class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for seeder generation
     * @return string The generated seeder content
     */
    public function buildClass(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub();
        
        // Generate imports based on required functionality
        $imports = $this->generateImports($options, $modelNamespace, $modelClass);
        
        // Generate factory usage code
        $factoryUsage = $this->implementFactoryUsage("{$modelNamespace}\\{$modelClass}");
        
        // Setup relationship seeding
        $relationshipSeeding = $this->setupRelationshipSeeding($options['relationships'] ?? []);
        
        // Implement transaction handling
        $transactionHandling = $this->implementTransactionHandling();
        
        // Setup dependency management
        $dependencyManagement = $this->setupDependencyManagement($options['dependencies'] ?? []);
        
        // Generate truncation methods
        $truncationMethods = $this->generateTruncationMethods();
        
        // Setup production vs development data
        $environmentSpecificData = $this->setupProductionVsDevelopmentData($options['production_mode'] ?? false);
        
        // Implement randomization options
        $randomizationOptions = $this->implementRandomizationOptions();
        
        // Generate static data seeding
        $staticDataSeeding = $this->generateStaticDataSeeding($options['static_data'] ?? []);
        
        // Create demonstration data
        $demoData = $this->createDemonstrationData($options['demo_data'] ?? []);
        
        // Replace stub placeholders
        $seederContent = str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{factoryUsage}}',
            '{{relationshipSeeding}}',
            '{{transactionHandling}}',
            '{{dependencyManagement}}',
            '{{truncationMethods}}',
            '{{environmentSpecificData}}',
            '{{randomizationOptions}}',
            '{{staticDataSeeding}}',
            '{{demoData}}'
        ], [
            $namespace,
            $imports,
            $className,
            $factoryUsage,
            $relationshipSeeding,
            $transactionHandling,
            $dependencyManagement,
            $truncationMethods,
            $environmentSpecificData,
            $randomizationOptions,
            $staticDataSeeding,
            $demoData
        ], $stub);
        
        return $seederContent;
    }
    
    /**
     * Generate necessary import statements.
     *
     * @param array $options The seeder options
     * @param string $modelNamespace The model namespace
     * @param string $modelClass The model class name
     * @return string The import statements
     */
    protected function generateImports(array $options, string $modelNamespace, string $modelClass): string
    {
        $imports = [
            'Illuminate\Database\Seeder',
            "{$modelNamespace}\\{$modelClass}"
        ];
        
        // Add additional imports based on options
        if (!empty($options['use_factory'] ?? true)) {
            $imports[] = 'Illuminate\Database\Eloquent\Factories\Factory';
        }
        
        if (!empty($options['use_transactions'] ?? true)) {
            $imports[] = 'Illuminate\Support\Facades\DB';
        }
        
        if (!empty($options['faker'] ?? false)) {
            $imports[] = 'Faker\Factory as FakerFactory';
        }
        
        // Include imports for related models
        if (!empty($options['relationships'])) {
            foreach ($options['relationships'] as $relationship) {
                if (isset($relationship['related_model'])) {
                    $imports[] = "{$modelNamespace}\\" . $relationship['related_model'];
                }
            }
        }
        
        // Add any additional imports from options
        if (!empty($options['additional_imports']) && is_array($options['additional_imports'])) {
            $imports = array_merge($imports, $options['additional_imports']);
        }
        
        // Sort and make unique
        $imports = array_unique($imports);
        sort($imports);
        
        $importStrings = array_map(function ($import) {
            return "use {$import};";
        }, $imports);
        
        return implode("\n", $importStrings);
    }
    
    /**
     * Implement factory usage for model seeding.
     *
     * @param string $modelClass The fully qualified model class name
     * @return string The factory usage code
     */
    public function implementFactoryUsage(string $modelClass): string
    {
        $count = $this->options['count'] ?? 25;
        
        $code = "        // Create {$count} records using the model factory
        {$modelClass}::factory()->count({$count})->create();";
        
        // Add custom states if specified
        if (!empty($this->options['states'])) {
            $states = $this->options['states'];
            $stateCode = "";
            
            foreach ($states as $state => $percentage) {
                $stateCount = ceil(($percentage / 100) * $count);
                $stateCode .= "\n        // Create {$stateCount} {$state} records
        {$modelClass}::factory()->{$state}()->count({$stateCount})->create();";
            }
            
            $code .= $stateCode;
        }
        
        return $code;
    }
    
    /**
     * Set up relationship seeding logic.
     *
     * @param array $relationships The relationships to seed
     * @return string The relationship seeding code
     */
    public function setupRelationshipSeeding(array $relationships): string
    {
        if (empty($relationships)) {
            return '';
        }
        
        $code = "\n        // Seed relationships\n";
        
        foreach ($relationships as $relationship) {
            $parentModel = $relationship['parent_model'];
            $relatedModel = $relationship['related_model'];
            $relationshipType = $relationship['type'] ?? 'hasMany';
            $relationMethod = $relationship['method'] ?? Str::camel(Str::plural(class_basename($relatedModel)));
            $count = $relationship['count'] ?? 3;
            
            switch ($relationshipType) {
                case 'hasMany':
                    $code .= "        // Seed {$relationshipType} relationship between {$parentModel} and {$relatedModel}
        {$parentModel}::all()->each(function (\$parent) {
            \$parent->{$relationMethod}()->saveMany(
                {$relatedModel}::factory()->count({$count})->make()
            );
        });\n";
                    break;
                    
                case 'belongsToMany':
                    $code .= "        // Seed {$relationshipType} relationship between {$parentModel} and {$relatedModel}
        {$parentModel}::all()->each(function (\$parent) {
            \$relatedIds = {$relatedModel}::inRandomOrder()
                ->limit({$count})
                ->pluck('id');
            \$parent->{$relationMethod}()->attach(\$relatedIds);
        });\n";
                    break;
                    
                case 'morphMany':
                    $code .= "        // Seed {$relationshipType} relationship between {$parentModel} and {$relatedModel}
        {$parentModel}::all()->each(function (\$parent) {
            \$parent->{$relationMethod}()->saveMany(
                {$relatedModel}::factory()->count({$count})->make()
            );
        });\n";
                    break;
            }
        }
        
        return $code;
    }
    
    /**
     * Implement transaction handling for safe seeding.
     *
     * @return string The transaction handling code
     */
    public function implementTransactionHandling(): string
    {
        if (!($this->options['use_transactions'] ?? true)) {
            return '';
        }
        
        return "
    /**
     * Run the seeder within a database transaction for safety.
     *
     * @param callable \$callback The seeding operation to execute
     * @return void
     */
    protected function runInTransaction(callable \$callback)
    {
        DB::beginTransaction();
        
        try {
            \$callback();
            DB::commit();
        } catch (\\Exception \$e) {
            DB::rollBack();
            throw \$e;
        }
    }
    
    /**
     * Example of using the transaction wrapper.
     *
     * @return void
     */
    protected function seedWithTransaction()
    {
        \$this->runInTransaction(function () {
            // Your seeding code here
        });
    }";
    }
    
    /**
     * Set up dependency management for seeder execution order.
     *
     * @param array $dependencies The seeder dependencies
     * @return string The dependency management code
     */
    public function setupDependencyManagement(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '';
        }
        
        $callStatements = array_map(function ($dependency) {
            return "        \$this->call({$dependency}::class);";
        }, $dependencies);
        
        return "
    /**
     * Run dependencies before this seeder.
     *
     * @return void
     */
    protected function runDependencies()
    {
" . implode("\n", $callStatements) . "
    }";
    }
    
    /**
     * Generate methods for truncating tables before seeding.
     *
     * @return string The truncation methods code
     */
    public function generateTruncationMethods(): string
    {
        if (!($this->options['include_truncate'] ?? false)) {
            return '';
        }
        
        $tableName = $this->options['table'] ?? '';
        
        return "
    /**
     * Truncate the table before seeding.
     *
     * @param bool \$cascade Whether to cascade the truncation to related tables
     * @return void
     */
    protected function truncateTable(bool \$cascade = false)
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('{$tableName}')->truncate();
        if (\$cascade) {
            // Add related tables to truncate here
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }";
    }
    
    /**
     * Set up environment-specific data for production vs development.
     *
     * @param bool $isProduction Whether to generate production data
     * @return string The environment-specific code
     */
    public function setupProductionVsDevelopmentData(bool $isProduction): string
    {
        if (!($this->options['environment_specific'] ?? false)) {
            return '';
        }
        
        return "
    /**
     * Determine if the application is in production.
     *
     * @return bool
     */
    protected function isProduction()
    {
        return app()->environment('production');
    }
    
    /**
     * Seed data based on environment.
     *
     * @return void
     */
    protected function seedEnvironmentSpecificData()
    {
        if (\$this->isProduction()) {
            // Seed minimal, essential data for production
            \$this->seedProductionData();
        } else {
            // Seed extended data for development/testing
            \$this->seedDevelopmentData();
        }
    }
    
    /**
     * Seed production-specific data.
     *
     * @return void
     */
    protected function seedProductionData()
    {
        // Add production-specific seeding logic here
    }
    
    /**
     * Seed development-specific data.
     *
     * @return void
     */
    protected function seedDevelopmentData()
    {
        // Add development-specific seeding logic here
    }";
    }
    
    /**
     * Implement randomization options for seeder.
     *
     * @return string The randomization options code
     */
    public function implementRandomizationOptions(): string
    {
        if (!($this->options['randomization'] ?? false)) {
            return '';
        }
        
        return "
    /**
     * Get a Faker instance with a consistent seed for reproducibility.
     *
     * @param int \$seed The seed value for randomization
     * @return \Faker\Generator
     */
    protected function getFaker(int \$seed = 1234)
    {
        \$faker = FakerFactory::create();
        \$faker->seed(\$seed);
        return \$faker;
    }
    
    /**
     * Create random but consistent data using a seeded faker.
     *
     * @param int \$count The number of items to generate
     * @return void
     */
    protected function seedRandomData(int \$count = 10)
    {
        \$faker = \$this->getFaker();
        
        for (\$i = 0; \$i < \$count; \$i++) {
            // Use faker here to create records
        }
    }";
    }
    
    /**
     * Generate static data seeding code.
     *
     * @param array $staticData The static data to seed
     * @return string The static data seeding code
     */
    public function generateStaticDataSeeding(array $staticData): string
    {
        if (empty($staticData)) {
            return '';
        }
        
        $modelClass = Str::studly(Str::singular($this->options['table'] ?? ''));
        $namespace = Config::get('crud.namespaces.models', 'App\\Models');
        $fullModelClass = "{$namespace}\\{$modelClass}";
        
        $dataCode = var_export($staticData, true);
        
        return "
    /**
     * Seed static, predefined data.
     *
     * @return void
     */
    protected function seedStaticData()
    {
        \$staticData = {$dataCode};
        
        foreach (\$staticData as \$data) {
            {$fullModelClass}::updateOrCreate(
                ['id' => \$data['id'] ?? null],
                \$data
            );
        }
    }";
    }
    
    /**
     * Create demonstration data for the seeder.
     *
     * @param array $demoData The demo data configuration
     * @return string The demo data seeding code
     */
    public function createDemonstrationData(array $demoData): string
    {
        if (empty($demoData)) {
            return '';
        }
        
        $modelClass = Str::studly(Str::singular($this->options['table'] ?? ''));
        $namespace = Config::get('crud.namespaces.models', 'App\\Models');
        $fullModelClass = "{$namespace}\\{$modelClass}";
        
        return "
    /**
     * Create demonstration data with specific examples.
     *
     * @return void
     */
    protected function createDemoData()
    {
        // Only create demo data in non-production environments
        if (\$this->isProduction()) {
            return;
        }
        
        // Create specific demo examples
        \$demoExamples = [
            [
                'name' => 'Example 1',
                'description' => 'This is a demonstration record',
                // Add other fields as needed
            ],
            [
                'name' => 'Example 2',
                'description' => 'This is another demonstration record',
                // Add other fields as needed
            ],
        ];
        
        foreach (\$demoExamples as \$example) {
            {$fullModelClass}::create(\$example);
        }
    }";
    }
    
    /**
     * Generate a DatabaseSeeder file that includes this seeder.
     *
     * @param string $table The database table name
     * @return void
     */
    protected function generateDatabaseSeeder(string $table): void
    {
        $seederClass = $this->getClassName($table);
        $namespace = $this->getNamespace();
        
        $stubPath = resource_path('stubs/crud/database_seeder.stub');
        if (!Config::get('crud.stubs.use_custom', false) || !file_exists($stubPath)) {
            $stubPath = __DIR__ . '/../stubs/database_seeder.stub';
        }
        
        $stub = file_exists($stubPath) ? file_get_contents($stubPath) : 
            "<?php

namespace {{namespace}};

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        {{seeders}}
    }
}
";
        
        $databaseSeederPath = $this->getPath() . '/DatabaseSeeder.php';
        
        // Check if DatabaseSeeder already exists
        if (file_exists($databaseSeederPath)) {
            // Read existing seeder and add this one if not already included
            $content = file_get_contents($databaseSeederPath);
            
            if (!str_contains($content, "$seederClass::class")) {
                $pattern = '/(function\s+run\s*\(\s*\)\s*{)([\s\S]*?)(}\s*$)/m';
                $replacement = "$1$2        \$this->call({$seederClass}::class);\n    $3";
                $content = preg_replace($pattern, $replacement, $content);
                file_put_contents($databaseSeederPath, $content);
            }
        } else {
            // Create new DatabaseSeeder
            $content = str_replace([
                '{{namespace}}',
                '{{seeders}}'
            ], [
                $namespace,
                "        \$this->call({$seederClass}::class);"
            ], $stub);
            
            file_put_contents($databaseSeederPath, $content);
        }
        
        $this->generatedFiles[] = $databaseSeederPath;
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