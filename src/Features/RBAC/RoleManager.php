<?php

namespace SwatTech\Crud\Features\RBAC;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use SwatTech\Crud\Services\BaseService;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * RoleManager
 *
 * A service class for managing role-based access control (RBAC) in the application.
 * Handles role creation, permission assignment, role hierarchy, and permission checking.
 *
 * @package SwatTech\Crud\Features\RBAC
 */
class RoleManager extends BaseService
{
    /**
     * The role repository instance.
     *
     * @var RepositoryInterface
     */
    protected $roleRepository;

    /**
     * The permission repository instance.
     *
     * @var RepositoryInterface
     */
    protected $permissionRepository;

    /**
     * The user repository instance.
     *
     * @var RepositoryInterface
     */
    protected $userRepository;

    /**
     * Configuration for RBAC functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Cache for user permissions to avoid repeated database queries.
     *
     * @var array
     */
    protected $userPermissionCache = [];

    /**
     * Create a new RoleManager instance.
     *
     * @param RepositoryInterface $roleRepository
     * @param RepositoryInterface $permissionRepository
     * @param RepositoryInterface $userRepository
     * @return void
     */
    public function __construct(
        RepositoryInterface $roleRepository,
        RepositoryInterface $permissionRepository,
        RepositoryInterface $userRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
        $this->userRepository = $userRepository;
        $this->config = config('crud.features.rbac', [
            'use_cache' => true,
            'cache_ttl' => 60, // minutes
            'strict_mode' => false,
            'dynamic_permissions_enabled' => true,
            'hierarchical_roles' => true,
            'context_based_permissions' => false,
            'default_role' => 'user'
        ]);
    }

    /**
     * Create a new role with the specified name and permissions.
     *
     * @param string $name The name of the role
     * @param array $permissions Array of permission names to assign to the role
     * @param array $attributes Additional attributes for the role (description, display_name, etc.)
     * @return Model The created role
     * 
     * @throws \Exception If the role already exists
     */
    public function createRole(string $name, array $permissions = [], array $attributes = [])
    {
        // Check if the role already exists
        $existingRole = $this->roleRepository->findBy('name', $name);
        if ($existingRole) {
            throw new \Exception("Role '{$name}' already exists.");
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Create the role
            $role = $this->roleRepository->create(array_merge([
                'name' => $name,
                'display_name' => $attributes['display_name'] ?? ucwords(str_replace('_', ' ', $name)),
                'description' => $attributes['description'] ?? null,
            ], $attributes));

            // Assign permissions if provided
            if (!empty($permissions)) {
                $this->assignPermissions($role->id, $permissions);
            }

            DB::commit();
            Log::info("Created role: {$name} with " . count($permissions) . " permissions");

            return $role;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create role: {$name}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Assign permissions to a role.
     *
     * @param int $roleId The ID of the role
     * @param array $permissions Array of permission names to assign
     * @return bool True on success
     * 
     * @throws \Exception If the role doesn't exist or if any permission doesn't exist
     */
    public function assignPermissions(int $roleId, array $permissions)
    {
        // Check if the role exists
        $role = $this->roleRepository->find($roleId);
        if (!$role) {
            throw new \Exception("Role with ID {$roleId} not found.");
        }

        // Get or create permissions
        $permissionIds = [];
        foreach ($permissions as $permissionName) {
            $permission = $this->permissionRepository->findBy('name', $permissionName);

            if (!$permission) {
                if ($this->config['strict_mode']) {
                    throw new \Exception("Permission '{$permissionName}' does not exist.");
                }

                // Create the permission if it doesn't exist and strict mode is off
                $permission = $this->permissionRepository->create([
                    'name' => $permissionName,
                    'display_name' => ucwords(str_replace('_', ' ', $permissionName)),
                    'description' => null
                ]);

                Log::info("Created missing permission: {$permissionName}");
            }

            $permissionIds[] = $permission->id;
        }

        // Sync the permissions
        $this->syncRolePermissions($role, $permissionIds);

        // Clear any cached permissions for users with this role
        $this->clearPermissionCache();

        Log::info("Assigned permissions to role ID {$roleId}", ['permissions' => $permissions]);

        return true;
    }

    /**
     * Set up role inheritance where a child role inherits permissions from a parent role.
     *
     * @param int $childRoleId The ID of the child role
     * @param int $parentRoleId The ID of the parent role
     * @return bool True on success
     * 
     * @throws \Exception If either role doesn't exist or if it would create a circular reference
     */
    public function setupInheritance(int $childRoleId, int $parentRoleId)
    {
        // Check if both roles exist
        $childRole = $this->roleRepository->find($childRoleId);
        $parentRole = $this->roleRepository->find($parentRoleId);

        if (!$childRole || !$parentRole) {
            throw new \Exception('One or both roles do not exist.');
        }

        // Check if this would create a circular reference
        if ($this->wouldCreateCircularReference($childRoleId, $parentRoleId)) {
            throw new \Exception('Creating this inheritance relationship would create a circular reference.');
        }

        // Create the inheritance relationship
        DB::table('role_inheritance')->updateOrInsert([
            'child_role_id' => $childRoleId,
            'parent_role_id' => $parentRoleId
        ], ['created_at' => now()]);

        // Clear any cached permissions
        $this->clearPermissionCache();

        Log::info("Set up role inheritance: Role {$childRoleId} now inherits from Role {$parentRoleId}");

        return true;
    }

    /**
     * Assign a role to a user.
     *
     * @param int $userId The ID of the user
     * @param int $roleId The ID of the role to assign
     * @param bool $maintainExisting Whether to maintain existing roles (default: true)
     * @return bool True on success
     * 
     * @throws \Exception If the user or role doesn't exist
     */
    public function assignUserRole(int $userId, int $roleId, bool $maintainExisting = true)
    {
        // Check if the user exists
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \Exception("User with ID {$userId} not found.");
        }

        // Check if the role exists
        $role = $this->roleRepository->find($roleId);
        if (!$role) {
            throw new \Exception("Role with ID {$roleId} not found.");
        }

        // Assign the role
        if ($maintainExisting) {
            // Add this role to the user's existing roles
            DB::table('user_roles')
                ->updateOrInsert([
                    'user_id' => $userId,
                    'role_id' => $roleId
                ], ['assigned_at' => now()]);
        } else {
            // Replace any existing roles with just this one
            DB::table('user_roles')->where('user_id', $userId)->delete();
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => now()
            ]);
        }

        // Clear cached permissions for this user
        $this->clearPermissionCache($userId);

        Log::info("Assigned role {$roleId} to user {$userId}", [
            'maintained_existing' => $maintainExisting
        ]);

        return true;
    }

    /**
     * Implement context-based role assignments and permissions.
     * This allows role assignments that are specific to a particular context,
     * such as a team, organization, project, etc.
     *
     * @param int $userId The ID of the user
     * @param int $roleId The ID of the role
     * @param string $contextType The type of context (e.g., 'team', 'project')
     * @param int $contextId The ID of the context entity
     * @return bool True on success
     * 
     * @throws \Exception If the feature is not enabled or if required entities don't exist
     */
    public function implementContextBasedRoles(int $userId, int $roleId, string $contextType, int $contextId)
    {
        if (!$this->config['context_based_permissions']) {
            throw new \Exception("Context-based permissions are not enabled in the configuration.");
        }

        // Validate user and role
        $user = $this->userRepository->find($userId);
        $role = $this->roleRepository->find($roleId);

        if (!$user || !$role) {
            throw new \Exception("User or role not found.");
        }

        // Create the context-based role assignment
        DB::table('context_roles')->updateOrInsert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'context_type' => $contextType,
            'context_id' => $contextId
        ], [
            'assigned_at' => now()
        ]);

        // Clear cached permissions for this user
        $this->clearPermissionCache($userId);

        Log::info("Assigned role {$roleId} to user {$userId} in context {$contextType}:{$contextId}");

        return true;
    }

    /**
     * Check if a user has a specific permission, optionally in a particular context.
     *
     * @param int $userId The ID of the user
     * @param string $permission The permission name to check
     * @param mixed $context Optional context (can be context type and ID, or an object)
     * @return bool True if the user has the permission
     */
    public function checkPermission(int $userId, string $permission, $context = null)
    {
        // Check cache first if enabled
        $cacheKey = "user_{$userId}_permission_{$permission}" . ($context ? "_context_" . $this->getContextHash($context) : "");

        if ($this->config['use_cache'] && isset($this->userPermissionCache[$cacheKey])) {
            return $this->userPermissionCache[$cacheKey];
        }

        // Get the user's roles
        $userRoles = $this->getUserRoles($userId, $context);

        if (empty($userRoles)) {
            return false;
        }

        // Check if any of the user's roles have this permission
        $hasPermission = false;

        foreach ($userRoles as $role) {
            // Get role permissions (including inherited ones if hierarchy is enabled)
            $rolePermissions = $this->getRolePermissions($role->id);

            if (in_array($permission, $rolePermissions)) {
                $hasPermission = true;
                break;
            }

            // Check dynamic permissions if enabled
            if ($this->config['dynamic_permissions_enabled']) {
                if ($this->checkDynamicPermission($userId, $role->id, $permission, $context)) {
                    $hasPermission = true;
                    break;
                }
            }
        }

        // Cache the result if caching is enabled
        if ($this->config['use_cache']) {
            $this->userPermissionCache[$cacheKey] = $hasPermission;
        }

        return $hasPermission;
    }

    /**
     * Set up a role hierarchy with multiple levels.
     *
     * @param array $hierarchy Array defining role hierarchy relationships
     *                         e.g. ['admin' => ['manager'], 'manager' => ['user']]
     * @return bool True on success
     * 
     * @throws \Exception If a role in the hierarchy doesn't exist
     */
    public function setupRoleHierarchy(array $hierarchy)
    {
        if (!$this->config['hierarchical_roles']) {
            throw new \Exception("Hierarchical roles are not enabled in the configuration.");
        }

        DB::beginTransaction();

        try {
            // Clear existing hierarchy
            DB::table('role_inheritance')->delete();

            foreach ($hierarchy as $parentRoleName => $childRoles) {
                // Get parent role
                $parentRole = $this->roleRepository->findBy('name', $parentRoleName);
                if (!$parentRole) {
                    throw new \Exception("Role '{$parentRoleName}' does not exist.");
                }

                foreach ($childRoles as $childRoleName) {
                    // Get child role
                    $childRole = $this->roleRepository->findBy('name', $childRoleName);
                    if (!$childRole) {
                        throw new \Exception("Role '{$childRoleName}' does not exist.");
                    }

                    // Create inheritance relationship
                    DB::table('role_inheritance')->insert([
                        'parent_role_id' => $parentRole->id,
                        'child_role_id' => $childRole->id,
                        'created_at' => now()
                    ]);
                }
            }

            DB::commit();

            // Clear permission cache
            $this->clearPermissionCache();

            Log::info("Set up role hierarchy", ['hierarchy' => $hierarchy]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to set up role hierarchy", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Add dynamic permissions that are calculated at runtime based on conditions.
     *
     * @param string $permissionName The name of the dynamic permission
     * @param \Closure $condition A function that returns true if the permission should be granted
     * @return bool True on success
     */
    public function addDynamicPermissions(string $permissionName, \Closure $condition)
    {
        if (!$this->config['dynamic_permissions_enabled']) {
            throw new \Exception("Dynamic permissions are not enabled in the configuration.");
        }

        // Store the dynamic permission condition
        app('rbac.dynamic_permissions')[$permissionName] = $condition;

        Log::info("Added dynamic permission: {$permissionName}");

        return true;
    }

    /**
     * Implement UI integration for role management.
     * 
     * @param string $section The UI section to generate (roles, permissions, assignments)
     * @param array $options Additional options for UI generation
     * @return array UI configuration data for the specified section
     */
    public function implementUiIntegration(string $section = 'all', array $options = [])
    {
        $result = [];

        // Get all roles
        $roles = $this->roleRepository->all();

        // Get all permissions
        $permissions = $this->permissionRepository->all();

        switch ($section) {
            case 'roles':
                $result['roles'] = $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                        'description' => $role->description,
                        'users_count' => DB::table('user_roles')->where('role_id', $role->id)->count(),
                        'permissions_count' => DB::table('role_permissions')->where('role_id', $role->id)->count()
                    ];
                });

                // Include hierarchy if enabled
                if ($this->config['hierarchical_roles']) {
                    $result['hierarchy'] = $this->getRoleHierarchyTree();
                }
                break;

            case 'permissions':
                $result['permissions'] = $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => $permission->display_name,
                        'description' => $permission->description,
                        'roles_count' => DB::table('role_permissions')->where('permission_id', $permission->id)->count()
                    ];
                });

                // Group permissions for better UI organization
                $result['permission_groups'] = $this->groupPermissionsByPrefix($permissions);
                break;

            case 'assignments':
                // Get users with their roles
                $users = $this->userRepository->paginate(
                    $options['page'] ?? 1,
                    $options['per_page'] ?? 25
                );

                $result['users'] = $users->map(function ($user) {
                    $userRoles = DB::table('user_roles')
                        ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                        ->where('user_roles.user_id', $user->id)
                        ->select('roles.id', 'roles.name', 'roles.display_name')
                        ->get();

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $userRoles
                    ];
                });

                $result['pagination'] = [
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage()
                ];

                $result['available_roles'] = $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name
                    ];
                });
                break;

            case 'all':
                // Combine all sections
                $result = array_merge(
                    $this->implementUiIntegration('roles', $options),
                    $this->implementUiIntegration('permissions', $options),
                    $this->implementUiIntegration('assignments', $options)
                );
                break;

            default:
                throw new \Exception("Unknown UI section: {$section}");
        }

        return $result;
    }

    /**
     * Synchronize roles and permissions with an external system.
     *
     * @param array $mapping Mapping configuration for external system integration
     * @return array Results of the synchronization
     */
    public function synchronizeWithExternalSystems(array $mapping)
    {
        $results = [
            'created_roles' => [],
            'updated_roles' => [],
            'created_permissions' => [],
            'updated_permissions' => [],
            'errors' => []
        ];

        DB::beginTransaction();

        try {
            // Process roles from external system
            if (!empty($mapping['roles'])) {
                foreach ($mapping['roles'] as $externalRole) {
                    $roleName = $externalRole['name'];
                    $existingRole = $this->roleRepository->findBy('name', $roleName);

                    if ($existingRole) {
                        // Update existing role
                        $this->roleRepository->update($existingRole->id, [
                            'display_name' => $externalRole['display_name'] ?? $existingRole->display_name,
                            'description' => $externalRole['description'] ?? $existingRole->description,
                            'external_id' => $externalRole['external_id'] ?? $existingRole->external_id
                        ]);

                        $results['updated_roles'][] = $roleName;
                    } else {
                        // Create new role
                        $role = $this->createRole($roleName, [], [
                            'display_name' => $externalRole['display_name'] ?? ucwords(str_replace('_', ' ', $roleName)),
                            'description' => $externalRole['description'] ?? null,
                            'external_id' => $externalRole['external_id'] ?? null
                        ]);

                        $results['created_roles'][] = $roleName;
                    }
                }
            }

            // Process permissions from external system
            if (!empty($mapping['permissions'])) {
                foreach ($mapping['permissions'] as $externalPermission) {
                    $permissionName = $externalPermission['name'];
                    $existingPermission = $this->permissionRepository->findBy('name', $permissionName);

                    if ($existingPermission) {
                        // Update existing permission
                        $this->permissionRepository->update($existingPermission->id, [
                            'display_name' => $externalPermission['display_name'] ?? $existingPermission->display_name,
                            'description' => $externalPermission['description'] ?? $existingPermission->description,
                            'external_id' => $externalPermission['external_id'] ?? $existingPermission->external_id
                        ]);

                        $results['updated_permissions'][] = $permissionName;
                    } else {
                        // Create new permission
                        $permission = $this->permissionRepository->create([
                            'name' => $permissionName,
                            'display_name' => $externalPermission['display_name'] ?? ucwords(str_replace('_', ' ', $permissionName)),
                            'description' => $externalPermission['description'] ?? null,
                            'external_id' => $externalPermission['external_id'] ?? null
                        ]);

                        $results['created_permissions'][] = $permissionName;
                    }
                }
            }

            // Process role-permission mappings
            if (!empty($mapping['role_permissions'])) {
                foreach ($mapping['role_permissions'] as $roleName => $permissionNames) {
                    $role = $this->roleRepository->findBy('name', $roleName);

                    if ($role) {
                        try {
                            $this->assignPermissions($role->id, $permissionNames);
                        } catch (\Exception $e) {
                            $results['errors'][] = "Error assigning permissions to role {$roleName}: " . $e->getMessage();
                        }
                    } else {
                        $results['errors'][] = "Role {$roleName} not found for permission assignment.";
                    }
                }
            }

            // Process user-role mappings
            if (!empty($mapping['user_roles'])) {
                foreach ($mapping['user_roles'] as $userIdentifier => $roleNames) {
                    // Determine if identifier is email or external ID
                    $isEmail = filter_var($userIdentifier, FILTER_VALIDATE_EMAIL);

                    $user = $isEmail
                        ? $this->userRepository->findBy('email', $userIdentifier)
                        : $this->userRepository->findBy('external_id', $userIdentifier);

                    if ($user) {
                        foreach ($roleNames as $roleName) {
                            $role = $this->roleRepository->findBy('name', $roleName);

                            if ($role) {
                                try {
                                    $this->assignUserRole($user->id, $role->id);
                                } catch (\Exception $e) {
                                    $results['errors'][] = "Error assigning role {$roleName} to user {$userIdentifier}: " . $e->getMessage();
                                }
                            } else {
                                $results['errors'][] = "Role {$roleName} not found for user {$userIdentifier}.";
                            }
                        }
                    } else {
                        $results['errors'][] = "User with identifier {$userIdentifier} not found.";
                    }
                }
            }

            DB::commit();

            // Clear permission cache
            $this->clearPermissionCache();

            Log::info("Synchronized RBAC with external system", [
                'created_roles' => count($results['created_roles']),
                'updated_roles' => count($results['updated_roles']),
                'created_permissions' => count($results['created_permissions']),
                'updated_permissions' => count($results['updated_permissions']),
                'errors' => count($results['errors'])
            ]);

            return $results;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to synchronize with external system", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Clear the permission cache for a specific user or all users.
     *
     * @param int|null $userId User ID to clear cache for (null for all users)
     * @return void
     */
    protected function clearPermissionCache(int $userId = null)
    {
        if ($userId) {
            // Clear for specific user
            $pattern = "/^user_{$userId}_permission_/";
            foreach (array_keys($this->userPermissionCache) as $key) {
                if (preg_match($pattern, $key)) {
                    unset($this->userPermissionCache[$key]);
                }
            }
        } else {
            // Clear all cached permissions
            $this->userPermissionCache = [];
        }
    }

    /**
     * Get a user's roles, optionally filtered by context.
     *
     * @param int $userId The user ID
     * @param mixed|null $context Optional context filter
     * @return Collection The roles assigned to the user
     */
    protected function getUserRoles(int $userId, $context = null)
    {
        // Start with global roles (not context-specific)
        $query = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->select('roles.*');

        // Add context-specific roles if context is provided and feature is enabled
        if ($context && $this->config['context_based_permissions']) {
            $contextType = null;
            $contextId = null;

            if (is_array($context)) {
                $contextType = $context['type'] ?? null;
                $contextId = $context['id'] ?? null;
            } elseif (is_object($context)) {
                $contextType = get_class($context);
                $contextId = $context->id ?? null;
            }

            if ($contextType && $contextId) {
                $query->union(
                    DB::table('context_roles')
                        ->join('roles', 'roles.id', '=', 'context_roles.role_id')
                        ->where('context_roles.user_id', $userId)
                        ->where('context_roles.context_type', $contextType)
                        ->where('context_roles.context_id', $contextId)
                        ->select('roles.*')
                );
            }
        }

        return collect($query->get());
    }

    /**
     * Get all permissions for a role, including permissions from parent roles if hierarchy is enabled.
     *
     * @param int $roleId The role ID
     * @return array Array of permission names
     */
    protected function getRolePermissions(int $roleId)
    {
        // Get direct permissions
        $permissions = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->toArray();

        // If hierarchical roles are enabled, get permissions from parent roles
        if ($this->config['hierarchical_roles']) {
            $parentRoleIds = $this->getParentRoleIds($roleId);

            if (!empty($parentRoleIds)) {
                $parentPermissions = DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->whereIn('role_permissions.role_id', $parentRoleIds)
                    ->pluck('permissions.name')
                    ->toArray();

                $permissions = array_merge($permissions, $parentPermissions);
                $permissions = array_unique($permissions);
            }
        }

        return $permissions;
    }

    /**
     * Check if a dynamic permission should be granted.
     *
     * @param int $userId The user ID
     * @param int $roleId The role ID
     * @param string $permission The permission name
     * @param mixed|null $context Optional context
     * @return bool True if the dynamic permission is granted
     */
    protected function checkDynamicPermission(int $userId, int $roleId, string $permission, $context = null)
    {
        $dynamicPermissions = app('rbac.dynamic_permissions') ?? [];

        if (isset($dynamicPermissions[$permission])) {
            $condition = $dynamicPermissions[$permission];

            // Get the user and role objects
            $user = $this->userRepository->find($userId);
            $role = $this->roleRepository->find($roleId);

            // Execute the condition closure
            return $condition($user, $role, $context);
        }

        return false;
    }


    /**
     * Get all parent role IDs for a given role.
     *
     * @param int $roleId The role ID
     * @return array Array of parent role IDs
     */
    protected function getParentRoleIds(int $roleId)
    {
        $parentRoleIds = [];
        $this->collectParentRoleIds($roleId, $parentRoleIds);
        return $parentRoleIds;
    }

    /**
     * Recursively collect all parent role IDs.
     *
     * @param int $roleId The role ID to get parents for
     * @param array &$parentRoleIds Collection of parent role IDs
     * @return void
     */
    protected function collectParentRoleIds(int $roleId, array &$parentRoleIds)
    {
        // Get direct parent roles
        $directParents = DB::table('role_inheritance')
            ->where('child_role_id', $roleId)
            ->pluck('parent_role_id')
            ->toArray();

        foreach ($directParents as $parentId) {
            // Avoid infinite recursion if there's a circular reference
            if (!in_array($parentId, $parentRoleIds)) {
                $parentRoleIds[] = $parentId;

                // Get parents of parents (recursive)
                $this->collectParentRoleIds($parentId, $parentRoleIds);
            }
        }
    }

    /**
     * Check if adding a parent-child relationship would create a circular reference.
     *
     * @param int $childRoleId The child role ID
     * @param int $parentRoleId The parent role ID
     * @return bool True if a circular reference would be created
     */
    protected function wouldCreateCircularReference(int $childRoleId, int $parentRoleId): bool
    {
        // If the parent is the same as the child, that's a circular reference
        if ($childRoleId === $parentRoleId) {
            return true;
        }

        // Get all descendants of the would-be child
        $descendantIds = [];
        $this->collectChildRoleIds($childRoleId, $descendantIds);

        // If the parent is among the descendants, it would create a circular reference
        return in_array($parentRoleId, $descendantIds);
    }

    /**
     * Recursively collect all child role IDs.
     *
     * @param int $roleId The role ID to get children for
     * @param array &$childRoleIds Collection of child role IDs
     * @return void
     */
    protected function collectChildRoleIds(int $roleId, array &$childRoleIds)
    {
        // Get direct child roles
        $directChildren = DB::table('role_inheritance')
            ->where('parent_role_id', $roleId)
            ->pluck('child_role_id')
            ->toArray();

        foreach ($directChildren as $childId) {
            // Avoid infinite recursion if there's a circular reference
            if (!in_array($childId, $childRoleIds)) {
                $childRoleIds[] = $childId;

                // Get children of children (recursive)
                $this->collectChildRoleIds($childId, $childRoleIds);
            }
        }
    }

    /**
     * Sync permissions for a role (remove existing and add new).
     *
     * @param Model $role The role model
     * @param array $permissionIds Array of permission IDs to assign
     * @return void
     */
    protected function syncRolePermissions(Model $role, array $permissionIds)
    {
        // Remove existing role permissions
        DB::table('role_permissions')->where('role_id', $role->id)->delete();

        // Add new permissions
        $rolePermissions = [];
        foreach ($permissionIds as $permissionId) {
            $rolePermissions[] = [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
                'created_at' => now()
            ];
        }

        if (!empty($rolePermissions)) {
            DB::table('role_permissions')->insert($rolePermissions);
        }
    }

    /**
     * Generate a hash for context objects to use in cache keys.
     *
     * @param mixed $context The context object or array
     * @return string A hash representing the context
     */
    protected function getContextHash($context): string
    {
        if (is_null($context)) {
            return 'null';
        }

        if (is_array($context)) {
            $contextType = $context['type'] ?? 'unknown';
            $contextId = $context['id'] ?? '0';
            return "{$contextType}_{$contextId}";
        }

        if (is_object($context)) {
            $contextType = get_class($context);
            $contextId = $context->id ?? '0';
            return "{$contextType}_{$contextId}";
        }

        return md5(serialize($context));
    }

    /**
     * Get the role hierarchy as a nested tree structure.
     *
     * @return array The role hierarchy tree
     */
    protected function getRoleHierarchyTree(): array
    {
        // Get all roles
        $roles = $this->roleRepository->all();

        // Get all inheritance relationships
        $inheritanceRelationships = DB::table('role_inheritance')
            ->get()
            ->groupBy('parent_role_id')
            ->toArray();

        // Build the tree structure
        $tree = [];

        foreach ($roles as $role) {
            // Create the role node
            $roleNode = [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'children' => []
            ];

            // Add children if this role is a parent
            if (isset($inheritanceRelationships[$role->id])) {
                foreach ($inheritanceRelationships[$role->id] as $relationship) {
                    $childRoleId = $relationship->child_role_id;
                    $childRole = $roles->firstWhere('id', $childRoleId);

                    if ($childRole) {
                        $roleNode['children'][] = [
                            'id' => $childRole->id,
                            'name' => $childRole->name,
                            'display_name' => $childRole->display_name
                        ];
                    }
                }
            }

            // Only add top-level roles (those that aren't children in any relationship)
            $isChild = DB::table('role_inheritance')
                ->where('child_role_id', $role->id)
                ->exists();

            if (!$isChild) {
                $tree[] = $roleNode;
            }
        }

        return $tree;
    }

    /**
     * Group permissions by their name prefix for better UI organization.
     *
     * @param Collection $permissions Collection of permission models
     * @return array Grouped permissions
     */
    protected function groupPermissionsByPrefix(Collection $permissions): array
    {
        $groups = [];

        foreach ($permissions as $permission) {
            $name = $permission->name;
            $parts = explode('.', $name);

            $prefix = count($parts) > 1 ? $parts[0] : 'general';

            if (!isset($groups[$prefix])) {
                $groups[$prefix] = [
                    'name' => ucwords(str_replace('_', ' ', $prefix)),
                    'permissions' => []
                ];
            }

            $groups[$prefix]['permissions'][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'description' => $permission->description
            ];
        }

        // Sort groups by name
        ksort($groups);

        return $groups;
    }
}