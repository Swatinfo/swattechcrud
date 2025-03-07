<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use SwatTech\Crud\Repositories\BaseRepository;
use SwatTech\Crud\Repositories\CacheDecorator;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * Tests the BaseRepository and CacheDecorator functionality
 *
 * This test suite verifies that the repository implementation correctly
 * handles CRUD operations, transactions, caching, query building,
 * filtering, sorting, pagination, relationships, security, and performance.
 */
class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model for repository testing.
     * 
     * @var Model
     */
    protected $testModel;
    
    /**
     * Repository instance under test.
     * 
     * @var RepositoryInterface
     */
    protected $repository;
    
    /**
     * CacheDecorator instance for testing.
     * 
     * @var CacheDecorator
     */
    protected $cachedRepository;
    
    /**
     * Sample data for testing.
     * 
     * @var array
     */
    protected $testData;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test table for our sample model
        $this->createTestTable();
        
        // Create a test model
        $this->testModel = $this->createTestModel();
        
        // Create repository instances
        $this->repository = $this->createTestRepository($this->testModel);
        $this->cachedRepository = $this->createCachedRepository($this->repository);
        
        // Prepare test data
        $this->testData = [
            'name' => 'Test Item',
            'description' => 'This is a test item',
            'is_active' => true,
            'price' => 99.99,
        ];
    }

    /**
     * Clean up after tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Schema::dropIfExists('test_items');
        Schema::dropIfExists('test_related_items');
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a test table for our model.
     *
     * @return void
     */
    private function createTestTable(): void
    {
        Schema::create('test_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('test_related_items', function ($table) {
            $table->id();
            $table->foreignId('test_item_id')->constrained('test_items')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Create a test model for our repository.
     *
     * @return Model
     */
    private function createTestModel(): Model
    {
        // Define an anonymous model class for testing
        $model = new class extends Model {
            protected $table = 'test_items';
            protected $fillable = ['name', 'description', 'is_active', 'price'];
            protected $casts = [
                'is_active' => 'boolean',
                'price' => 'float',
            ];
            protected $dates = ['deleted_at'];
            
            public function relatedItems()
            {
                return $this->hasMany(TestRelatedItem::class, 'test_item_id');
            }
            
            public function scopeActive($query)
            {
                return $query->where('is_active', true);
            }
        };
        
        return $model;
    }

    /**
     * Create a test repository instance.
     *
     * @param Model $model The model instance
     * @return RepositoryInterface
     */
    private function createTestRepository(Model $model): RepositoryInterface
    {
        // Create a concrete implementation of BaseRepository for testing
        return new class($model) extends BaseRepository {
            public function __construct(Model $model)
            {
                $this->model = $model;
            }
        };
    }

    /**
     * Create a cached repository decorator.
     *
     * @param RepositoryInterface $repository The repository to decorate
     * @return CacheDecorator
     */
    private function createCachedRepository(RepositoryInterface $repository): CacheDecorator
    {
        return new CacheDecorator($repository, Cache::store());
    }

    /**
     * Test basic CRUD operations work correctly.
     *
     * @return void
     */
    public function testCrudOperations()
    {
        // Test Create
        $createdModel = $this->repository->create($this->testData);
        $this->assertInstanceOf(get_class($this->testModel), $createdModel);
        $this->assertEquals($this->testData['name'], $createdModel->name);
        $this->assertDatabaseHas('test_items', ['name' => $this->testData['name']]);

        // Test Read (Find)
        $foundModel = $this->repository->find($createdModel->id);
        $this->assertInstanceOf(get_class($this->testModel), $foundModel);
        $this->assertEquals($createdModel->id, $foundModel->id);

        // Test Update
        $updatedData = ['name' => 'Updated Name'];
        $updatedModel = $this->repository->update($createdModel->id, $updatedData);
        $this->assertEquals('Updated Name', $updatedModel->name);
        $this->assertDatabaseHas('test_items', ['id' => $createdModel->id, 'name' => 'Updated Name']);

        // Test Delete
        $result = $this->repository->delete($createdModel->id);
        $this->assertTrue($result);
        $this->assertSoftDeleted('test_items', ['id' => $createdModel->id]);
    }

    /**
     * Test transaction handling in repository.
     *
     * @return void
     */
    public function testTransactionHandling()
    {
        // Test successful transaction
        DB::beginTransaction();
        try {
            $model = $this->repository->create($this->testData);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        $this->assertDatabaseHas('test_items', ['name' => $this->testData['name']]);

        // Test transaction rollback
        $initialCount = DB::table('test_items')->count();
        
        DB::beginTransaction();
        try {
            $this->repository->create(['name' => 'Transaction Test']);
            throw new \Exception('Forced exception for testing rollback');
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }

        $afterCount = DB::table('test_items')->count();
        $this->assertEquals($initialCount, $afterCount, 'Transaction rollback failed');
    }

    /**
     * Test caching functionality with CacheDecorator.
     *
     * @return void
     */
    public function testCaching()
    {
        // Create a test record
        $model = $this->repository->create($this->testData);

        // Clear cache to ensure clean state
        Cache::flush();

        // First call should cache the result
        $cachedResult = $this->cachedRepository->find($model->id);
        
        // Modify the record directly to test cache vs database
        DB::table('test_items')
            ->where('id', $model->id)
            ->update(['name' => 'Changed in database']);

        // Second call should return cached result
        $secondCallResult = $this->cachedRepository->find($model->id);
        
        // Results should be equal (from cache) and not reflect direct DB change
        $this->assertEquals($cachedResult->name, $secondCallResult->name);
        $this->assertEquals($this->testData['name'], $secondCallResult->name);
        
        // Force clearing cache
        $this->cachedRepository->clearCache();
        
        // Third call should fetch from database with updated value
        $thirdCallResult = $this->cachedRepository->find($model->id);
        $this->assertEquals('Changed in database', $thirdCallResult->name);
    }

    /**
     * Test query building functionality.
     *
     * @return void
     */
    public function testQueryBuilding()
    {
        // Create multiple test records
        $this->repository->create([
            'name' => 'First Item', 'is_active' => true, 'price' => 10.99
        ]);
        $this->repository->create([
            'name' => 'Second Item', 'is_active' => false, 'price' => 20.99
        ]);
        $this->repository->create([
            'name' => 'Third Item', 'is_active' => true, 'price' => 30.99
        ]);

        // Test where condition
        $query = $this->testModel->newQuery();
        $query->where('is_active', true);
        $result = $query->get();
        
        $this->assertCount(2, $result);
        $this->assertEquals('First Item', $result[0]->name);
        $this->assertEquals('Third Item', $result[1]->name);

        // Test complex query
        $query = $this->testModel->newQuery();
        $query->where('is_active', true)
              ->where('price', '>', 15.00)
              ->orderBy('name');
        $result = $query->get();
        
        $this->assertCount(1, $result);
        $this->assertEquals('Third Item', $result[0]->name);
    }

    /**
     * Test filtering functionality in repository.
     *
     * @return void
     */
    public function testFiltering()
    {
        // Create multiple test records
        $this->repository->create([
            'name' => 'Active Item 1', 'is_active' => true, 'price' => 10.99
        ]);
        $this->repository->create([
            'name' => 'Inactive Item', 'is_active' => false, 'price' => 20.99
        ]);
        $this->repository->create([
            'name' => 'Active Item 2', 'is_active' => true, 'price' => 30.99
        ]);

        // Test filtering with exact match
        $filters = [
            'is_active' => ['operator' => '=', 'value' => true]
        ];
        $result = $this->repository->all($filters);
        
        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->is_active);
        $this->assertTrue($result[1]->is_active);

        // Test filtering with LIKE operator
        $filters = [
            'name' => ['operator' => 'like', 'value' => '%Item 2%']
        ];
        $result = $this->repository->all($filters);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Active Item 2', $result[0]->name);

        // Test combined filters
        $filters = [
            'is_active' => ['operator' => '=', 'value' => true],
            'price' => ['operator' => '>', 'value' => 15.00]
        ];
        $result = $this->repository->all($filters);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Active Item 2', $result[0]->name);
    }

    /**
     * Test sorting functionality in repository.
     *
     * @return void
     */
    public function testSorting()
    {
        // Create multiple test records
        $this->repository->create([
            'name' => 'B Item', 'price' => 20.99
        ]);
        $this->repository->create([
            'name' => 'C Item', 'price' => 10.99
        ]);
        $this->repository->create([
            'name' => 'A Item', 'price' => 30.99
        ]);

        // Test ascending sort
        $sorts = ['name' => 'asc'];
        $result = $this->repository->all([], $sorts);
        
        $this->assertEquals('A Item', $result[0]->name);
        $this->assertEquals('B Item', $result[1]->name);
        $this->assertEquals('C Item', $result[2]->name);

        // Test descending sort
        $sorts = ['name' => 'desc'];
        $result = $this->repository->all([], $sorts);
        
        $this->assertEquals('C Item', $result[0]->name);
        $this->assertEquals('B Item', $result[1]->name);
        $this->assertEquals('A Item', $result[2]->name);

        // Test multi-column sort (price asc, name desc)
        $this->repository->create([
            'name' => 'D Item', 'price' => 10.99
        ]);
        
        $sorts = ['price' => 'asc', 'name' => 'desc'];
        $result = $this->repository->all([], $sorts);
        
        $this->assertEquals(10.99, $result[0]->price);
        $this->assertEquals('D Item', $result[0]->name); // D comes after C alphabetically
        $this->assertEquals(10.99, $result[1]->price);
        $this->assertEquals('C Item', $result[1]->name);
    }

    /**
     * Test pagination functionality in repository.
     *
     * @return void
     */
    public function testPagination()
    {
        // Create multiple test records (11 total)
        for ($i = 1; $i <= 11; $i++) {
            $this->repository->create([
                'name' => "Item {$i}",
                'price' => $i * 10
            ]);
        }

        // Test default pagination (10 per page)
        $paginator = $this->repository->paginate();
        
        $this->assertEquals(1, $paginator->currentPage());
        $this->assertEquals(10, $paginator->perPage());
        $this->assertEquals(11, $paginator->total());
        $this->assertEquals(2, $paginator->lastPage());
        $this->assertCount(10, $paginator->items());

        // Test custom page and per_page
        $paginator = $this->repository->paginate(2, 5);
        
        $this->assertEquals(2, $paginator->currentPage());
        $this->assertEquals(5, $paginator->perPage());
        $this->assertEquals(11, $paginator->total());
        $this->assertEquals(3, $paginator->lastPage());
        $this->assertCount(5, $paginator->items());
        
        // Test pagination with filters
        $filters = [
            'price' => ['operator' => '>', 'value' => 50]
        ];
        $paginator = $this->repository->paginate(1, 5, $filters);
        
        $this->assertEquals(6, $paginator->total());
    }

    /**
     * Test relationship loading functionality.
     *
     * @return void
     */
    public function testRelationshipLoading()
    {
        // Define related model class
        if (!class_exists('TestRelatedItem')) {
            class_alias(
                new class extends Model {
                    protected $table = 'test_related_items';
                    protected $fillable = ['test_item_id', 'name'];
        
                    public function testItem()
                    {
                        return $this->belongsTo(TestItem::class);
                    }
                },
                'TestRelatedItem'
            );
        }
        
        // Create a parent item
        $parent = $this->repository->create([
            'name' => 'Parent Item',
            'price' => 99.99
        ]);

        // Create related items
        DB::table('test_related_items')->insert([
            ['test_item_id' => $parent->id, 'name' => 'Related 1', 'created_at' => now(), 'updated_at' => now()],
            ['test_item_id' => $parent->id, 'name' => 'Related 2', 'created_at' => now(), 'updated_at' => now()]
        ]);

        // Test eager loading
        $result = $this->repository->with(['relatedItems'])->find($parent->id);
        
        $this->assertTrue($result->relationLoaded('relatedItems'));
        $this->assertCount(2, $result->relatedItems);
        $this->assertEquals('Related 1', $result->relatedItems[0]->name);
        $this->assertEquals('Related 2', $result->relatedItems[1]->name);
    }

    /**
     * Test security checks in repository methods.
     *
     * @return void
     */
    public function testSecurityChecks()
    {
        // Test SQL injection protection
        $maliciousValue = "1; DROP TABLE test_items; --";
        
        // This should safely escape the input
        $this->repository->create([
            'name' => $maliciousValue,
            'price' => 99.99
        ]);
        
        // Verify table still exists and record was created safely
        $this->assertDatabaseHas('test_items', ['name' => $maliciousValue]);
        
        // Make sure we can still query - table wasn't dropped
        $count = DB::table('test_items')->count();
        $this->assertEquals(1, $count);
        
        // Test mass assignment protection
        $data = [
            'name' => 'Protected Test',
            'price' => 19.99,
            'non_fillable_column' => 'This should be ignored'
        ];
        
        $model = $this->repository->create($data);
        
        // Verify only fillable attributes were set
        $this->assertEquals('Protected Test', $model->name);
        $this->assertEquals(19.99, $model->price);
        $this->assertFalse(property_exists($model, 'non_fillable_column'));
    }

    /**
     * Test repository performance.
     *
     * @return void
     */
    public function testPerformance()
    {
        // Create a significant number of records for performance testing
        $recordCount = 50; // Keep modest for CI/testing environments
        
        $startTime = microtime(true);
        for ($i = 1; $i <= $recordCount; $i++) {
            $this->repository->create([
                'name' => "Performance Item {$i}",
                'price' => $i
            ]);
        }
        $insertTime = microtime(true) - $startTime;
        
        // Test bulk retrieval performance
        $startTime = microtime(true);
        $allRecords = $this->repository->all();
        $retrieveTime = microtime(true) - $startTime;
        
        // Test cached retrieval performance
        $startTime = microtime(true);
        $cachedRecords = $this->cachedRepository->all();
        $cachedTime = microtime(true) - $startTime;
        
        // Basic performance assertions
        $this->assertLessThan(1.0, $insertTime, 'Insertion took too long');
        $this->assertLessThan(0.5, $retrieveTime, 'Retrieval took too long');
        $this->assertLessThanOrEqual($retrieveTime, $cachedTime, 'Cached retrieval should be at least as fast as normal retrieval');
        
        // Performance of filtered queries
        $startTime = microtime(true);
        $filteredRecords = $this->repository->all(['price' => ['operator' => '>', 'value' => 25]]);
        $filterTime = microtime(true) - $startTime;
        
        $this->assertLessThan(0.5, $filterTime, 'Filtered query took too long');
        $this->assertEquals($recordCount - 25, count($filteredRecords));
    }
}