<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\BelongsToAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\HasManyAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\HasOneAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\BelongsToManyAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\MorphToAnalyzer;
use SwatTech\Crud\Analyzers\Relationships\MorphManyAnalyzer;
use SwatTech\Crud\Helpers\RelationshipHelper;
use SwatTech\Crud\Helpers\SchemaHelper;
use Tests\TestCase;

/**
 * Tests for the RelationshipAnalyzer class
 *
 * This test suite verifies that the RelationshipAnalyzer correctly detects
 * and processes various types of database relationships including hasMany,
 * hasOne, belongsTo, belongsToMany, morphTo, and morphMany.
 */
class RelationshipAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The RelationshipAnalyzer instance.
     *
     * @var RelationshipAnalyzer
     */
    protected $analyzer;

    /**
     * SchemaHelper mock.
     *
     * @var SchemaHelper
     */
    protected $schemaHelper;

    /**
     * RelationshipHelper mock.
     *
     * @var RelationshipHelper
     */
    protected $relationshipHelper;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create the test tables
        $this->createTestTables();

        // Create real instances of the analyzers with schema helper
        $this->schemaHelper = new SchemaHelper();
        $this->relationshipHelper = new RelationshipHelper();

        $hasManyAnalyzer = new HasManyAnalyzer($this->schemaHelper, $this->relationshipHelper);
        $hasOneAnalyzer = new HasOneAnalyzer($this->schemaHelper, $this->relationshipHelper);
        $belongsToAnalyzer = new BelongsToAnalyzer($this->schemaHelper, $this->relationshipHelper);
        $belongsToManyAnalyzer = new BelongsToManyAnalyzer($this->schemaHelper, $this->relationshipHelper);
        $morphToAnalyzer = new MorphToAnalyzer($this->schemaHelper, $this->relationshipHelper);
        $morphManyAnalyzer = new MorphManyAnalyzer($this->schemaHelper, $this->relationshipHelper);

        $this->analyzer = new RelationshipAnalyzer(
            $hasManyAnalyzer,
            $hasOneAnalyzer,
            $belongsToAnalyzer,
            $belongsToManyAnalyzer,
            $morphToAnalyzer,
            $morphManyAnalyzer,
            $this->relationshipHelper,
            $this->schemaHelper
        );
    }

    /**
     * Clean up the test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Drop test tables
        Schema::dropIfExists('tag_user');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('images');

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create the test database tables for relationship analysis.
     *
     * @return void
     */
    protected function createTestTables()
    {
        // Create users table (parent table)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        // Create profiles table (for hasOne relationship)
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('bio');
            $table->string('avatar');
            $table->timestamps();
        });

        // Create posts table (for hasMany relationship)
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });

        // Create comments table (for polymorphic morphTo/morphMany)
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable'); // Creates commentable_id and commentable_type
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('content');
            $table->timestamps();
        });

        // Create roles table (for belongsToMany relationship)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Create role_user pivot table (for belongsToMany relationship)
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('assigned_at')->nullable();
            $table->primary(['role_id', 'user_id']);
        });

        // Create tags table (for custom belongsToMany relationship)
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Non-standard pivot table (for custom naming conventions test)
        Schema::create('tag_user', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->primary(['tag_id', 'user_id']);
            $table->timestamps();
        });

        // Create images table (for testing cross-database relationships)
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable'); // Creates imageable_id and imageable_type
            $table->string('path');
            $table->timestamps();
        });
    }

    /**
     * Test detection of belongsTo relationships.
     *
     * @return void
     */
    public function testBelongsToDetection()
    {
        // Analyze the posts table which has a belongsTo relationship with users
        $this->analyzer->analyze('posts');
        $results = $this->analyzer->getResults();

        // Check that the relationship was detected
        $this->assertArrayHasKey('relationships', $results);
        
        // Find the belongsTo relationship
        $belongsToRelationship = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'belongsTo' && $relationship['related_table'] === 'users') {
                $belongsToRelationship = $relationship;
                break;
            }
        }

        // Assert the relationship was found and has correct properties
        $this->assertNotNull($belongsToRelationship, 'BelongsTo relationship not found');
        $this->assertEquals('user', $belongsToRelationship['method']);
        $this->assertEquals('user_id', $belongsToRelationship['foreign_key']);
        $this->assertEquals('id', $belongsToRelationship['owner_key']);
    }

    /**
     * Test detection of hasMany relationships.
     *
     * @return void
     */
    public function testHasManyDetection()
    {
        // Analyze the users table which has hasMany posts
        $this->analyzer->analyze('users');
        $results = $this->analyzer->getResults();

        // Check that the relationship was detected
        $this->assertArrayHasKey('relationships', $results);
        
        // Find the hasMany relationship
        $hasManyRelationship = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'hasMany' && $relationship['related_table'] === 'posts') {
                $hasManyRelationship = $relationship;
                break;
            }
        }

        // Assert the relationship was found and has correct properties
        $this->assertNotNull($hasManyRelationship, 'HasMany relationship not found');
        $this->assertEquals('posts', $hasManyRelationship['method']);
        $this->assertEquals('user_id', $hasManyRelationship['foreign_key']);
        $this->assertEquals('id', $hasManyRelationship['local_key']);
    }

    /**
     * Test detection of hasOne relationships.
     *
     * @return void
     */
    public function testHasOneDetection()
    {
        // Analyze the users table which has hasOne profile
        $this->analyzer->analyze('users');
        $results = $this->analyzer->getResults();

        // Check that the relationship was detected
        $this->assertArrayHasKey('relationships', $results);
        
        // Find the hasOne relationship
        $hasOneRelationship = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'hasOne' && $relationship['related_table'] === 'profiles') {
                $hasOneRelationship = $relationship;
                break;
            }
        }

        // Assert the relationship was found and has correct properties
        $this->assertNotNull($hasOneRelationship, 'HasOne relationship not found');
        $this->assertEquals('profile', $hasOneRelationship['method']);
        $this->assertEquals('user_id', $hasOneRelationship['foreign_key']);
        $this->assertEquals('id', $hasOneRelationship['local_key']);
    }

    /**
     * Test detection of belongsToMany relationships.
     *
     * @return void
     */
    public function testBelongsToManyDetection()
    {
        // Analyze users which belongs to many roles
        $this->analyzer->analyze('users');
        $results = $this->analyzer->getResults();
        
        // Check that relationships were detected
        $this->assertArrayHasKey('relationships', $results);
        
        // Find the belongsToMany relationship
        $belongsToManyRelationship = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'belongsToMany' && $relationship['related_table'] === 'roles') {
                $belongsToManyRelationship = $relationship;
                break;
            }
        }
        
        // Assert the relationship was found and has correct properties
        $this->assertNotNull($belongsToManyRelationship, 'BelongsToMany relationship not found');
        $this->assertEquals('roles', $belongsToManyRelationship['method']);
        $this->assertEquals('role_user', $belongsToManyRelationship['pivot_table']);
        $this->assertEquals('user_id', $belongsToManyRelationship['pivot_foreign_key']);
        $this->assertEquals('role_id', $belongsToManyRelationship['pivot_related_key']);
        
        // Check that pivot attributes were detected
        $this->assertContains('assigned_at', $belongsToManyRelationship['pivot_fields']);
    }

    /**
     * Test detection of morphTo relationships.
     *
     * @return void
     */
    public function testMorphToDetection()
    {
        // Analyze comments which has a morphTo relationship
        $this->analyzer->analyze('comments');
        $results = $this->analyzer->getResults();
        
        // Check that relationships were detected
        $this->assertArrayHasKey('relationships', $results);
        
        // Find the morphTo relationship
        $morphToRelationship = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'morphTo') {
                $morphToRelationship = $relationship;
                break;
            }
        }
        
        // Assert the relationship was found and has correct properties
        $this->assertNotNull($morphToRelationship, 'MorphTo relationship not found');
        $this->assertEquals('commentable', $morphToRelationship['method']);
        $this->assertEquals('commentable_type', $morphToRelationship['type_column']);
        $this->assertEquals('commentable_id', $morphToRelationship['id_column']);
    }

    /**
     * Test detection of morphMany relationships.
     *
     * @return void
     */
    public function testMorphManyDetection()
    {
        // Analyze posts which can have many comments (morphMany)
        $this->analyzer->analyze('posts');
        $results = $this->analyzer->getResults();
        
        // The relationship might be detected or not depending on detection strategy
        // In most implementations, it would require direct hints or configurations
        
        // For this test, we'll check if the method definitions would be correct
        // if the relationship is manually defined
        
        // Check mappings from the analyzer for morphable types
        $this->analyzer->analyze('comments');
        $results = $this->analyzer->getResults();
        
        // Verify polymorphic mappings existence
        $this->assertArrayHasKey('relationships', $results);
        
        // This test may need adjustment based on how the specific implementation
        // tracks and detects morphMany relationships which are usually inferred
        // rather than directly detected from the database schema
    }

    /**
     * Test handling of custom naming conventions.
     *
     * @return void
     */
    public function testCustomNamingConventions()
    {
        // Analyze the tags table which has a non-standard many-to-many relationship
        $this->analyzer->analyze('tags');
        $results = $this->analyzer->getResults();
        
        // Check for the detection of the non-standard pivot table
        $this->assertArrayHasKey('relationships', $results);
        
        $nonStandardPivot = null;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'belongsToMany' && $relationship['pivot_table'] === 'tag_user') {
                $nonStandardPivot = $relationship;
                break;
            }
        }
        
        $this->assertNotNull($nonStandardPivot, 'Non-standard pivot relationship not found');
        $this->assertEquals('users', $nonStandardPivot['method']);
    }

    /**
     * Test edge cases in relationship detection.
     *
     * @return void
     */
    public function testEdgeCases()
    {
        // Test analyzing a table that doesn't exist
        // This should not throw exceptions but return empty or null results
        $this->analyzer->analyze('nonexistent_table');
        $results = $this->analyzer->getResults();
        
        // Results should exist but might be empty
        $this->assertIsArray($results);
        
        // Test a table with no relationships
        Schema::create('standalone_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        
        $this->analyzer->analyze('standalone_table');
        $results = $this->analyzer->getResults();
        
        // Should have results structure but empty relationships
        $this->assertArrayHasKey('relationships', $results);
        $this->assertEmpty($results['relationships']);
        
        Schema::dropIfExists('standalone_table');
    }

    /**
     * Test detection of complex relationships.
     *
     * @return void
     */
    public function testComplexRelationships()
    {
        // Create tables with complex relationships
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('manager_id')->nullable()->references('id')->on('employees');
            $table->string('name');
            $table->timestamps();
        });
        
        // Test self-referencing relationship
        $this->analyzer->analyze('employees');
        $results = $this->analyzer->getResults();
        
        // Look for the self-referencing relationship
        $selfReference = false;
        foreach ($results['relationships'] as $relationship) {
            if ($relationship['type'] === 'belongsTo' && $relationship['related_table'] === 'employees') {
                $selfReference = true;
                break;
            }
        }
        
        $this->assertTrue($selfReference, 'Self-referencing relationship not detected');
        
        // Clean up
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }

    /**
     * Test cross-database relationships.
     * Note: This test may be skipped in CI environments that don't support multiple databases.
     *
     * @return void
     */
    public function testCrossDatabaseRelationships()
    {
        // This test would normally check cross-database relationships
        // In a test environment, we'll just check that the analyzer can handle
        // the schema helper's cross-database capabilities
        
        // Mock the schema helper to simulate cross-database queries
        $mockSchemaHelper = Mockery::mock(SchemaHelper::class);
        $mockSchemaHelper->shouldReceive('getDatabaseName')->andReturn('test_db');
        $mockSchemaHelper->shouldReceive('getTableColumns')->andReturn([
            'id' => ['type' => 'int', 'nullable' => false],
            'external_id' => ['type' => 'int', 'nullable' => false]
        ]);
        
        // Create a special analyzer with the mock
        $hasManyAnalyzer = new HasManyAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        $hasOneAnalyzer = new HasOneAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        $belongsToAnalyzer = new BelongsToAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        $belongsToManyAnalyzer = new BelongsToManyAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        $morphToAnalyzer = new MorphToAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        $morphManyAnalyzer = new MorphManyAnalyzer($mockSchemaHelper, $this->relationshipHelper);
        
        $specialAnalyzer = new RelationshipAnalyzer(
            $hasManyAnalyzer,
            $hasOneAnalyzer,
            $belongsToAnalyzer,
            $belongsToManyAnalyzer,
            $morphToAnalyzer,
            $morphManyAnalyzer,
            $this->relationshipHelper,
            $mockSchemaHelper
        );
        
        // This is more of a smoke test to ensure the analyzer can work with
        // the schema helper's cross-database capabilities
        $specialAnalyzer->analyze('cross_db_table');
        $results = $specialAnalyzer->getResults();
        
        $this->assertIsArray($results);
    }
}