<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;
use SwatTech\Crud\Analyzers\RelationshipAnalyzer;

/**
 * ViewGenerator
 *
 * This class is responsible for generating Vuexy-themed Blade views for the application.
 * It creates the necessary view files for CRUD operations with a modern UI design
 * and advanced features like filters, pagination, modals, and form components.
 *
 * @package SwatTech\Crud\Generators
 */
class ViewGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The relationship analyzer instance.
     *
     * @var RelationshipAnalyzer
     */
    protected $relationshipAnalyzer;

    /**
     * The model generator instance.
     *
     * @var ModelGenerator
     */
    protected $modelGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * View configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ViewGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param RelationshipAnalyzer $relationshipAnalyzer
     * @param ModelGenerator $modelGenerator
     */
    public function __construct(StringHelper $stringHelper, RelationshipAnalyzer $relationshipAnalyzer, ModelGenerator $modelGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->modelGenerator = $modelGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.views', []);
    }

    /**
     * Generate view files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for view generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Get table columns and relationships
        $columns = $this->getTableColumns($table);
        $relationshipsResult = $this->relationshipAnalyzer->analyze($table);
        $relationships = $relationshipsResult['results'];

        // Generate default CRUD views
        $this->generateIndexView($table, $columns, $this->options);
        $this->generateCreateView($table, $columns, $this->options);
        $this->generateEditView($table, $columns, $this->options);
        $this->generateShowView($table, $columns, $this->options);

        // Generate form partials
        $this->generateFormPartials($table, $columns);

        // Generate modal operations if enabled
        if ($this->options['use_modals'] ?? true) {
            $this->setupModalOperations($table);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the view name for the specified table and view type.
     *
     * @param string $table The database table name
     * @param string $view The view type (index, create, edit, show)
     * @return string The view name
     */
    public function getViewName(string $table, string $view): string
    {
        $viewPrefix = Str::kebab(Str::plural($table));
        return $viewPrefix . '.' . $view;
    }

    /**
     * Get the file path for the view.
     *
     * @param string $view The view path
     * @return string The view file path
     */
    public function getPath(string $path = ""): string
    {
        return resource_path('views/' . str_replace('.', '/', $path) . '.blade.php');
    }

    /**
     * Get the stub template content for view generation.
     *
     * @param string $view The view type (index, create, edit, show)
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path("stubs/crud/views/{$view}.blade.stub");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/vuexy/views/{$view}.blade.stub");
    }

    /**
     * Build and save a view file for the specified table and view type.
     *
     * @param string $table The database table name
     * @param string $view The view type (index, create, edit, show)
     * @param array $options Options for view generation
     * @return string The generated file path
     */
    public function buildView(string $table, string $view, array $options, array $viewVars): string
    {
        $viewName = $this->getViewName($table, $view);
        $filePath = $this->getPath($viewName);
        $stub = $this->getStub($view);
        foreach ($viewVars as $key => $value) {
            $stub = str_replace('{{' . $key . '}}', $value, $stub);
        }
        // Replace template variables
        $content = $this->replaceTemplateVariables($stub, $table, $options);

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write the file
        file_put_contents($filePath, $content);

        $this->generatedFiles[] = $filePath;

        return $filePath;
    }

    /**
     * Generate an index view for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The generated file path
     */
    public function generateIndexView(string $table, array $columns, array $options): string
    {




        $modelName = Str::studly(Str::singular($table));
        $viewVars = [
            'modelName' => $modelName,
            'modelNamePlural' => Str::plural($modelName),
            'tableName' => $table,
            'routeName' => Str::kebab(Str::plural($table)),
            'columns' => $this->prepareColumnsForDisplay($columns, $options),
            'searchable' => $this->getSearchableColumns($columns),
            'sortable' => $this->getSortableColumns($columns),
        ];

        // Add breadcrumb navigation
        $viewVars['breadcrumbs'] = $this->generateBreadcrumbNavigation($table);

        // Add pagination controls
        $viewVars['pagination'] = $this->setupPaginationControls();

        // Add export buttons
        $viewVars['exportButtons'] = $this->setupExportButtons($table);

        // Add bulk action controls
        $viewVars['bulkActions'] = $this->setupBulkActionControls($table);

        // Add filter components
        $viewVars['filters'] = $this->generateFilterComponents($table, $columns);

        // Build the view with variables
        // $stub = $this->getStub('index');


        return $this->buildView($table, 'index', $options, $viewVars);
    }

    /**
     * Generate a create view for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The generated file path
     */
    public function generateCreateView(string $table, array $columns, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $viewVars = [
            'modelName' => $modelName,
            'tableName' => $table,
            'routeName' => Str::kebab(Str::plural($table)),
            'fields' => $this->generateFormFields($columns, $options),
        ];

        // Add breadcrumb navigation
        $viewVars['breadcrumbs'] = $this->generateBreadcrumbNavigation($table, 'create');

        // Add relationship fields
        $relationshipsResult = $this->relationshipAnalyzer->analyze($table);
        $relationships = $relationshipsResult['results'];
        $viewVars['relationshipFields'] = $this->generateRelationshipFormFields($relationships);

        // Setup Vuexy card layout
        $viewVars['cardLayout'] = $this->setupVuexyCardLayouts($table, $options);

        // // Build the view with variables
        // $stub = $this->getStub('create');
        // foreach ($viewVars as $key => $value) {
        //     $stub = str_replace('{{' . $key . '}}', $value, $stub);
        // }

        return $this->buildView($table, 'create', $options, $viewVars);
    }

    /**
     * Generate an edit view for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The generated file path
     */
    public function generateEditView(string $table, array $columns, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);

        $viewVars = [
            'modelName' => $modelName,
            'modelVariable' => $modelVariable,
            'tableName' => $table,
            'routeName' => Str::kebab(Str::plural($table)),
            'fields' => $this->generateFormFields($columns, $options, true),
        ];

        // Add breadcrumb navigation
        $viewVars['breadcrumbs'] = $this->generateBreadcrumbNavigation($table, 'edit');

        // Add relationship fields
        $relationshipsResult = $this->relationshipAnalyzer->analyze($table);
        $relationships = $relationshipsResult['results'];
        $viewVars['relationshipFields'] = $this->generateRelationshipFormFields($relationships, true);

        // Setup Vuexy card layout
        $viewVars['cardLayout'] = $this->setupVuexyCardLayouts($table, $options);

        // Build the view with variables
        // $stub = $this->getStub('edit');
        // foreach ($viewVars as $key => $value) {
        //     $stub = str_replace('{{' . $key . '}}', $value, $stub);
        // }

        return $this->buildView($table, 'edit', $options, $viewVars);
    }

    /**
     * Generate a show view for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The generated file path
     */
    public function generateShowView(string $table, array $columns, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);

        $viewVars = [
            'modelName' => $modelName,
            'modelVariable' => $modelVariable,
            'tableName' => $table,
            'routeName' => Str::kebab(Str::plural($table)),
            'fields' => $this->generateDetailFields($columns, $options),
        ];

        // Add breadcrumb navigation
        $viewVars['breadcrumbs'] = $this->generateBreadcrumbNavigation($table, 'show');

        // Add relationship display components
        $relationshipsResult = $this->relationshipAnalyzer->analyze($table);
        $relationships = $relationshipsResult['results'];
        $viewVars['relationshipComponents'] = $this->generateRelationshipDisplayComponents($table, $relationships);

        // Setup Vuexy card layout
        $viewVars['cardLayout'] = $this->setupVuexyCardLayouts($table, $options);

        // Build the view with variables
        // $stub = $this->getStub('show');
        // foreach ($viewVars as $key => $value) {
        //     $stub = str_replace('{{' . $key . '}}', $value, $stub);
        // }

        return $this->buildView($table, 'show', $options, $viewVars);
    }

    /**
     * Generate form partials for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @return array Array of generated partial file paths
     */
    public function generateFormPartials(string $table, array $columns): array
    {
        $partials = [];
        $basePath = resource_path('views/' . Str::kebab(Str::plural($table)) . '/partials');

        // Create directory if it doesn't exist
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Generate form fields partial
        $formFieldsPartial = $basePath . '/_form_fields.blade.php';
        $formFieldsContent = $this->generateFormFields($columns, $this->options);
        file_put_contents($formFieldsPartial, $formFieldsContent);
        $this->generatedFiles[] = $formFieldsPartial;
        $partials['form_fields'] = $formFieldsPartial;

        // Generate validation errors partial
        $validationErrorsPartial = $basePath . '/_validation_errors.blade.php';
        $validationErrorsContent = $this->generateValidationErrorsPartial();
        file_put_contents($validationErrorsPartial, $validationErrorsContent);
        $this->generatedFiles[] = $validationErrorsPartial;
        $partials['validation_errors'] = $validationErrorsPartial;

        return $partials;
    }

    /**
     * Set up modal operations for the specified table.
     *
     * @param string $table The database table name
     * @return array Array of generated modal file paths
     */
    public function setupModalOperations(string $table): array
    {
        $modals = [];
        $basePath = resource_path('views/' . Str::kebab(Str::plural($table)) . '/modals');

        // Create directory if it doesn't exist
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Generate create modal
        $createModalPath = $basePath . '/create.blade.php';
        $createModalContent = $this->generateModalContent($table, 'create');
        file_put_contents($createModalPath, $createModalContent);
        $this->generatedFiles[] = $createModalPath;
        $modals['create'] = $createModalPath;

        // Generate edit modal
        $editModalPath = $basePath . '/edit.blade.php';
        $editModalContent = $this->generateModalContent($table, 'edit');
        file_put_contents($editModalPath, $editModalContent);
        $this->generatedFiles[] = $editModalPath;
        $modals['edit'] = $editModalPath;

        // Generate delete confirmation modal
        $deleteModalPath = $basePath . '/delete.blade.php';
        $deleteModalContent = $this->generateModalContent($table, 'delete');
        file_put_contents($deleteModalPath, $deleteModalContent);
        $this->generatedFiles[] = $deleteModalPath;
        $modals['delete'] = $deleteModalPath;

        return $modals;
    }

    /**
     * Set up Vuexy card layouts for the specified table.
     *
     * @param string $table The database table name
     * @param array $options Options for layout generation
     * @return string The card layout HTML
     */
    public function setupVuexyCardLayouts(string $table, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $cardTitle = $options['card_title'] ?? "{$modelName} Details";
        $cardClass = $options['card_class'] ?? 'card';
        $cardHeaderClass = $options['card_header_class'] ?? 'card-header';
        $cardBodyClass = $options['card_body_class'] ?? 'card-body';

        return <<<HTML
<div class="{$cardClass}">
    <div class="{$cardHeaderClass}">
        <h4 class="card-title">{$cardTitle}</h4>
        <div class="card-header-actions">
            {{-- Card header actions here --}}
        </div>
    </div>
    <div class="{$cardBodyClass}">
        {{-- Card content here --}}
        {{\$content}}
    </div>
</div>
HTML;
    }

    /**
     * Generate filter components for the specified table.
     *
     * @param string $table The database table name
     * @param array $columns The table columns
     * @return string The filter components HTML
     */
    public function generateFilterComponents(string $table, array $columns): string
    {
        $modelName = Str::studly(Str::singular($table));
        $routeName = Str::kebab(Str::plural($table));

        // Get filterable columns
        $filterableColumns = $this->getFilterableColumns($columns);

        // Build filter fields
        $filterFields = '';
        foreach ($filterableColumns as $column) {
            $fieldName = $column['name'];
            $fieldLabel = Str::title(str_replace('_', ' ', $fieldName));
            $fieldType = $this->mapColumnTypeToInputType($column['type']);

            $filterFields .= <<<HTML
            <div class="mb-3 col-md-3">
                <label for="filter_{$fieldName}" class="form-label">{$fieldLabel}</label>
                <input type="{$fieldType}" class="form-control" id="filter_{$fieldName}" name="filter[{$fieldName}]" value="{{ request('filter.{$fieldName}') }}">
            </div>
HTML;
        }

        // Build the filter component
        return <<<HTML
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Filters</h5>
        <a href="#" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
            <i class="fa fa-plus"></i> Toggle Filters
        </a>
    </div>
    <div class="collapse" id="filterCollapse">
        <div class="card-body">
            <form action="{{ route('{$routeName}.index') }}" method="GET">
                <div class="row">
                    {$filterFields}
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="{{ route('{$routeName}.index') }}" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Set up pagination controls.
     *
     * @return string The pagination controls HTML
     */
    public function setupPaginationControls(): string
    {
        return <<<HTML
<div class="d-flex justify-content-between align-items-center mt-4">
    <div>
        Showing {{ \$records->firstItem() }} to {{ \$records->lastItem() }} of {{ \$records->total() }} entries
    </div>
    <div>
        {{ \$records->appends(request()->query())->links('pagination::bootstrap-5') }}
    </div>
</div>
HTML;
    }

    /**
     * Generate breadcrumb navigation for the specified table.
     *
     * @param string $table The database table name
     * @param string $action The current action (index, create, edit, show)
     * @return string The breadcrumb navigation HTML
     */
    public function generateBreadcrumbNavigation(string $table, string $action = 'index'): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelNamePlural = Str::plural($modelName);
        $routeName = Str::kebab(Str::plural($table));

        // Create breadcrumb items based on action
        $breadcrumbItems = [
            ['url' => "{{ route('dashboard') }}", 'label' => 'Dashboard'],
            ['url' => "{{ route('{$routeName}.index') }}", 'label' => $modelNamePlural],
        ];

        if ($action === 'create') {
            $breadcrumbItems[] = ['url' => '#', 'label' => 'Create New', 'active' => true];
        } elseif ($action === 'edit') {
            $breadcrumbItems[] = ['url' => "{{ route('{$routeName}.show', \${$routeName}) }}", 'label' => 'View'];
            $breadcrumbItems[] = ['url' => '#', 'label' => 'Edit', 'active' => true];
        } elseif ($action === 'show') {
            $breadcrumbItems[] = ['url' => '#', 'label' => 'View', 'active' => true];
        }

        // Build HTML
        $breadcrumbHTML = '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">';

        foreach ($breadcrumbItems as $item) {
            $isActive = $item['active'] ?? false;

            if ($isActive) {
                $breadcrumbHTML .= '<li class="breadcrumb-item active" aria-current="page">' . $item['label'] . '</li>';
            } else {
                $breadcrumbHTML .= '<li class="breadcrumb-item"><a href="' . $item['url'] . '">' . $item['label'] . '</a></li>';
            }
        }

        $breadcrumbHTML .= '</ol></nav>';

        return $breadcrumbHTML;
    }

    /**
     * Set up export buttons for the specified table.
     *
     * @param string $table The database table name
     * @return string The export buttons HTML
     */
    public function setupExportButtons(string $table): string
    {
        $routeName = Str::kebab(Str::plural($table));

        return <<<HTML
<div class="export-buttons">
    <div class="dropdown">
        <button class="btn btn-primary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa fa-download me-1"></i> Export
        </button>
        <ul class="dropdown-menu" aria-labelledby="exportDropdown">
            <li><a class="dropdown-item" href="{{ route('{$routeName}.export', ['format' => 'csv'] + request()->query()) }}">CSV</a></li>
            <li><a class="dropdown-item" href="{{ route('{$routeName}.export', ['format' => 'excel'] + request()->query()) }}">Excel</a></li>
            <li><a class="dropdown-item" href="{{ route('{$routeName}.export', ['format' => 'pdf'] + request()->query()) }}">PDF</a></li>
        </ul>
    </div>
</div>
HTML;
    }

    /**
     * Set up bulk action controls for the specified table.
     *
     * @param string $table The database table name
     * @return string The bulk action controls HTML
     */
    public function setupBulkActionControls(string $table): string
    {
        $routeName = Str::kebab(Str::plural($table));

        return <<<HTML
<div class="bulk-actions mb-3">
    <form id="bulk-action-form" action="{{ route('{$routeName}.bulk') }}" method="POST">
        @csrf
        <div class="d-flex align-items-center">
            <select name="bulk_action" class="form-select me-2" style="width: auto">
                <option value="">Select Action</option>
                <option value="delete">Delete Selected</option>
                <option value="export">Export Selected</option>
                <!-- Add more actions as needed -->
            </select>
            <button type="submit" class="btn btn-secondary bulk-action-btn" disabled>Apply</button>
            
            <!-- Selected items will be added here as hidden inputs -->
            <div id="selected-items-container"></div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bulkForm = document.getElementById('bulk-action-form');
    const bulkActionBtn = document.querySelector('.bulk-action-btn');
    const selectedItemsContainer = document.getElementById('selected-items-container');
    const checkboxes = document.querySelectorAll('.record-checkbox');
    
    // Update selected items and button state
    function updateSelectedItems() {
        const selectedItems = [];
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                selectedItems.push(checkbox.value);
            }
        });
        
        // Clear previous hidden inputs
        selectedItemsContainer.innerHTML = '';
        
        // Create hidden inputs for selected items
        selectedItems.forEach(itemId => {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'selected_items[]';
            hiddenInput.value = itemId;
            selectedItemsContainer.appendChild(hiddenInput);
        });
        
        // Update button state
        bulkActionBtn.disabled = selectedItems.length === 0;
        
        // Update selected count display if it exists
        const countDisplay = document.querySelector('.selected-count');
        if (countDisplay) {
            countDisplay.textContent = selectedItems.length;
        }
    }
    
    // Add event listeners to checkboxes
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedItems);
    });
    
    // Add event listener to select all checkbox
    const selectAllCheckbox = document.getElementById('select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateSelectedItems();
        });
    }
    
    // Form submission confirmation
    bulkForm.addEventListener('submit', function(e) {
        const action = bulkForm.elements['bulk_action'].value;
        if (action === 'delete') {
            if (!confirm('Are you sure you want to delete the selected items? This action cannot be undone.')) {
                e.preventDefault();
            }
        }
    });
});
</script>
HTML;
    }

    /**
     * Generate relationship display components for the specified table.
     *
     * @param string $table The database table name
     * @param array $relationships The table relationships
     * @return string The relationship display components HTML
     */
    public function generateRelationshipDisplayComponents(string $table, array $relationships): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);

        $relationshipTabs = '';
        $tabPanes = '';

        // Group relationships by type
        $relationshipGroups = [];
        foreach ($relationships as $relationship) {
            $type = $relationship['type'] ?? 'default';
            if (!isset($relationshipGroups[$type])) {
                $relationshipGroups[$type] = [];
            }
            $relationshipGroups[$type][] = $relationship;
        }

        // Process each relationship type
        $tabIndex = 0;
        foreach ($relationshipGroups as $type => $relations) {
            foreach ($relations as $relation) {
                $relatedTable = $relation['related_table'] ?? '';
                if (empty($relatedTable)) {
                    continue;
                }

                $relationName = $relation['name'] ?? Str::camel($relatedTable);
                $relatedModelName = Str::studly(Str::singular($relatedTable));
                $tabId = "tab-" . Str::slug($relationName);
                $isActive = $tabIndex === 0;

                // Create tab button
                $activeClass = $isActive ? 'active' : '';
                $relationshipTabs .= <<<HTML
                <li class="nav-item">
                    <a class="nav-link {$activeClass}" id="{$tabId}-tab" data-bs-toggle="tab" href="#{$tabId}" role="tab" aria-controls="{$tabId}" aria-selected="{$isActive}">
                        {$relatedModelName}
                    </a>
                </li>
HTML;

                // Create tab content
                $activeClass = $isActive ? 'show active' : '';
                $tabPanes .= <<<HTML
                <div class="tab-pane fade {$activeClass}" id="{$tabId}" role="tabpanel" aria-labelledby="{$tabId}-tab">
                    <div class="related-records">
                        @if(isset(\${$modelVariable}->{$relationName}) && \${$modelVariable}->{$relationName}->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <!-- Replace with actual columns from the related model -->
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach(\${$modelVariable}->{$relationName} as \$related)
                                            <tr>
                                                <!-- Replace with actual properties from the related model -->
                                                <td>{{ \$related->id }}</td>
                                                <td>{{ \$related->name ?? \$related->title ?? 'N/A' }}</td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="#" class="btn btn-outline-primary">View</a>
                                                        <a href="#" class="btn btn-outline-secondary">Edit</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="alert alert-info">No related {$relatedModelName} records found.</div>
                        @endif
                    </div>
                </div>
HTML;

                $tabIndex++;
            }
        }

        // If there are no relationships, return empty string
        if ($tabIndex === 0) {
            return '';
        }

        // Build the complete component
        return <<<HTML
<div class="related-data-section mt-4">
    <h4>Related Data</h4>
    
    <div class="card">
        <div class="card-body">
            <ul class="nav nav-tabs" id="relationshipTabs" role="tablist">
                {$relationshipTabs}
            </ul>
            <div class="tab-content" id="relationshipTabsContent">
                {$tabPanes}
            </div>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Replace template variables with actual content.
     *
     * @param string $template The template content
     * @param string $table The database table name
     * @param array $options Options for view generation
     * @return string The processed template content
     */
    protected function replaceTemplateVariables(string $template, string $table, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);
        $routeName = Str::kebab(Str::plural($table));

        $replacements = [
            '{{modelName}}' => $modelName,
            '{{modelNamePlural}}' => Str::plural($modelName),
            '{{modelVariable}}' => $modelVariable,
            '{{tableName}}' => $table,
            '{{routeName}}' => $routeName,
        ];

        foreach ($options as $key => $value) {
            if (is_string($value)) {
                $replacements['{{' . $key . '}}'] = $value;
            }
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Get the table columns from the database.
     *
     * @param string $table The database table name
     * @return array The table columns
     */
    protected function getTableColumns(string $table): array
    {
        // This should be replaced with actual database inspection logic
        // For now, return a sample column structure
        return [
            ['name' => 'id', 'type' => 'increments', 'required' => true],
            ['name' => 'name', 'type' => 'string', 'required' => true],
            ['name' => 'email', 'type' => 'string', 'required' => true],
            ['name' => 'description', 'type' => 'text', 'required' => false],
            ['name' => 'status', 'type' => 'boolean', 'required' => true],
            ['name' => 'created_at', 'type' => 'timestamp', 'required' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'required' => false],
        ];
    }

    /**
     * Prepare columns for display in views.
     *
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The columns HTML for display
     */
    protected function prepareColumnsForDisplay(array $columns, array $options): string
    {
        // Filter out columns that shouldn't be displayed
        $excludedColumns = $options['exclude_columns'] ?? ['password', 'remember_token', 'deleted_at'];
        $displayColumns = array_filter($columns, function ($column) use ($excludedColumns) {
            return !in_array($column['name'], $excludedColumns);
        });

        $html = '';
        foreach ($displayColumns as $column) {
            $fieldName = $column['name'];
            $fieldLabel = Str::title(str_replace('_', ' ', $fieldName));

            $html .= "<th>{$fieldLabel}</th>\n";
        }

        return $html;
    }

    /**
     * Get searchable columns for the table.
     *
     * @param array $columns The table columns
     * @return array The searchable columns
     */
    protected function getSearchableColumns(array $columns): array
    {
        // Define which column types should be searchable
        $searchableTypes = ['string', 'text', 'char', 'varchar'];

        return array_filter($columns, function ($column) use ($searchableTypes) {
            return in_array($column['type'], $searchableTypes);
        });
    }

    /**
     * Get sortable columns for the table.
     *
     * @param array $columns The table columns
     * @return array The sortable columns
     */
    protected function getSortableColumns(array $columns): array
    {
        // Most columns should be sortable except some specific types
        $nonSortableTypes = ['json', 'blob'];

        return array_filter($columns, function ($column) use ($nonSortableTypes) {
            return !in_array($column['type'], $nonSortableTypes);
        });
    }

    /**
     * Get filterable columns for the table.
     *
     * @param array $columns The table columns
     * @return array The filterable columns
     */
    protected function getFilterableColumns(array $columns): array
    {
        // Define which column types should be filterable
        $filterableTypes = ['string', 'text', 'integer', 'boolean', 'date', 'datetime', 'timestamp'];
        $excludedColumns = ['id', 'created_at', 'updated_at', 'deleted_at'];

        return array_filter($columns, function ($column) use ($filterableTypes, $excludedColumns) {
            return in_array($column['type'], $filterableTypes) && !in_array($column['name'], $excludedColumns);
        });
    }

    /**
     * Map database column type to HTML input type.
     *
     * @param string $columnType The database column type
     * @return string The HTML input type
     */
    protected function mapColumnTypeToInputType(string $columnType): string
    {
        $typeMap = [
            'string' => 'text',
            'text' => 'textarea',
            'integer' => 'number',
            'bigInteger' => 'number',
            'boolean' => 'checkbox',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'timestamp' => 'datetime-local',
            'time' => 'time',
            'email' => 'email',
            'password' => 'password',
            'url' => 'url',
            'tel' => 'tel',
            'file' => 'file',
            'decimal' => 'number',
            'float' => 'number',
            'double' => 'number',
        ];

        return $typeMap[$columnType] ?? 'text';
    }

    /**
     * Generate form fields for a view.
     *
     * @param array $columns The table columns
     * @param array $options Options for form generation
     * @param bool $isEdit Whether the form is for editing
     * @return string The form fields HTML
     */
    protected function generateFormFields(array $columns, array $options, bool $isEdit = false): string
    {
        // Filter out columns that shouldn't be in forms
        $excludedColumns = $options['exclude_form_fields'] ?? [
            'id',
            'created_at',
            'updated_at',
            'deleted_at'
        ];

        $formColumns = array_filter($columns, function ($column) use ($excludedColumns) {
            return !in_array($column['name'], $excludedColumns);
        });

        $html = '';
        foreach ($formColumns as $column) {
            $fieldName = $column['name'];
            $fieldLabel = Str::title(str_replace('_', ' ', $fieldName));
            $fieldType = $this->mapColumnTypeToInputType($column['type']);
            $required = $column['required'] ?? false;
            $requiredAttr = $required ? 'required' : '';
            $oldValueBlade = "{{ old('{$fieldName}'" . ($isEdit ? ", \${$this->stringHelper->getVariableName($options['model'] ?? 'model')}->{$fieldName})" : ")");

            if ($fieldType === 'textarea') {
                $html .= <<<HTML
<div class="mb-3">
    <label for="{$fieldName}" class="form-label">{$fieldLabel}</label>
    <textarea class="form-control @error('{$fieldName}') is-invalid @enderror" id="{$fieldName}" name="{$fieldName}" rows="3" {$requiredAttr}>{$oldValueBlade}</textarea>
    @error('{$fieldName}')
        <div class="invalid-feedback">{{ \$message }}</div>
    @enderror
</div>

HTML;
            } elseif ($fieldType === 'checkbox') {
                $checkedBlade = $isEdit ? "{{ old('{$fieldName}', \${$this->stringHelper->getVariableName($options['model'] ?? 'model')}->{$fieldName}) ? 'checked' : '' }}" : "{{ old('{$fieldName}') ? 'checked' : '' }}";

                $html .= <<<HTML
<div class="form-check mb-3">
    <input type="checkbox" class="form-check-input @error('{$fieldName}') is-invalid @enderror" id="{$fieldName}" name="{$fieldName}" value="1" {$checkedBlade} {$requiredAttr}>
    <label class="form-check-label" for="{$fieldName}">{$fieldLabel}</label>
    @error('{$fieldName}')
        <div class="invalid-feedback">{{ \$message }}</div>
    @enderror
</div>

HTML;
            } else {
                $html .= <<<HTML
<div class="mb-3">
    <label for="{$fieldName}" class="form-label">{$fieldLabel}</label>
    <input type="{$fieldType}" class="form-control @error('{$fieldName}') is-invalid @enderror" id="{$fieldName}" name="{$fieldName}" value="{$oldValueBlade}" {$requiredAttr}>
    @error('{$fieldName}')
        <div class="invalid-feedback">{{ \$message }}</div>
    @enderror
</div>

HTML;
            }
        }

        return $html;
    }

    /**
     * Generate relationship form fields.
     *
     * @param array $relationships The table relationships
     * @param bool $isEdit Whether the form is for editing
     * @return string The relationship form fields HTML
     */
    protected function generateRelationshipFormFields(array $relationships, bool $isEdit = false): string
    {
        $html = '';
        foreach ($relationships as $relationship) {
            $type = $relationship['type'] ?? '';
            $relatedTable = $relationship['related_table'] ?? '';
            $relationName = $relationship['name'] ?? Str::camel($relatedTable);

            if (empty($relatedTable)) {
                continue;
            }

            $relatedModel = Str::studly(Str::singular($relatedTable));
            $fieldName = $type === 'belongsTo' ? Str::snake($relationName) . '_id' : $relationName;
            $fieldLabel = Str::title(str_replace('_', ' ', Str::snake($relationName)));
            $relatedModelNamespace = $relationship['related_model_namespace'] ?? 'App\\Models';

            if ($type === 'belongsTo') {
                // For belongsTo relationships, create a select dropdown
                $html .= <<<HTML
                    <div class="mb-3">
                        <label for="{$fieldName}" class="form-label">{$fieldLabel}</label>
                        <select class="form-select @error('{$fieldName}') is-invalid @enderror" id="{$fieldName}" name="{$fieldName}">
                            <option value="">Select {$relatedModel}</option>
                            @foreach(\\{$relatedModelNamespace}\{$relatedModel}::all() as \$item)
                                <option value="{{ \$item->id }}" {{ old('{$fieldName}'" . ($isEdit ? ", \${$this->stringHelper->getVariableName($relationship['model'] ?? 'model')}->{$fieldName})" : ")") . " == \$item->id ? 'selected' : '' }}>{{ \$item->name ?? \$item->title ?? \$item->id }}</option>
                            @endforeach
                        </select>
                        @error('{$fieldName}')
                            <div class="invalid-feedback">{{ \$message }}</div>
                        @enderror
                    </div>

                    HTML;
            } elseif ($type === 'belongsToMany') {
                // For belongsToMany relationships, create a multi-select dropdown
                $html .= <<<HTML
                <div class="mb-3">
                    <label for="{$relationName}" class="form-label">{$fieldLabel}</label>
                    <select class="form-select @error('{$relationName}') is-invalid @enderror" id="{$relationName}" name="{$relationName}[]" multiple>
                    @foreach(\\{$relatedModelNamespace}\{$relatedModel}::all() as \$item)

                            <option value="{{ \$item->id }}" {{ " . ($isEdit ? "in_array(\$item->id, \${$this->stringHelper->getVariableName($relationship['model'] ?? 'model')}->{$relationName}->pluck('id')->toArray()) ? 'selected' : ''" : "''") . " }}>{{ \$item->name ?? \$item->title ?? \$item->id }}</option>
                        @endforeach
                    </select>
                    @error('{$relationName}')
                        <div class="invalid-feedback">{{ \$message }}</div>
                    @enderror
                </div>

                HTML;
            }
        }

        return $html;
    }

    /**
     * Generate detail fields for show view.
     *
     * @param array $columns The table columns
     * @param array $options Options for view generation
     * @return string The detail fields HTML
     */
    protected function generateDetailFields(array $columns, array $options): string
    {
        // Filter out columns that shouldn't be displayed
        $excludedColumns = $options['exclude_show_fields'] ?? ['password', 'remember_token'];

        $detailColumns = array_filter($columns, function ($column) use ($excludedColumns) {
            return !in_array($column['name'], $excludedColumns);
        });

        $html = '<div class="table-responsive"><table class="table table-bordered">';

        foreach ($detailColumns as $column) {
            $fieldName = $column['name'];
            $fieldLabel = Str::title(str_replace('_', ' ', $fieldName));
            $fieldType = $column['type'] ?? 'string';

            $html .= <<<HTML
    <tr>
        <th style="width: 30%">{$fieldLabel}</th>
        <td>
HTML;

            // Format the value based on field type
            if ($fieldType === 'boolean') {
                $html .= <<<HTML
            @if(\${{modelVariable}}->{$fieldName})
                <span class="badge bg-success">Yes</span>
            @else
                <span class="badge bg-danger">No</span>
            @endif
HTML;
            } elseif (in_array($fieldType, ['datetime', 'timestamp', 'date'])) {
                $format = $fieldType === 'date' ? 'M d, Y' : 'M d, Y H:i:s';
                $html .= <<<HTML
            {{ \${{modelVariable}}->{$fieldName} ? \${{modelVariable}}->{$fieldName}->format('$format') : 'N/A' }}
HTML;
            } elseif ($fieldType === 'text') {
                $html .= <<<HTML
            <p>{!! nl2br(e(\${{modelVariable}}->{$fieldName})) !!}</p>
HTML;
            } else {
                $html .= <<<HTML
            {{ \${{modelVariable}}->{$fieldName} ?? 'N/A' }}
HTML;
            }

            $html .= <<<HTML
        </td>
    </tr>
HTML;
        }

        $html .= '</table></div>';

        return $html;
    }

    /**
     * Generate validation errors partial.
     *
     * @return string The validation errors partial content
     */
    protected function generateValidationErrorsPartial(): string
    {
        return <<<'HTML'
@if ($errors->any())
    <div class="alert alert-danger mb-4">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i>
            <span>Please correct the errors below</span>
        </div>
        <ul class="mt-2 mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
HTML;
    }

    /**
     * Generate modal content for the specified action.
     *
     * @param string $table The database table name
     * @param string $action The modal action (create, edit, delete)
     * @return string The modal content
     */
    protected function generateModalContent(string $table, string $action): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);
        $routeName = Str::kebab(Str::plural($table));

        // Common modal structure
        $modalHeader = '';
        $modalBody = '';
        $modalFooter = '';

        // Action-specific content
        if ($action === 'create') {
            $modalHeader = "<h5 class=\"modal-title\">Create New {$modelName}</h5>";
            $modalBody = "@include('{$routeName}.partials._form_fields')";
            $modalFooter = <<<HTML
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Create {$modelName}</button>
HTML;
        } elseif ($action === 'edit') {
            $modalHeader = "<h5 class=\"modal-title\">Edit {$modelName}</h5>";
            $modalBody = "@include('{$routeName}.partials._form_fields')";
            $modalFooter = <<<HTML
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Update {$modelName}</button>
HTML;
        } elseif ($action === 'delete') {
            $modalHeader = "<h5 class=\"modal-title\">Confirm Delete</h5>";
            $modalBody = "<p>Are you sure you want to delete this {$modelName}? This action cannot be undone.</p>";
            $modalFooter = <<<HTML
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-danger">Delete {$modelName}</button>
HTML;
        }

        // Build the complete modal
        return <<<HTML
<div class="modal fade" id="{$action}-modal" tabindex="-1" aria-labelledby="{$action}-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="{$action}-form" method="POST" action="{{ route('{$routeName}.{$action}') }}">
                @csrf
                @if('{$action}' === 'edit' || '{$action}' === 'delete')
                    @method('{$action}' === 'edit' ? 'PUT' : 'DELETE')
                    <input type="hidden" name="id" id="modal-item-id">
                @endif
                
                <div class="modal-header">
                    {$modalHeader}
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {$modalBody}
                </div>
                <div class="modal-footer">
                    {$modalFooter}
                </div>
            </form>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Get the class name for a resource (needed to comply with GeneratorInterface).
     *
     * @param string $table The database table name
     * @return string The class name (not relevant for views)
     */
    public function getClassName(string $table, string $action = ""): string
    {
        // Views don't have classes, but we need this method to comply with the interface
        $modelName = Str::studly(Str::singular($table));
        return "{$modelName}View";
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
     * Get the namespace for generated classes.
     *
     * @return string The namespace for generated classes
     */
    public function getNamespace(): string
    {
        // Views don't have a PHP namespace, so return empty string or null
        return '';
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