<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use SwatTech\Crud\Commands\GenerateCrudCommand;
use Tests\TestCase;
use Mockery;

/**
 * Tests the CRUD generation functionality
 *
 * This test class verifies all aspects of the CRUD generation command,
 * including file generation, relationship detection, validation rules,
 * and various edge cases.
 */
class CrudGenerationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The test table name.
     *
     * @var string
     */
    protected $testTable = 'test_items';

    /**
     * The test model name.
     *
     * @var string
     */
    protected $testModel = 'TestItem';

    /**
     * Temporary test directory.
     *
     * @var string
     */
    protected $testDirectory;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for test files
        $this->testDirectory = base_path('tests/temp');
        if (!File::exists($this->testDirectory)) {
            File::makeDirectory($this->testDirectory, 0755, true);
        }

        // Configure test paths
        Config::set('crud.paths.models', $this->testDirectory . '/Models');
        Config::set('crud.paths.controllers', $this->testDirectory . '/Http/Controllers');
        Config::set('crud.paths.repositories', $this->testDirectory . '/Repositories');
        Config::set('crud.paths.services', $this->testDirectory . '/Services');
        Config::set('crud.paths.requests', $this->testDirectory . '/Http/Requests');
        Config::set('crud.paths.views', $this->testDirectory . '/resources/views');

        // Configure test namespaces
        Config::set('crud.namespaces.models', 'Tests\\Temp\\Models');
        Config::set('crud.namespaces.controllers', 'Tests\\Temp\\Http\\Controllers');
        Config::set('crud.namespaces.repositories', 'Tests\\Temp\\Repositories');
        Config::set('crud.namespaces.services', 'Tests\\Temp\\Services');
        Config::set('crud.namespaces.requests', 'Tests\\Temp\\Http\\Requests');

        // Create test tables
        $this->createTestTables();
    }

    /**
     * Clean up the test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Remove test tables
        Schema::dropIfExists('test_item_test_tag');
        Schema::dropIfExists('test_tags');
        Schema::dropIfExists('test_comments');
        Schema::dropIfExists('test_items');
        
        // Delete temporary test directory
        if (File::exists($this->testDirectory)) {
            File::deleteDirectory($this->testDirectory);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that the command executes successfully.
     *
     * @return void
     */
    public function testCommandExecution()
    {
        // Execute the command
        $exitCode = Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--force' => true,
        ]);

        // Assert command executed successfully
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('CRUD files', Artisan::output());
    }

    /**
     * Test that all necessary files are generated.
     *
     * @return void
     */
    public function testFileGeneration()
    {
        // Execute the command
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--force' => true,
        ]);

        // Assert model file was created
        $modelPath = $this->testDirectory . '/Models/' . $this->testModel . '.php';
        $this->assertTrue(File::exists($modelPath));
        $this->assertStringContainsString('namespace Tests\\Temp\\Models;', File::get($modelPath));

        // Assert controller was created
        $controllerPath = $this->testDirectory . '/Http/Controllers/' . $this->testModel . 'Controller.php';
        $this->assertTrue(File::exists($controllerPath));

        // Assert repository was created
        $repositoryPath = $this->testDirectory . '/Repositories/' . $this->testModel . 'Repository.php';
        $this->assertTrue(File::exists($repositoryPath));

        // Assert repository interface was created
        $repoInterfacePath = $this->testDirectory . '/Repositories/Interfaces/' . $this->testModel . 'RepositoryInterface.php';
        $this->assertTrue(File::exists($repoInterfacePath));

        // Assert service was created
        $servicePath = $this->testDirectory . '/Services/' . $this->testModel . 'Service.php';
        $this->assertTrue(File::exists($servicePath));

        // Assert request validation classes were created
        $createRequestPath = $this->testDirectory . '/Http/Requests/' . $this->testModel . 'StoreRequest.php';
        $updateRequestPath = $this->testDirectory . '/Http/Requests/' . $this->testModel . 'UpdateRequest.php';
        $this->assertTrue(File::exists($createRequestPath));
        $this->assertTrue(File::exists($updateRequestPath));

        // Assert views were created
        $viewsPath = $this->testDirectory . '/resources/views/' . strtolower($this->testTable);
        $this->assertTrue(File::exists($viewsPath . '/index.blade.php'));
        $this->assertTrue(File::exists($viewsPath . '/show.blade.php'));
        $this->assertTrue(File::exists($viewsPath . '/create.blade.php'));
        $this->assertTrue(File::exists($viewsPath . '/edit.blade.php'));
    }

    /**
     * Test that relationships are properly detected and implemented.
     *
     * @return void
     */
    public function testRelationshipDetection()
    {
        // Execute the command
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--force' => true,
        ]);

        // Check model for hasMany relationship to comments
        $modelPath = $this->testDirectory . '/Models/' . $this->testModel . '.php';
        $modelContent = File::get($modelPath);

        $this->assertStringContainsString('public function comments()', $modelContent);
        $this->assertStringContainsString('return $this->hasMany', $modelContent);

        // Check model for belongsToMany relationship to tags
        $this->assertStringContainsString('public function tags()', $modelContent);
        $this->assertStringContainsString('return $this->belongsToMany', $modelContent);
    }

    /**
     * Test that validation rules are properly generated.
     *
     * @return void
     */
    public function testValidationRuleGeneration()
    {
        // Execute the command
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--force' => true,
        ]);

        // Check store request for validation rules
        $storeRequestPath = $this->testDirectory . '/Http/Requests/' . $this->testModel . 'StoreRequest.php';
        $storeRequestContent = File::get($storeRequestPath);

        // Assert required fields have validation
        $this->assertStringContainsString("'name' => 'required|string|max:255'", $storeRequestContent);
        $this->assertStringContainsString("'email' => 'required|email|max:255'", $storeRequestContent);
        
        // Check update request
        $updateRequestPath = $this->testDirectory . '/Http/Requests/' . $this->testModel . 'UpdateRequest.php';
        $updateRequestContent = File::get($updateRequestPath);

        // Asset rules are similar but may have sometimes-rules
        $this->assertStringContainsString("'name' => 'sometimes|required|string|max:255'", $updateRequestContent);
    }

    /**
     * Test integration with Laravel's standard components.
     *
     * @return void
     */
    public function testIntegrationWithLaravel()
    {
        // Execute the command
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--force' => true,
        ]);

        // Check controller uses Auth facade
        $controllerPath = $this->testDirectory . '/Http/Controllers/' . $this->testModel . 'Controller.php';
        $controllerContent = File::get($controllerPath);
        
        $this->assertStringContainsString('use Illuminate\Support\Facades\Auth;', $controllerContent);
        $this->assertStringContainsString('use Illuminate\Http\Request;', $controllerContent);

        // Check model uses Laravel's model class
        $modelPath = $this->testDirectory . '/Models/' . $this->testModel . '.php';
        $modelContent = File::get($modelPath);
        
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Model;', $modelContent);
        $this->assertStringContainsString('class ' . $this->testModel . ' extends Model', $modelContent);
    }

    /**
     * Test edge cases in CRUD generation.
     *
     * @return void
     */
    public function testEdgeCases()
    {
        // Test with a table that has unconventional naming
        Schema::create('tbl_weird_naming_convention', function ($table) {
            $table->id();
            $table->string('some_field');
            $table->timestamps();
        });

        // Execute command with unconventional table name
        Artisan::call('crud:generate', [
            'table' => 'tbl_weird_naming_convention',
            '--force' => true,
        ]);

        // Assert model was created with proper studly case name
        $weirdModelPath = $this->testDirectory . '/Models/TblWeirdNamingConvention.php';
        $this->assertTrue(File::exists($weirdModelPath));

        // Test with table that has unique column constraints
        Schema::drop('tbl_weird_naming_convention');
        Schema::create('unique_test', function ($table) {
            $table->id();
            $table->string('username')->unique();
            $table->timestamps();
        });

        // Execute command
        Artisan::call('crud:generate', [
            'table' => 'unique_test',
            '--force' => true,
        ]);

        // Check validation has unique rule
        $uniqueRequestPath = $this->testDirectory . '/Http/Requests/UniqueTestStoreRequest.php';
        $uniqueRequestContent = File::get($uniqueRequestPath);
        $this->assertStringContainsString("'username' => 'required|string|max:255|unique:unique_test,username'", $uniqueRequestContent);

        // Clean up
        Schema::drop('unique_test');
    }

    /**
     * Test error handling in the command.
     *
     * @return void
     */
    public function testErrorHandling()
    {
        // Test with non-existent table
        $exitCode = Artisan::call('crud:generate', [
            'table' => 'non_existent_table',
        ]);

        // Assert command failed
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('does not exist', Artisan::output());

        // Test with invalid options combination
        $exitCode = Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--model' => true,
            '--controller' => false,
            '--views' => true,
            '--api' => true,
            '--repository' => false,
            '--service' => false,
        ]);

        // Check output for warning about views requiring controller
        $this->assertStringContainsString('Warning', Artisan::output());
    }

    /**
     * Test command customization options.
     *
     * @return void
     */
    public function testCustomizationOptions()
    {
        // Test with API-only option
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--api' => true,
            '--no-views' => true,
            '--force' => true,
        ]);

        // Check API controller was generated
        $apiControllerPath = $this->testDirectory . '/Http/Controllers/Api/' . $this->testModel . 'Controller.php';
        $this->assertTrue(File::exists($apiControllerPath));
        
        // Ensure views weren't generated
        $viewsPath = $this->testDirectory . '/resources/views/' . strtolower($this->testTable);
        $this->assertFalse(File::exists($viewsPath));

        // Test with custom namespace
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--namespace' => 'App\\Custom',
            '--force' => true,
        ]);

        // Check files have custom namespace
        $modelPath = $this->testDirectory . '/Models/' . $this->testModel . '.php';
        $modelContent = File::get($modelPath);
        $this->assertStringContainsString('namespace App\\Custom\\Models;', $modelContent);
    }

    /**
     * Test database setup handling.
     *
     * @return void
     */
    public function testDatabaseSetup()
    {
        // Test with specific database connection
        Config::set('database.connections.testing2', Config::get('database.connections.sqlite'));
        
        Artisan::call('crud:generate', [
            'table' => $this->testTable,
            '--connection' => 'testing2',
            '--force' => true,
        ]);

        // Check model includes connection specification
        $modelPath = $this->testDirectory . '/Models/' . $this->testModel . '.php';
        $modelContent = File::get($modelPath);
        $this->assertStringContainsString("protected \$connection = 'testing2';", $modelContent);
    }

    /**
     * Create test database tables for testing CRUD generation.
     *
     * @return void
     */
    private function createTestTables()
    {
        // Create main test table
        Schema::create('test_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Create related tables for testing relationships
        Schema::create('test_comments', function ($table) {
            $table->id();
            $table->foreignId('test_item_id')->constrained()->onDelete('cascade');
            $table->string('comment');
            $table->timestamps();
        });

        Schema::create('test_tags', function ($table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('test_item_test_tag', function ($table) {
            $table->foreignId('test_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('test_tag_id')->constrained()->onDelete('cascade');
            $table->primary(['test_item_id', 'test_tag_id']);
        });
    }
}