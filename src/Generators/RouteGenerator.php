<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * RouteGenerator
 *
 * This class is responsible for generating web and API routes for the application.
 * It creates route definitions for resourceful controllers, custom methods, and
 * supports route configuration options like middleware, prefixes, and model binding.
 *
 * @package SwatTech\Crud\Generators
 */
class RouteGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The controller generator instance.
     *
     * @var ControllerGenerator
     */
    protected $controllerGenerator;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Route configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new RouteGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param ControllerGenerator $controllerGenerator
     */
    public function __construct(StringHelper $stringHelper, ControllerGenerator $controllerGenerator)
    {
        $this->stringHelper = $stringHelper;
        $this->controllerGenerator = $controllerGenerator;

        // Load default configuration options
        $this->options = Config::get('crud.routes', []);
    }

    /**
     * Generate route files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for route generation
     * @return array Array of modified file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Generate Web Routes
        if ($this->options['generate_web_routes'] ?? true) {
            $this->generateRouteFile($table, 'web', $this->options);
        }

        // Generate API Routes
        if ($this->options['generate_api_routes'] ?? true) {
            $this->generateRouteFile($table, 'api', $this->options);
        }

        return $this->generatedFiles;
    }

    /**
     * Generate a route file for the specified table and type.
     *
     * @param string $table The database table name
     * @param string $type The type of routes (web or api)
     * @param array $options Options for route generation
     * @return string The generated/modified file path
     */
    protected function generateRouteFile(string $table, string $type, array $options): string
    {
        $filePath = $this->getPath($type);
        $routeContent = $this->buildRoutes($table, $type, $options);
        
        // Check if file exists
        if (file_exists($filePath)) {
            // Don't duplicate routes if they already exist
            $currentContent = file_get_contents($filePath);
            if (!$this->routesExist($currentContent, $table, $type)) {
                // Append to the file if it doesn't contain the routes yet
                file_put_contents(
                    $filePath,
                    $currentContent . "\n\n" . $routeContent
                );
            }
        } else {
            // Create the file with a basic structure and the routes
            $stub = $this->getStub($type);
            $content = str_replace('{{routes}}', $routeContent, $stub);
            
            // Create directory if it doesn't exist
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            file_put_contents($filePath, $content);
        }

        $this->generatedFiles[] = $filePath;

        return $filePath;
    }

    /**
     * Get the file path for the route file.
     *
     * @param string $type The type of routes (web or api)
     * @return string The route file path
     */
    public function getPath(string $type = 'web'): string
    {
        if ($type === 'api') {
            return base_path('routes/api.php');
        }

        return base_path('routes/web.php');
    }

    /**
     * Get the stub template content for route generation.
     *
     * @param string $type The type of routes (web or api)
     * @return string The stub template content
     */
    public function getStub(string $type = 'web'): string
    {
        $stubName = $type === 'api' ? 'api_routes.stub' : 'web_routes.stub';
        $customStubPath = resource_path("stubs/crud/{$stubName}");

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . "/../stubs/{$stubName}");
    }

    /**
     * Build routes content for the specified table and type.
     *
     * @param string $table The database table name
     * @param string $type The type of routes (web or api)
     * @param array $options Options for route generation
     * @return string The generated routes content
     */
    public function buildRoutes(string $table, string $type, array $options): string
    {
        $routeContent = "// {$table} routes\n";
        
        // Setup route groups if needed
        $groupStart = $this->createRouteGroups($options, $type);
        if (!empty($groupStart)) {
            $routeContent .= $groupStart . "\n";
        }

        // Generate controller name
        $controllerClass = $this->controllerGenerator->getClassName($table);
        if ($type === 'api') {
            $controllerNamespace = $this->controllerGenerator->getNamespace() . '\\Api';
            $controllerClass = 'Api' . $controllerClass;
        } else {
            $controllerNamespace = $this->controllerGenerator->getNamespace();
        }
        $controllerFQCN = $controllerNamespace . '\\' . $controllerClass;
        
        // Generate resourceful routes
        $resourceRoutes = $this->generateResourcefulRoutes($table, $controllerFQCN);
        $routeContent .= $resourceRoutes;

        // Setup nested routes if relationships are provided
        if (isset($options['relationships']) && !empty($options['relationships'])) {
            $routeContent .= "\n" . $this->setupNestedRoutes($table, $options['relationships'], $type);
        }

        // Generate custom routes
        if (isset($options['custom_methods']) && !empty($options['custom_methods'])) {
            $routeContent .= "\n" . $this->generateCustomRouteMethods($table, $options['custom_methods'], $controllerFQCN, $type);
        }

        // Close any open groups
        $groupEnd = $this->getRouteGroupClosing($options);
        if (!empty($groupEnd)) {
            $routeContent .= "\n" . $groupEnd;
        }

        return $routeContent;
    }

    /**
     * Generate resourceful routes for the specified table and controller.
     *
     * @param string $table The database table name
     * @param string $controller The controller class name (fully qualified)
     * @return string The resourceful routes code
     */
    public function generateResourcefulRoutes(string $table, string $controller): string
    {
        $resourceName = Str::kebab(Str::plural($table));
        $modelVariable = Str::camel(Str::singular($table));
        
        // Setup route model binding if enabled
        $routeModelBinding = '';
        if ($this->options['use_route_model_binding'] ?? true) {
            $modelClass = Str::studly(Str::singular($table));
            $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
            $routeModelBinding = "->whereNumber('{$modelVariable}')";
        }
        
        // Determine if we should use API resource naming
        $apiResource = (isset($this->options['route_type']) && $this->options['route_type'] === 'api');
        $routeMethod = $apiResource ? 'apiResource' : 'resource';
        
        // Generate route name if needed
        $routeNaming = '';
        if ($this->options['use_route_naming'] ?? true) {
            $routeNaming = "->name('{$resourceName}')";
        }
        
        // Determine route methods to exclude
        $excludeMethods = '';
        if (isset($this->options['exclude_methods']) && !empty($this->options['exclude_methods'])) {
            $methods = array_map(function ($method) {
                return "'{$method}'";
            }, $this->options['exclude_methods']);
            
            $excludeMethods = "->except([" . implode(', ', $methods) . "])";
        }

        return "Route::{$routeMethod}('{$resourceName}', {$controller}::class){$routeModelBinding}{$routeNaming}{$excludeMethods};";
    }

    /**
     * Setup nested routes for related resources.
     *
     * @param string $table The parent table name
     * @param array $relationships The relationships configuration
     * @param string $type The type of routes (web or api)
     * @return string The nested routes code
     */
    public function setupNestedRoutes(string $table, array $relationships, string $type = 'web'): string
    {
        $routeContent = '';
        $parentResource = Str::kebab(Str::plural($table));
        $parentVariable = Str::camel(Str::singular($table));
        
        foreach ($relationships as $relation => $config) {
            if (!isset($config['type']) || !isset($config['table'])) {
                continue;
            }
            
            $childTable = $config['table'];
            $childResource = Str::kebab(Str::plural($childTable));
            $childVariable = Str::camel(Str::singular($childTable));
            
            // Generate controller name for the child resource
            $controllerClass = $this->controllerGenerator->getClassName($childTable);
            if ($type === 'api') {
                $controllerNamespace = $this->controllerGenerator->getNamespace() . '\\Api';
                $controllerClass = 'Api' . $controllerClass;
            } else {
                $controllerNamespace = $this->controllerGenerator->getNamespace();
            }
            $controllerFQCN = $controllerNamespace . '\\' . $controllerClass;
            
            // Determine if we should use API resource naming
            $apiResource = ($type === 'api');
            $routeMethod = $apiResource ? 'apiResource' : 'resource';
            
            // Route model binding
            $routeModelBinding = '';
            if ($this->options['use_route_model_binding'] ?? true) {
                $routeModelBinding = "->whereNumber('{$parentVariable}')->whereNumber('{$childVariable}')";
            }
            
            // Route naming
            $routeNaming = '';
            if ($this->options['use_route_naming'] ?? true) {
                $routeNaming = "->name('{$parentResource}.{$childResource}')";
            }
            
            $routeContent .= "\nRoute::{$routeMethod}('{$parentResource}/{{{$parentVariable}}}/{$childResource}', {$controllerFQCN}::class){$routeModelBinding}{$routeNaming};";
        }
        
        return $routeContent;
    }

    /**
     * Implement API versioning in routes.
     *
     * @param array $options API versioning options
     * @return string The API versioning route group code
     */
    public function implementApiVersioning(array $options): string
    {
        if (!isset($options['api_versions']) || empty($options['api_versions'])) {
            return '';
        }
        
        $versionGroups = '';
        foreach ($options['api_versions'] as $version) {
            $versionGroups .= "Route::prefix('v{$version}')->group(function () {\n    // Version {$version} routes\n    {{routes}}\n});\n\n";
        }
        
        return $versionGroups;
    }

    /**
     * Setup route model binding for the specified table.
     *
     * @param string $table The database table name
     * @return string The route model binding code
     */
    public function setupRouteModelBinding(string $table): string
    {
        $modelVariable = Str::camel(Str::singular($table));
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        
        return "->whereNumber('{$modelVariable}')";
    }

    /**
     * Setup route naming for the specified table and type.
     *
     * @param string $table The database table name
     * @param string $type The type of routes (web or api)
     * @return string The route naming code
     */
    public function setupRouteNaming(string $table, string $type): string
    {
        $resourceName = Str::kebab(Str::plural($table));
        $prefix = $type === 'api' ? 'api.' : '';
        
        return "->name('{$prefix}{$resourceName}')";
    }

    /**
     * Assign middleware to routes.
     *
     * @param string $type The type of routes (web or api)
     * @param array $middleware The middleware to assign
     * @return string The middleware assignment code
     */
    public function assignMiddleware(string $type, array $middleware): string
    {
        if (empty($middleware)) {
            return '';
        }
        
        $middlewareList = array_map(function ($middleware) {
            return "'{$middleware}'";
        }, $middleware);
        
        return "->middleware([" . implode(', ', $middlewareList) . "])";
    }

    /**
     * Create route groups based on provided options.
     *
     * @param array $options The group options
     * @param string $type The type of routes (web or api)
     * @return string The route group opening code
     */
    public function createRouteGroups(array $options, string $type = 'web'): string
    {
        $groupStart = '';
        
        // Middleware group
        if (isset($options['middleware']) && !empty($options['middleware'])) {
            $middleware = $this->assignMiddleware($type, $options['middleware']);
            $groupStart .= "Route::middleware([" . implode(', ', array_map(function ($middleware) {
                return "'{$middleware}'";
            }, $options['middleware'])) . "])->group(function () {\n";
        }
        
        // Prefix group
        if (isset($options['prefix']) && !empty($options['prefix'])) {
            $prefix = $options['prefix'];
            if (empty($groupStart)) {
                $groupStart .= "Route::prefix('{$prefix}')->group(function () {\n";
            } else {
                $groupStart = substr($groupStart, 0, -3);
                $groupStart .= "->prefix('{$prefix}')->group(function () {\n";
            }
        }
        
        // Domain group
        if (isset($options['domain']) && !empty($options['domain'])) {
            $domain = $options['domain'];
            if (empty($groupStart)) {
                $groupStart .= "Route::domain('{$domain}')->group(function () {\n";
            } else {
                $groupStart = substr($groupStart, 0, -3);
                $groupStart .= "->domain('{$domain}')->group(function () {\n";
            }
        }
        
        // API versioning for API routes
        if ($type === 'api' && isset($options['api_version']) && !empty($options['api_version'])) {
            $version = $options['api_version'];
            if (empty($groupStart)) {
                $groupStart .= "Route::prefix('v{$version}')->group(function () {\n";
            } else {
                $groupStart = substr($groupStart, 0, -3);
                $groupStart .= "->prefix('v{$version}')->group(function () {\n";
            }
        }
        
        // Add indentation to the group content
        if (!empty($groupStart)) {
            return $groupStart;
        }
        
        return '';
    }

    /**
     * Generate custom route methods for the specified table.
     *
     * @param string $table The database table name
     * @param array $customMethods The custom methods to generate routes for
     * @param string $controller The controller class name
     * @param string $type The type of routes (web or api)
     * @return string The custom route methods code
     */
    public function generateCustomRouteMethods(string $table, array $customMethods, string $controller, string $type = 'web'): string
    {
        $routeContent = '';
        $resourceName = Str::kebab(Str::plural($table));
        $modelVariable = Str::camel(Str::singular($table));
        
        foreach ($customMethods as $method => $config) {
            $httpMethod = $config['http_method'] ?? 'get';
            $path = $config['path'] ?? $method;
            $action = $config['action'] ?? $method;
            $name = $config['name'] ?? "{$resourceName}.{$method}";
            $middleware = isset($config['middleware']) ? $this->assignMiddleware($type, $config['middleware']) : '';
            
            // Determine if it's a resource-specific route
            $resourcePath = '';
            if ($config['resource_specific'] ?? false) {
                $resourcePath = "/{{{$modelVariable}}}";
            }
            
            $routeContent .= "\nRoute::{$httpMethod}('{$resourceName}{$resourcePath}/{$path}', [{$controller}::class, '{$action}'])->name('{$name}'){$middleware};";
        }
        
        return $routeContent;
    }

    /**
     * Setup API prefix and domain for API routes.
     *
     * @param array $options The API options
     * @return string The API prefix and domain code
     */
    public function setupApiPrefixAndDomain(array $options): string
    {
        $groupCode = '';
        
        if (isset($options['api_prefix']) && !empty($options['api_prefix'])) {
            $prefix = $options['api_prefix'];
            $groupCode .= "->prefix('{$prefix}')";
        }
        
        if (isset($options['api_domain']) && !empty($options['api_domain'])) {
            $domain = $options['api_domain'];
            $groupCode .= "->domain('{$domain}')";
        }
        
        if (!empty($groupCode)) {
            return "Route" . $groupCode . "->group(function () {\n    {{routes}}\n});";
        }
        
        return '';
    }

    /**
     * Setup route caching configuration.
     *
     * @return string The route caching command
     */
    public function setupRouteCaching(): string
    {
        return "php artisan route:cache";
    }

    /**
     * Check if routes for the specified table already exist in content.
     *
     * @param string $content The current file content
     * @param string $table The database table name
     * @param string $type The type of routes (web or api)
     * @return bool Whether the routes exist
     */
    protected function routesExist(string $content, string $table, string $type): bool
    {
        $resourceName = Str::kebab(Str::plural($table));
        $controllerClass = $this->controllerGenerator->getClassName($table);
        
        if ($type === 'api') {
            $controllerClass = 'Api' . $controllerClass;
        }
        
        // Check for the presence of the resource route
        return Str::contains($content, "Route::resource('{$resourceName}'") || 
               Str::contains($content, "Route::apiResource('{$resourceName}'");
    }
    
    /**
     * Get the closing code for route groups.
     *
     * @param array $options The group options
     * @return string The route group closing code
     */
    protected function getRouteGroupClosing(array $options): string
    {
        $groupCount = 0;
        
        if (isset($options['middleware']) && !empty($options['middleware'])) {
            $groupCount++;
        }
        
        if (isset($options['prefix']) && !empty($options['prefix'])) {
            $groupCount++;
        }
        
        if (isset($options['domain']) && !empty($options['domain'])) {
            $groupCount++;
        }
        
        if (isset($options['api_version']) && !empty($options['api_version'])) {
            $groupCount++;
        }
        
        if ($groupCount > 0) {
            return str_repeat("});", $groupCount);
        }
        
        return '';
    }
    
    /**
     * Get the class name for a resource (not used, but required by interface).
     *
     * @param string $table The database table name
     * @return string The class name (empty for routes)
     */
    public function getClassName(string $table): string
    {
        // Routes don't have a class name, but we need this for the interface
        return '';
    }
    
    /**
     * Get the namespace for routes (not used, but required by interface).
     *
     * @return string The namespace (empty for routes)
     */
    public function getNamespace(): string
    {
        // Routes don't have a namespace, but we need this for the interface
        return '';
    }
}