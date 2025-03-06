<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * ControllerGenerator
 *
 * This class is responsible for generating controller classes for the application.
 * Controllers handle HTTP requests and return appropriate responses, acting as the
 * interface between the HTTP layer and the application's business logic.
 *
 * @package SwatTech\Crud\Generators
 */
class ControllerGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The service generator instance.
     *
     * @var ServiceGenerator
     */
    protected $serviceGenerator;

    /**
     * The request generator instance.
     *
     * @var RequestGenerator
     */
    protected $requestGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Controller configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new ControllerGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param ServiceGenerator $serviceGenerator
     * @param RequestGenerator $requestGenerator
     */
    public function __construct(StringHelper $stringHelper, ServiceGenerator $serviceGenerator, RequestGenerator $requestGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->serviceGenerator = $serviceGenerator;
        $this->requestGenerator = $requestGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.controllers', []);
    }

    /**
     * Generate controller files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for controller generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Generate the controller
        $filePath = $this->generateController($table, $this->options);

        // If API controller is requested, generate that too
        if ($this->options['generate_api_controller'] ?? false) {
            $this->generateApiController($table, $this->options);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the controller.
     *
     * @param string $table The database table name
     * @return string The controller class name
     */
    public function getClassName(string $table): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Controller';
    }

    /**
     * Get the namespace for the controller.
     *
     * @return string The controller namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.controllers', 'App\\Http\\Controllers');
    }

    /**
     * Get the file path for the controller.
     *
     * @return string The controller file path
     */
    public function getPath(): string
    {
        return base_path(Config::get('crud.paths.controllers', 'app/Http/Controllers'));
    }

    /**
     * Get the stub template content for controller generation.
     *
     * @param string $type The type of controller (web or api)
     * @return string The stub template content
     */
    public function getStub(string $type = 'web'): string
    {
        $stubName = $type === 'api' ? 'api_controller.stub' : 'controller.stub';
        $customStubPath = resource_path("stubs/crud/{$stubName}");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}");
    }

    /**
     * Generate a controller file for the specified table.
     *
     * @param string $table The database table name
     * @param array $options Options for controller generation
     * @return string The generated file path
     */
    protected function generateController(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $content = $this->buildClass($table, $options);

        $filePath = $this->getPath() . '/' . $className . '.php';

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
     * Generate an API controller file for the specified table.
     *
     * @param string $table The database table name
     * @param array $options Options for API controller generation
     * @return string The generated file path
     */
    protected function generateApiController(string $table, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $className = 'Api' . $modelName . 'Controller';
        
        $apiOptions = array_merge($options, ['is_api' => true]);
        $content = $this->buildClass($table, $apiOptions, 'api');

        $namespace = $this->getNamespace() . '\\Api';
        $filePath = $this->getPath() . '/Api/' . $className . '.php';

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
     * Build the controller class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for controller generation
     * @param string $type The type of controller (web or api)
     * @return string The generated controller content
     */
    public function buildClass(string $table, array $options, string $type = 'web'): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);
        
        if ($type === 'api') {
            $className = 'Api' . $modelName . 'Controller';
            $namespace = $this->getNamespace() . '\\Api';
        } else {
            $className = $this->getClassName($table);
            $namespace = $this->getNamespace();
        }
        
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $serviceClass = $modelName . 'Service';
        $serviceNamespace = Config::get('crud.namespaces.services', 'App\\Services');
        
        $stub = $this->getStub($type);

        // Setup service injection
        $serviceInjection = $this->setupServiceInjection($serviceClass);

        // Setup request validation
        $requestValidation = $this->setupRequestValidation([
            'store' => $modelName . 'StoreRequest',
            'update' => $modelName . 'UpdateRequest',
        ]);

        // Generate authorization checks
        $authorization = $this->setupAuthorizationChecks();

        // Generate view rendering methods (for web controllers)
        $viewRendering = $type === 'web' ? $this->generateViewRenderingMethods($table) : '';

        // Setup form processing
        $formProcessing = $this->setupFormProcessing();

        // Generate redirect and response logic
        $redirectLogic = $this->generateRedirectAndResponseLogic($type);

        // Setup flash messages (for web controllers)
        $flashMessages = $type === 'web' ? $this->setupFlashMessages() : '';

        // Generate CRUD methods
        $crudMethods = $this->generateCrudMethods($table, $type, $options);

        // Setup transaction handling
        $transactionHandling = $this->setupTransactionHandling();

        // Setup error handling
        $errorHandling = $this->setupErrorHandling($type);

        // Prepare imports
        $requestNamespace = Config::get('crud.namespaces.requests', 'App\\Http\\Requests');
        $imports = $this->getRequiredImports($type, $modelNamespace, $modelName, $serviceNamespace, $serviceClass, $requestNamespace);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{serviceInjection}}',
            '{{requestValidation}}',
            '{{authorization}}',
            '{{viewRendering}}',
            '{{formProcessing}}',
            '{{redirectLogic}}',
            '{{flashMessages}}',
            '{{crudMethods}}',
            '{{transactionHandling}}',
            '{{errorHandling}}',
            '{{modelVariable}}',
            '{{modelClass}}',
        ], [
            $namespace,
            $imports,
            $className,
            $serviceInjection,
            $requestValidation,
            $authorization,
            $viewRendering,
            $formProcessing,
            $redirectLogic,
            $flashMessages,
            $crudMethods,
            $transactionHandling,
            $errorHandling,
            $modelVariable,
            $modelName,
        ], $stub);
    }

    /**
     * Setup service injection for the controller.
     *
     * @param string $serviceClass The service class name
     * @return string The service injection code
     */
    public function setupServiceInjection(string $serviceClass): string
    {
        return "    /**
     * The {$serviceClass} instance.
     *
     * @var \\App\\Services\\{$serviceClass}
     */
    protected \$service;

    /**
     * Create a new controller instance.
     *
     * @param  \\App\\Services\\{$serviceClass}  \$service
     * @return void
     */
    public function __construct({$serviceClass} \$service)
    {
        \$this->service = \$service;
        \$this->middleware('auth')->except(['index', 'show']);
    }";
    }

    /**
     * Setup request validation for the controller.
     *
     * @param array $requestClasses The request classes for different actions
     * @return string The request validation code
     */
    public function setupRequestValidation(array $requestClasses): string
    {
        $validationCode = "";
        
        if (!empty($requestClasses)) {
            $validationCode = "    /**
     * Get the appropriate request class for the given action.
     *
     * @param  string  \$action
     * @return string
     */
    protected function getRequestClass(string \$action): string
    {
        return [
";
            foreach ($requestClasses as $action => $requestClass) {
                $validationCode .= "            '{$action}' => {$requestClass}::class,\n";
            }
            $validationCode .= "        ][\$action] ?? FormRequest::class;
    }";
        }
        
        return $validationCode;
    }

    /**
     * Setup authorization checks for the controller.
     *
     * @return string The authorization checks code
     */
    public function setupAuthorizationChecks(): string
    {
        return "    /**
     * Authorize the given action for the current user.
     *
     * @param  string  \$ability
     * @param  mixed  \$arguments
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeAction(\$ability, \$arguments = [])
    {
        \$this->authorize(\$ability, \$arguments);
    }";
    }

    /**
     * Generate view rendering methods for the controller.
     *
     * @param string $table The database table name
     * @return string The view rendering code
     */
    public function generateViewRenderingMethods(string $table): string
    {
        $viewPrefix = Str::kebab(Str::plural($table));
        
        return "    /**
     * Get the view for the given action.
     *
     * @param  string  \$action
     * @param  array  \$data
     * @return \Illuminate\View\View
     */
    protected function getView(string \$action, array \$data = [])
    {
        return view('{$viewPrefix}.' . \$action, \$data);
    }";
    }

    /**
     * Setup form processing code for the controller.
     *
     * @return string The form processing code
     */
    public function setupFormProcessing(): string
    {
        return "    /**
     * Process form input for storing or updating.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return array
     */
    protected function processInput(\$request): array
    {
        \$data = \$request->validated();
        
        // Process file uploads if any exist
        if (\$request->hasFile('file')) {
            \$data['file_path'] = \$this->processFileUpload(\$request->file('file'));
        }
        
        return \$data;
    }
    
    /**
     * Process file upload.
     *
     * @param  \Illuminate\Http\UploadedFile  \$file
     * @return string
     */
    protected function processFileUpload(\$file): string
    {
        \$path = \$file->store('uploads', 'public');
        return \$path;
    }";
    }

    /**
     * Generate redirect and response logic for the controller.
     *
     * @param string $type The type of controller (web or api)
     * @return string The redirect and response code
     */
    public function generateRedirectAndResponseLogic(string $type): string
    {
        if ($type === 'api') {
            return "    /**
     * Return a successful JSON response.
     *
     * @param  mixed  \$data
     * @param  string  \$message
     * @param  int  \$code
     * @return \Illuminate\Http\JsonResponse
     */
    protected function successResponse(\$data, string \$message = '', int \$code = 200)
    {
        return response()->json([
            'success' => true,
            'data' => \$data,
            'message' => \$message,
        ], \$code);
    }
    
    /**
     * Return an error JSON response.
     *
     * @param  string  \$message
     * @param  int  \$code
     * @param  array  \$errors
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse(string \$message = '', int \$code = 400, array \$errors = [])
    {
        \$response = [
            'success' => false,
            'message' => \$message,
        ];
        
        if (!empty(\$errors)) {
            \$response['errors'] = \$errors;
        }
        
        return response()->json(\$response, \$code);
    }";
        } else {
            return "    /**
     * Redirect to the index page with a success message.
     *
     * @param  string  \$message
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectToIndex(string \$message = '')
    {
        if (!empty(\$message)) {
            session()->flash('success', \$message);
        }
        
        return redirect()->route('{{modelVariable}}.index');
    }
    
    /**
     * Redirect to the show page with a success message.
     *
     * @param  int  \$id
     * @param  string  \$message
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectToShow(int \$id, string \$message = '')
    {
        if (!empty(\$message)) {
            session()->flash('success', \$message);
        }
        
        return redirect()->route('{{modelVariable}}.show', \$id);
    }
    
    /**
     * Redirect back with an error message.
     *
     * @param  string  \$message
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectBackWithError(string \$message)
    {
        return redirect()->back()
            ->withInput()
            ->withErrors(['error' => \$message]);
    }";
        }
    }

    /**
     * Setup flash messages for the controller.
     *
     * @return string The flash messages code
     */
    public function setupFlashMessages(): string
    {
        return "    /**
     * Flash a success message to the session.
     *
     * @param  string  \$message
     * @return void
     */
    protected function flashSuccess(string \$message)
    {
        session()->flash('success', \$message);
    }
    
    /**
     * Flash an error message to the session.
     *
     * @param  string  \$message
     * @return void
     */
    protected function flashError(string \$message)
    {
        session()->flash('error', \$message);
    }";
    }

    /**
     * Generate CRUD methods for the controller.
     *
     * @param string $table The database table name
     * @param string $type The type of controller (web or api)
     * @param array $options The options for controller generation
     * @return string The CRUD methods code
     */
    public function generateCrudMethods(string $table, string $type, array $options): string
    {
        $modelName = Str::studly(Str::singular($table));
        $modelVariable = Str::camel($modelName);
        $modelPluralVariable = Str::camel(Str::plural($modelName));
        $requestStore = $modelName . 'StoreRequest';
        $requestUpdate = $modelName . 'UpdateRequest';
        
        if ($type === 'api') {
            return "    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        \$filters = request()->get('filter', []);
        \$sorts = request()->get('sort', []);
        \$page = request()->get('page', 1);
        \$perPage = request()->get('per_page', 15);
        
        \$data = \$this->service->getPaginated(\$page, \$perPage, \$filters, \$sorts);
        
        return \$this->successResponse(\$data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \\App\\Http\\Requests\\{$requestStore} \$request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store({$requestStore} \$request)
    {
        \$data = \$this->processInput(\$request);
        
        \$result = \$this->service->create(\$data);
        
        return \$this->successResponse(
            \$result, 
            '{$modelName} created successfully.', 
            201
        );
    }

    /**
     * Display the specified resource.
     *
     * @param  int  \$id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(\$id)
    {
        \$data = \$this->service->findById(\$id);
        
        return \$this->successResponse(\$data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \\App\\Http\\Requests\\{$requestUpdate} \$request
     * @param  int  \$id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update({$requestUpdate} \$request, \$id)
    {
        \$data = \$this->processInput(\$request);
        
        \$result = \$this->service->update(\$id, \$data);
        
        return \$this->successResponse(
            \$result, 
            '{$modelName} updated successfully.'
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  \$id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(\$id)
    {
        \$result = \$this->service->delete(\$id);
        
        return \$this->successResponse(
            null, 
            '{$modelName} deleted successfully.'
        );
    }";
        } else {
            return "    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        \$filters = request()->get('filter', []);
        \$sorts = request()->get('sort', []);
        \$page = request()->get('page', 1);
        \$perPage = request()->get('per_page', 15);
        
        \$data = \$this->service->getPaginated(\$page, \$perPage, \$filters, \$sorts);
        
        return \$this->getView('index', [
            '{$modelPluralVariable}' => \$data,
            'filters' => \$filters,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        \$this->authorizeAction('create', {$modelName}::class);
        
        return \$this->getView('create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \\App\\Http\\Requests\\{$requestStore} \$request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store({$requestStore} \$request)
    {
        \$this->authorizeAction('create', {$modelName}::class);
        
        \$data = \$this->processInput(\$request);
        
        try {
            \$result = \$this->service->create(\$data);
            
            \$this->flashSuccess('{$modelName} created successfully.');
            
            return \$this->redirectToShow(\$result->id);
        } catch (\Exception \$e) {
            return \$this->handleError(\$e, 'Failed to create {$modelVariable}.');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  \$id
     * @return \Illuminate\View\View
     */
    public function show(\$id)
    {
        \$item = \$this->service->findById(\$id);
        
        \$this->authorizeAction('view', \$item);
        
        return \$this->getView('show', [
            '{$modelVariable}' => \$item
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  \$id
     * @return \Illuminate\View\View
     */
    public function edit(\$id)
    {
        \$item = \$this->service->findById(\$id);
        
        \$this->authorizeAction('update', \$item);
        
        return \$this->getView('edit', [
            '{$modelVariable}' => \$item
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \\App\\Http\\Requests\\{$requestUpdate} \$request
     * @param  int  \$id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update({$requestUpdate} \$request, \$id)
    {
        \$item = \$this->service->findById(\$id);
        
        \$this->authorizeAction('update', \$item);
        
        \$data = \$this->processInput(\$request);
        
        try {
            \$this->service->update(\$id, \$data);
            
            \$this->flashSuccess('{$modelName} updated successfully.');
            
            return \$this->redirectToShow(\$id);
        } catch (\Exception \$e) {
            return \$this->handleError(\$e, 'Failed to update {$modelVariable}.');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  \$id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(\$id)
    {
        \$item = \$this->service->findById(\$id);
        
        \$this->authorizeAction('delete', \$item);
        
        try {
            \$this->service->delete(\$id);
            
            \$this->flashSuccess('{$modelName} deleted successfully.');
            
            return \$this->redirectToIndex();
        } catch (\Exception \$e) {
            return \$this->handleError(\$e, 'Failed to delete {$modelVariable}.');
        }
    }";
        }
    }

    /**
     * Setup transaction handling for the controller.
     *
     * @return string The transaction handling code
     */
    public function setupTransactionHandling(): string
    {
        return "    /**
     * Execute a function within a database transaction.
     *
     * @param  \Closure  \$callback
     * @return mixed
     * @throws \Throwable
     */
    protected function transaction(\Closure \$callback)
    {
        return DB::transaction(\$callback);
    }";
    }

    /**
     * Setup error handling for the controller.
     *
     * @param string $type The type of controller (web or api)
     * @return string The error handling code
     */
    public function setupErrorHandling(string $type): string
    {
        if ($type === 'api') {
            return "    /**
     * Handle an error in the API controller.
     *
     * @param  \Exception  \$exception
     * @param  string  \$defaultMessage
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleError(\Exception \$exception, string \$defaultMessage = 'An error occurred.')
    {
        \$message = config('app.debug') ? \$exception->getMessage() : \$defaultMessage;
        \$code = \$this->getErrorCode(\$exception);
        
        if (\$exception instanceof ValidationException) {
            return \$this->errorResponse(
                'Validation failed.',
                422,
                \$exception->errors()
            );
        }
        
        if (\$exception instanceof ModelNotFoundException) {
            return \$this->errorResponse(
                'Resource not found.',
                404
            );
        }
        
        return \$this->errorResponse(\$message, \$code);
    }
    
    /**
     * Get the appropriate error code for an exception.
     *
     * @param  \Exception  \$exception
     * @return int
     */
    protected function getErrorCode(\Exception \$exception): int
    {
        if (\$exception instanceof ValidationException) {
            return 422;
        }
        
        if (\$exception instanceof ModelNotFoundException) {
            return 404;
        }
        
        if (\$exception instanceof AuthorizationException) {
            return 403;
        }
        
        return 500;
    }";
        } else {
            return "    /**
     * Handle an error in the controller.
     *
     * @param  \Exception  \$exception
     * @param  string  \$defaultMessage
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function handleError(\Exception \$exception, string \$defaultMessage = 'An error occurred.')
    {
        \$message = config('app.debug') ? \$exception->getMessage() : \$defaultMessage;
        
        // Log the full error
        \Log::error(\$exception);
        
        if (\$exception instanceof ValidationException) {
            return redirect()->back()
                ->withInput()
                ->withErrors(\$exception->errors());
        }
        
        if (\$exception instanceof ModelNotFoundException) {
            \$this->flashError('Resource not found.');
            return redirect()->route('{{modelVariable}}.index');
        }
        
        if (\$exception instanceof AuthorizationException) {
            \$this->flashError('Unauthorized action.');
            return redirect()->route('{{modelVariable}}.index');
        }
        
        return \$this->redirectBackWithError(\$message);
    }";
        }
    }

    /**
     * Get required imports for the controller.
     *
     * @param string $type The controller type (web or api)
     * @param string $modelNamespace The model namespace
     * @param string $modelName The model name
     * @param string $serviceNamespace The service namespace
     * @param string $serviceClass The service class name
     * @param string $requestNamespace The request namespace
     * @return string The import statements
     */
    protected function getRequiredImports(
        string $type,
        string $modelNamespace,
        string $modelName,
        string $serviceNamespace,
        string $serviceClass,
        string $requestNamespace
    ): string {
        $commonImports = [
            'Illuminate\Http\Request',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Database\Eloquent\ModelNotFoundException',
            'Illuminate\Auth\Access\AuthorizationException',
            'Illuminate\Support\Facades\Log',
            'Illuminate\Support\Facades\DB',
            "{$modelNamespace}\\{$modelName}",
            "{$serviceNamespace}\\{$serviceClass}",
            "{$requestNamespace}\\{$modelName}StoreRequest",
            "{$requestNamespace}\\{$modelName}UpdateRequest",
        ];
        
        if ($type === 'api') {
            $apiImports = [
                'Illuminate\Http\JsonResponse',
                'Illuminate\Validation\ValidationException',
            ];
            $imports = array_merge($commonImports, $apiImports);
        } else {
            $webImports = [
                'Illuminate\View\View',
                'Illuminate\Http\RedirectResponse',
            ];
            $imports = array_merge($commonImports, $webImports);
        }
        
        $imports = array_unique($imports);
        sort($imports);
        
        $importStatements = '';
        foreach ($imports as $import) {
            $importStatements .= "use {$import};\n";
        }
        
        return $importStatements;
    }
}