<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * PolicyGenerator
 *
 * This class is responsible for generating authorization policy classes for the application.
 * Policies are used to determine if a user is authorized to perform a specific action on a resource.
 *
 * @package SwatTech\Crud\Generators
 */
class PolicyGenerator implements GeneratorInterface
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
     * Policy configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new PolicyGenerator instance.
     *
     * @param StringHelper $stringHelper
     */
    public function __construct(StringHelper $stringHelper)
    {
        $this->stringHelper = $stringHelper;

        // Load default configuration options
        $this->options = Config::get('crud.policies', []);
    }

    /**
     * Generate policy files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for policy generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Generate the policy
        $filePath = $this->generatePolicy($table, $this->options);

        // Generate policy provider if needed
        if ($this->options['generate_provider'] ?? false) {
            $this->generatePolicyProvider();
        }

        // Generate resource-specific policies if needed
        if ($this->options['generate_resource_policies'] ?? false) {
            $this->generateResourceSpecificPolicies($table);
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the policy.
     *
     * @param string $table The database table name
     * @return string The policy class name
     */
    public function getClassName(string $table): string
    {
        $modelName = Str::studly(Str::singular($table));
        return $modelName . 'Policy';
    }

    /**
     * Get the namespace for the policy.
     *
     * @return string The policy namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.policies', 'App\\Policies');
    }

    /**
     * Get the file path for the policy.
     *
     * @return string The policy file path
     */
    public function getPath(): string
    {
        return base_path(Config::get('crud.paths.policies', 'app/Policies'));
    }

    /**
     * Get the stub template content for policy generation.
     *
     * @return string The stub template content
     */
    public function getStub(): string
    {
        $customStubPath = resource_path('stubs/crud/policy.stub');

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . '/../stubs/policy.stub');
    }

    /**
     * Generate a policy file for the specified table.
     *
     * @param string $table The database table name
     * @param array $options Options for policy generation
     * @return string The generated file path
     */
    protected function generatePolicy(string $table, array $options): string
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
     * Build the policy class based on options.
     *
     * @param string $table The database table name
     * @param array $options The options for policy generation
     * @return string The generated policy content
     */
    public function buildClass(string $table, array $options): string
    {
        $className = $this->getClassName($table);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $userClass = Config::get('auth.providers.users.model', 'App\\Models\\User');
        $stub = $this->getStub();

        // Generate authorization methods
        $authMethods = $this->generateAuthorizationMethods();

        // Generate role-based authorization
        $roleBasedAuth = $this->setupRoleBasedAuthorization($options['roles'] ?? []);

        // Generate ownership checks
        $ownershipChecks = $this->generateOwnershipChecks($table);

        // Implement ability mapping
        $abilityMapping = $this->implementAbilityMapping();

        // Generate before and after hooks
        $beforeAfterHooks = $this->implementBeforeAndAfterHooks();

        // Generate custom ability methods
        $customAbilities = $this->generateCustomAbilityMethods($options['abilities'] ?? []);

        // In the buildClass() method, add modelVariable to the replacements:
        $modelVariable = Str::camel($modelClass); // Or Str::camel(Str::singular($table))

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{class}}',
            '{{modelNamespace}}',
            '{{modelClass}}',
            '{{modelVariable}}', // Add this line
            '{{userNamespace}}',
            '{{userClass}}',
            '{{authorizationMethods}}',
            '{{roleBasedAuthorization}}',
            '{{ownershipChecks}}',
            '{{abilityMapping}}',
            '{{beforeAfterHooks}}',
            '{{customAbilities}}',
        ], [
            $namespace,
            $className,
            $modelNamespace,
            $modelClass,
            $modelVariable, // Add this line
            Str::beforeLast($userClass, '\\'),
            Str::afterLast($userClass, '\\'),
            $authMethods,
            $roleBasedAuth,
            $ownershipChecks,
            $abilityMapping,
            $beforeAfterHooks,
            $customAbilities,
        ], $stub);
    }

    /**
     * Generate standard authorization methods for policies.
     *
     * @return string The authorization methods code
     */
    public function generateAuthorizationMethods(): string
    {
        return "    /**
     * Determine whether the user can view any models.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(\$user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(\$user, \${{ modelVariable }})
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(\$user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(\$user, \${{ modelVariable }})
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(\$user, \${{ modelVariable }})
    {
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(\$user, \${{ modelVariable }})
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(\$user, \${{ modelVariable }})
    {
        return true;
    }";
    }

    /**
     * Setup role-based authorization for the policy.
     *
     * @param array $roles Array of role definitions
     * @return string The role-based authorization code
     */
    public function setupRoleBasedAuthorization(array $roles): string
    {
        if (empty($roles)) {
            return '';
        }

        $code = "    /**
     * Check if user has the specified role.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  string  \$role
     * @return bool
     */
    protected function hasRole(\$user, string \$role): bool
    {
        // Implement according to your role system
        // Example with a roles relationship:
        // return \$user->roles->contains('name', \$role);
        
        // Example with a direct column:
        // return \$user->role === \$role;
        
        return false;
    }

    /**
     * Check if user has any of the specified roles.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  array  \$roles
     * @return bool
     */
    protected function hasAnyRole(\$user, array \$roles): bool
    {
        foreach (\$roles as \$role) {
            if (\$this->hasRole(\$user, \$role)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user has all of the specified roles.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  array  \$roles
     * @return bool
     */
    protected function hasAllRoles(\$user, array \$roles): bool
    {
        foreach (\$roles as \$role) {
            if (!\$this->hasRole(\$user, \$role)) {
                return false;
            }
        }
        
        return true;
    }\n";

        // Generate role-specific checks
        if (!empty($roles)) {
            $code .= "\n    /**
     * Define role-based permissions for actions.
     *
     * @return array
     */
    protected function rolePermissions(): array
    {
        return [
";
            foreach ($roles as $role => $permissions) {
                $code .= "            '{$role}' => [";
                if (is_array($permissions)) {
                    $code .= "'" . implode("', '", $permissions) . "'";
                }
                $code .= "],\n";
            }
            $code .= "        ];
    }";
        }

        return $code;
    }

    /**
     * Generate ownership checks for the policy.
     *
     * @param string $table The database table name
     * @return string The ownership checks code
     */
    public function generateOwnershipChecks(string $table): string
    {
        $modelVariable = Str::camel(Str::singular($table));

        return "    /**
     * Check if user owns the model.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return bool
     */
    protected function owns(\$user, \${{ modelVariable }}): bool
    {
        // Implement according to your ownership system
        // Example:
        // return \$user->id === \${{ modelVariable }}->user_id;
        
        return false;
    }
    
    /**
     * Check if user is in the same team/group as the model owner.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return bool
     */
    protected function inSameTeamAs(\$user, \${{ modelVariable }}): bool
    {
        // Implement according to your team/group system
        // Example:
        // return \$user->team_id === \${{ modelVariable }}->user->team_id;
        
        return false;
    }";
    }

    /**
     * Implement ability mapping for Laravel's authorization system.
     *
     * @return string The ability mapping code
     */
    public function implementAbilityMapping(): string
    {
        return "    /**
     * Map policy methods to ability names.
     *
     * @return array
     */
    public static function abilities(): array
    {
        return [
            'viewAny' => 'view-any',
            'view' => 'view',
            'create' => 'create',
            'update' => 'update',
            'delete' => 'delete',
            'restore' => 'restore',
            'forceDelete' => 'force-delete',
            // Map custom abilities here
        ];
    }";
    }

    /**
     * Generate a policy provider class.
     *
     * @return string The generated file path
     */
    public function generatePolicyProvider(): string
    {
        $namespace = Config::get('crud.namespaces.providers', 'App\\Providers');
        $className = 'PolicyServiceProvider';
        $policiesNamespace = $this->getNamespace();
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');

        $content = "<?php

namespace {$namespace};

use Illuminate\\Support\\ServiceProvider;
use Illuminate\\Support\\Facades\\Gate;

class {$className} extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        \$this->registerPolicies();
    }
    
    /**
     * Register the application's policies.
     *
     * @return void
     */
    protected function registerPolicies()
    {
        // Register policies for your models here
        // Example:
        // Gate::policy({$modelNamespace}\\Model::class, {$policiesNamespace}\\ModelPolicy::class);
    }
}";

        $filePath = base_path('app/Providers/' . $className . '.php');

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
     * Setup policy registration code.
     *
     * @return string The policy registration code
     */
    public function setupPolicyRegistration(): string
    {
        return "    /**
     * Register the application's policies.
     *
     * @return void
     */
    protected function registerPolicies()
    {
        foreach (\$this->policies() as \$model => \$policy) {
            Gate::policy(\$model, \$policy);
        }
    }
    
    /**
     * Get policy mappings for the application.
     *
     * @return array
     */
    protected function policies(): array
    {
        return [
            // Model::class => ModelPolicy::class,
        ];
    }";
    }

    /**
     * Implement before and after hooks for policies.
     *
     * @return string The before and after hooks code
     */
    public function implementBeforeAndAfterHooks(): string
    {
        return "    /**
     * Perform pre-authorization checks.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  string  \$ability
     * @return \Illuminate\Auth\Access\Response|bool|null
     */
    public function before(\$user, \$ability)
    {
        // Grant all permissions to administrators
        if (\$this->hasRole(\$user, 'admin') || \$this->hasRole(\$user, 'super-admin')) {
            return true;
        }
        
        // Continue to other authorization checks
        return null;
    }
    
    /**
     * Perform post-authorization checks.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  string  \$ability
     * @param  \Illuminate\Auth\Access\Response|bool  \$result
     * @param  mixed  \$arguments
     * @return void
     */
    public function after(\$user, \$ability, \$result, \$arguments)
    {
        // Log authorization attempts if needed
        // This method is rarely used but available for customization
    }";
    }

    /**
     * Generate custom ability methods for the policy.
     *
     * @param array $abilities Array of custom ability definitions
     * @return string The custom ability methods code
     */
    public function generateCustomAbilityMethods(array $abilities): string
    {
        if (empty($abilities)) {
            return '';
        }

        $code = '';

        foreach ($abilities as $ability => $description) {
            $code .= "\n    /**
     * Determine whether the user can {$description}.
     *
     * @param  \{{ userNamespace }}\{{ userClass }}  \$user
     * @param  \{{ modelNamespace }}\{{ modelClass }}  \${{ modelVariable }}
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function {$ability}(\$user, \${{ modelVariable }})
    {
        // Implement your custom authorization logic here
        return true;
    }";
        }

        return $code;
    }

    /**
     * Generate resource-specific policies for the table.
     *
     * @param string $table The database table name
     * @return array Array of generated file paths
     */
    public function generateResourceSpecificPolicies(string $table): array
    {
        $resources = [
            'api' => 'Api' . $this->getClassName($table),
            'admin' => 'Admin' . $this->getClassName($table)
        ];

        $generatedPaths = [];

        foreach ($resources as $type => $className) {
            $content = $this->buildResourceSpecificPolicy($table, $type);
            $filePath = $this->getPath() . '/' . $className . '.php';

            // Create directory if it doesn't exist
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Write the file
            file_put_contents($filePath, $content);
            $this->generatedFiles[] = $filePath;
            $generatedPaths[] = $filePath;
        }

        return $generatedPaths;
    }

    /**
     * Build a resource-specific policy class.
     *
     * @param string $table The database table name
     * @param string $resourceType The resource type (api, admin, etc.)
     * @return string The generated policy content
     */
    protected function buildResourceSpecificPolicy(string $table, string $resourceType): string
    {
        $baseClassName = $this->getClassName($table);
        $className = ucfirst($resourceType) . $baseClassName;
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $userClass = Config::get('auth.providers.users.model', 'App\\Models\\User');

        return "<?php

namespace {$namespace};

use {$modelNamespace}\\{$modelClass};
use " . Str::beforeLast($userClass, '\\') . "\\" . Str::afterLast($userClass, '\\') . ";

/**
 * {$resourceType} specific policy for {$modelClass}
 */
class {$className} extends {$baseClassName}
{
    /**
     * Apply {$resourceType}-specific rules on top of the base policy.
     * 
     * @param User \$user
     * @return \Illuminate\Auth\Access\Response|bool|null
     */
    public function before(\$user, \$ability)
    {
        // Add {$resourceType}-specific authorization logic
        // Example: require API tokens, admin roles, etc.
        
        // Continue with parent checks
        return parent::before(\$user, \$ability);
    }
    
    /**
     * {$resourceType}-specific view any permission.
     *
     * @param User \$user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(\$user)
    {
        // Add {$resourceType}-specific rules
        return parent::viewAny(\$user);
    }
    
    // Override other methods as needed for {$resourceType}-specific behavior
}";
    }
}
