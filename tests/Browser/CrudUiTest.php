<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Tests the CRUD UI functionality with Laravel Dusk
 *
 * This class tests the frontend UI components and interactions of the SwatTech CRUD
 * package, including index page rendering, form handling, validation display,
 * relationship management, filtering, sorting, pagination, modal interactions,
 * file uploads, and JavaScript functionality.
 */
class CrudUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * The user for authentication.
     *
     * @var User
     */
    protected $user;

    /**
     * The test model with relationships.
     *
     * @var string
     */
    protected $testModel = 'Product';

    /**
     * The route prefix for the test model.
     *
     * @var string
     */
    protected $routePrefix = 'products';

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create storage disk for file uploads
        Storage::fake('public');

        // Create a user for authentication
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create test categories for relationship testing
        Category::factory()->count(5)->create();

        // Create test products for listing, filtering, sorting, etc.
        Product::factory()->count(25)->create();
    }

    /**
     * Test the index page renders correctly with all necessary components.
     *
     * @return void
     */
    public function testIndexPageRendering()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}")
                ->assertSee("Manage {$this->testModel}s")
                ->assertPresent('.data-table')
                ->assertPresent('.search-input')
                ->assertPresent('.filter-button')
                ->assertPresent('.create-button')
                ->assertPresent('.pagination-container')
                ->assertPresent('table')
                ->assertSee('Actions') // Action column header
                ->screenshot('index-page');
        });
    }

    /**
     * Test form submission works correctly.
     *
     * @return void
     */
    public function testFormSubmission()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}/create")
                ->assertSee("Create {$this->testModel}")
                ->assertPresent('form')
                // Fill in form fields
                ->type('name', 'Test Product')
                ->type('description', 'This is a test product description')
                ->type('price', '99.99')
                ->select('category_id', Category::first()->id)
                ->check('is_active')
                // Submit the form
                ->press('Save')
                // Check redirect to index
                ->assertPathIs("/{$this->routePrefix}")
                // Check success message
                ->assertSee('created successfully')
                // Check data in table
                ->assertSee('Test Product')
                ->screenshot('form-submission');
        });
    }

    /**
     * Test validation errors are displayed correctly.
     *
     * @return void
     */
    public function testValidationDisplay()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}/create")
                // Submit form with empty required fields
                ->press('Save')
                // Check validation errors
                ->assertSee('The name field is required')
                ->assertPresent('.is-invalid')
                ->assertPresent('.invalid-feedback')
                
                // Test inline validation (if using JS validation)
                ->type('price', 'not-a-number')
                ->click('label[for=name]') // Click elsewhere to trigger validation
                ->assertSee('The price must be a number')
                ->screenshot('validation-display');
        });
    }

    /**
     * Test relationship UI components render and function correctly.
     *
     * @return void
     */
    public function testRelationshipUi()
    {
        $this->browse(function (Browser $browser) {
            // Create a product first to edit
            $product = Product::factory()->create();
            
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}/{$product->id}/edit")
                
                // Test select dropdown for belongs-to relationship
                ->assertPresent('select[name=category_id]')
                ->assertSelectHasOption('category_id', Category::first()->id)
                
                // Test multi-select for belongs-to-many relationship (if applicable)
                ->whenAvailable('select[name="tags[]"]', function ($select) {
                    $select->assertPresent();
                })
                
                // Test nested form components (for has-many relationship)
                ->whenAvailable('.nested-form', function ($nestedForm) {
                    $nestedForm->assertPresent('.add-item-button');
                })
                
                // Test polymorphic relationship UI (if applicable)
                ->whenAvailable('.morphto-selector', function ($selector) {
                    $selector->assertPresent('select[name=commentable_type]');
                })
                
                ->screenshot('relationship-ui');
        });
    }

    /**
     * Test filtering functionality works correctly.
     *
     * @return void
     */
    public function testFiltering()
    {
        // Create specific products for filtering test
        Product::factory()->create(['name' => 'UniqueFilterName123']);
        
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}")
                
                // Click filter button to open filter panel
                ->click('.filter-button')
                ->waitFor('.filter-panel')
                
                // Fill in filter fields
                ->type('filter_name', 'UniqueFilterName123')
                ->press('Apply Filters')
                
                // Check if filtered correctly
                ->assertSee('UniqueFilterName123')
                ->assertDontSee('Showing 1-25 of')
                ->assertSee('Showing 1-1 of')
                
                // Clear filters
                ->click('.clear-filters-button')
                ->waitUntilMissing('.filter-panel')
                
                // Verify original list is restored
                ->assertSee('Showing 1-25 of')
                ->screenshot('filtering');
        });
    }

    /**
     * Test sorting functionality works correctly.
     *
     * @return void
     */
    public function testSorting()
    {
        // Create specific products for sorting test
        Product::factory()->create(['name' => 'AAA First Product']);
        Product::factory()->create(['name' => 'ZZZ Last Product']);
        
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}")
                
                // Test ascending sort
                ->click('th[data-sort="name"]')
                ->waitFor('.sort-asc')
                ->assertSeeIn('tbody tr:first-child', 'AAA First Product')
                
                // Test descending sort
                ->click('th[data-sort="name"]')
                ->waitFor('.sort-desc')
                ->assertSeeIn('tbody tr:first-child', 'ZZZ Last Product')
                
                // Test numeric column sorting
                ->click('th[data-sort="price"]')
                ->waitFor('.sort-asc')
                ->screenshot('sorting');
        });
    }

    /**
     * Test pagination controls work correctly.
     *
     * @return void
     */
    public function testPagination()
    {
        // Create enough products to ensure pagination (more than default per page)
        Product::factory()->count(30)->create();
        
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}")
                
                // Check pagination is present
                ->assertPresent('.pagination')
                ->assertSee('Showing 1-25 of')
                ->assertSeeLink('2')
                
                // Go to next page
                ->clickLink('2')
                ->waitForReload()
                ->assertQueryStringHas('page', '2')
                
                // Check per page dropdown
                ->whenAvailable('.per-page-selector', function ($selector) {
                    $selector->select('10')
                        ->waitForReload()
                        ->assertQueryStringHas('per_page', '10')
                        ->assertSee('Showing 1-10 of');
                })
                
                ->screenshot('pagination');
        });
    }

    /**
     * Test modal interactions for operations like delete confirmations.
     *
     * @return void
     */
    public function testModalInteractions()
    {
        // Create a product to delete
        $product = Product::factory()->create();
        
        $this->browse(function (Browser $browser) use ($product) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}")
                
                // Click delete button to trigger modal
                ->click("button.delete-btn[data-id='{$product->id}']")
                ->waitFor('.modal')
                ->assertSee('Confirm Deletion')
                
                // Test modal cancel button
                ->press('Cancel')
                ->waitUntilMissing('.modal')
                ->assertSee($product->name)
                
                // Trigger modal again and confirm deletion
                ->click("button.delete-btn[data-id='{$product->id}']")
                ->waitFor('.modal')
                ->press('Delete')
                ->waitForReload()
                ->assertSee('deleted successfully')
                ->assertDontSee($product->name)
                
                // Test bulk action modal (if applicable)
                ->whenAvailable('.select-all-checkbox', function ($checkbox) {
                    $checkbox->check();
                })
                ->whenAvailable('.bulk-actions', function ($bulkActions) {
                    $bulkActions->select('delete')
                        ->waitFor('.modal')
                        ->assertSee('Confirm Bulk Deletion');
                })
                
                ->screenshot('modal-interactions');
        });
    }

    /**
     * Test file upload functionality.
     *
     * @return void
     */
    public function testFileUpload()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}/create")
                
                // Test file upload component
                ->whenAvailable('.file-upload', function ($fileUpload) {
                    // Attach a fake file
                    $fileUpload->attach('input[type="file"]', __DIR__.'/fixtures/test-image.jpg');
                })
                
                // Fill other required fields
                ->type('name', 'Product With Image')
                ->type('price', '49.99')
                ->select('category_id', Category::first()->id)
                
                // Submit the form
                ->press('Save')
                ->waitForReload()
                
                // Check success and image preview
                ->assertSee('created successfully')
                ->visit("/{$this->routePrefix}")
                ->clickLink('Product With Image')
                ->assertPresent('.image-preview')
                
                ->screenshot('file-upload');
        });
    }

    /**
     * Test JavaScript functionality like dynamic form elements.
     *
     * @return void
     */
    public function testJavaScriptFunctionality()
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/{$this->routePrefix}/create")
                
                // Test dynamic dependent dropdown
                ->whenAvailable('.dynamic-form', function ($form) {
                    $form->select('parent_category', Category::first()->id)
                        ->waitFor('.child-dropdown.loaded')
                        ->assertPresent('select[name=subcategory_id]:not(:disabled)');
                })
                
                // Test conditional fields
                ->whenAvailable('.conditional-container', function ($container) {
                    $container->check('has_variants')
                        ->waitFor('.variant-fields')
                        ->assertPresent('.variant-row');
                })
                
                // Test dynamic field addition
                ->whenAvailable('.repeater-container', function ($repeater) {
                    $repeater->click('.add-row-button')
                        ->waitFor('.repeater-row:nth-child(2)')
                        ->assertPresent('.repeater-row:nth-child(2)');
                })
                
                // Test form wizard/stepper (if applicable)
                ->whenAvailable('.form-wizard', function ($wizard) {
                    $wizard->assertSee('Step 1 of')
                        ->type('name', 'Multi-step Product')
                        ->press('Next Step')
                        ->waitFor('.step-2.active')
                        ->assertSee('Step 2 of');
                })
                
                ->screenshot('javascript-functionality');
        });
    }
}