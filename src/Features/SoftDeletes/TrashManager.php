<?php

namespace SwatTech\Crud\Features\SoftDeletes;

use SwatTech\Crud\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Exception;

/**
 * TrashManager
 *
 * A service class for managing soft-deleted models in the application.
 * Provides functionality for trash management, restoration, permanent deletion,
 * batch operations, and retention policies for soft-deleted items.
 *
 * @package SwatTech\Crud\Features\SoftDeletes
 */
class TrashManager extends BaseService
{
    /**
     * The model repository instance.
     *
     * @var mixed
     */
    protected $repository;

    /**
     * Configuration for trash management.
     *
     * @var array
     */
    protected $config;

    /**
     * Active model class name.
     *
     * @var string|null
     */
    protected $modelType = null;

    /**
     * Cascade deletion configuration.
     *
     * @var array
     */
    protected $cascadeConfig = [];

    /**
     * Create a new TrashManager instance.
     *
     * @param mixed $repository Optional repository for data access
     * @return void
     */
    public function __construct($repository = null)
    {
        $this->repository = $repository;
        
        $this->config = config('crud.features.soft_deletes', [
            'default_retention_days' => 30,
            'enable_cascade_deletion' => true,
            'notify_on_permanent_deletion' => true,
            'allow_batch_operations' => true,
            'trash_table_column' => 'deleted_at',
            'admin_role' => 'admin',
            'auto_restore_relationships' => false
        ]);
    }

    /**
     * Manage trash for a specific model type.
     *
     * @param string $modelType Fully qualified model class name
     * @return $this
     * 
     * @throws Exception If model doesn't support soft deletes
     */
    public function manageTrash(string $modelType)
    {
        // Check if the model exists and uses soft deletes
        if (!class_exists($modelType)) {
            throw new Exception("Model class {$modelType} does not exist");
        }

        $instance = new $modelType();
        
        // Check if the model uses soft deletes trait
        if (!method_exists($instance, 'getDeletedAtColumn')) {
            throw new Exception("Model {$modelType} does not use SoftDeletes trait");
        }

        $this->modelType = $modelType;

        Log::debug("Trash manager initialized for model: {$modelType}");

        return $this;
    }

    /**
     * Restore a soft-deleted item.
     *
     * @param int $id The ID of the item to restore
     * @param bool $withRelationships Whether to restore related items
     * @return mixed The restored model
     * 
     * @throws Exception If restoration fails
     */
    public function restoreItem(int $id, bool $withRelationships = false)
    {
        $this->checkModelType();

        try {
            DB::beginTransaction();

            // Get the model with trashed
            $model = $this->modelType::withTrashed()->findOrFail($id);

            // Check if it's actually deleted
            if (!$model->trashed()) {
                throw new Exception("Item with ID {$id} is not in trash");
            }

            // Restore related models if requested
            if ($withRelationships && $this->config['auto_restore_relationships']) {
                $this->restoreRelatedItems($model);
            }

            // Restore the model
            $model->restore();

            DB::commit();

            Log::info("Restored item from trash", [
                'model' => $this->modelType,
                'id' => $id,
                'with_relationships' => $withRelationships
            ]);

            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to restore item: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to restore item: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Configure cascade deletion for related models.
     *
     * @param array $relationships Configuration for cascade deletion
     * @return $this
     */
    public function configureCascadeDeletion(array $relationships = [])
    {
        $this->cascadeConfig = $relationships;

        // Store this configuration in the manager instance
        if (empty($relationships) && $this->config['enable_cascade_deletion']) {
            // Auto-detect relationships that should cascade
            $this->cascadeConfig = $this->detectCascadeRelationships();
        }

        Log::debug("Cascade deletion configured", [
            'model' => $this->modelType,
            'relationships' => array_keys($this->cascadeConfig)
        ]);

        return $this;
    }

    /**
     * Permanently delete an item from the database.
     *
     * @param int $id The ID of the item to delete permanently
     * @param bool $force Whether to force deletion even if not in trash
     * @return bool Success status
     * 
     * @throws Exception If deletion fails
     */
    public function permanentlyDeleteItem(int $id, bool $force = false)
    {
        $this->checkModelType();

        try {
            DB::beginTransaction();

            // Get the model (with trashed)
            $model = $this->modelType::withTrashed()->findOrFail($id);
            
            // Check if it's actually deleted, unless force flag is true
            if (!$force && !$model->trashed()) {
                throw new Exception("Item with ID {$id} is not in trash. Use force parameter to delete anyway.");
            }

            // Handle cascade deletion of related models if configured
            if (!empty($this->cascadeConfig)) {
                $this->handleCascadeDeletion($model);
            }

            // Permanently delete the model
            $result = $model->forceDelete();

            // Send notification if configured
            if ($result && $this->config['notify_on_permanent_deletion']) {
                $this->notifyPermanentDeletion($model);
            }

            DB::commit();

            Log::info("Permanently deleted item", [
                'model' => $this->modelType,
                'id' => $id
            ]);

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to permanently delete item: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to permanently delete item: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up retention policies for automatic trash purging.
     *
     * @param int $days Number of days to keep items in trash (0 for no limit)
     * @return $this
     */
    public function setupRetentionPolicies(int $days = 0)
    {
        $this->checkModelType();

        // If days is 0, use default from config
        if ($days <= 0) {
            $days = $this->config['default_retention_days'];
        }

        // Store retention days in config
        $this->config['retention_days'] = $days;

        Log::debug("Retention policy configured", [
            'model' => $this->modelType,
            'days' => $days
        ]);

        return $this;
    }

    /**
     * Process batch operations on multiple trash items.
     *
     * @param string $action The action to perform ('restore', 'delete')
     * @param array $ids Array of item IDs to process
     * @param array $options Additional options for the operation
     * @return array Results of the batch operation
     * 
     * @throws Exception If the batch operation fails
     */
    public function processBatchOperations(string $action, array $ids, array $options = [])
    {
        $this->checkModelType();

        if (!$this->config['allow_batch_operations']) {
            throw new Exception("Batch operations are disabled in configuration");
        }

        $results = [
            'total' => count($ids),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            foreach ($ids as $id) {
                try {
                    switch ($action) {
                        case 'restore':
                            $withRelationships = $options['with_relationships'] ?? false;
                            $this->restoreItem($id, $withRelationships);
                            $results['successful']++;
                            break;

                        case 'delete':
                            $force = $options['force'] ?? false;
                            $this->permanentlyDeleteItem($id, $force);
                            $results['successful']++;
                            break;

                        default:
                            throw new Exception("Unsupported batch operation: {$action}");
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][$id] = $e->getMessage();
                    
                    // Log the error but continue with other items
                    Log::warning("Batch operation failed for item", [
                        'model' => $this->modelType,
                        'id' => $id,
                        'action' => $action,
                        'error' => $e->getMessage()
                    ]);
                }
                
                $results['processed']++;
            }

            Log::info("Batch operation completed", [
                'model' => $this->modelType,
                'action' => $action,
                'results' => $results
            ]);

            return $results;
        } catch (Exception $e) {
            Log::error("Batch operation failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Batch operation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle relationships for trash operations.
     *
     * @param array $relationshipConfig Configuration for relationship handling
     * @return $this
     */
    public function handleRelationships(array $relationshipConfig = [])
    {
        $this->checkModelType();

        // Merge with any existing config
        $this->cascadeConfig = array_merge($this->cascadeConfig, $relationshipConfig);

        Log::debug("Relationship handling configured", [
            'model' => $this->modelType,
            'relationships' => array_keys($this->cascadeConfig)
        ]);

        return $this;
    }

    /**
     * Implement search and filtering for trash items.
     *
     * @param array $filters Search and filter criteria
     * @param array $sort Sorting options
     * @param int $perPage Items per page (0 for no pagination)
     * @return mixed Collection or LengthAwarePaginator of trash items
     */
    public function implementSearchAndFiltering(array $filters = [], array $sort = [], int $perPage = 15)
    {
        $this->checkModelType();

        $query = $this->modelType::onlyTrashed();
        
        // Apply filters
        if (!empty($filters)) {
            foreach ($filters as $field => $value) {
                if (is_array($value) && count($value) === 2) {
                    // Handle range/comparison filters
                    $operator = $value[0];
                    $filterValue = $value[1];
                    $query->where($field, $operator, $filterValue);
                } else {
                    // Handle exact match or like filters
                    if (is_string($value) && strpos($value, '%') !== false) {
                        $query->where($field, 'like', $value);
                    } else {
                        $query->where($field, $value);
                    }
                }
            }
        }
        
        // Apply sorting
        if (!empty($sort)) {
            foreach ($sort as $field => $direction) {
                $query->orderBy($field, $direction);
            }
        } else {
            // Default sort by deletion date (newest first)
            $deletedAtColumn = (new $this->modelType)->getDeletedAtColumn();
            $query->orderBy($deletedAtColumn, 'desc');
        }
        
        // Return paginated results if perPage > 0
        if ($perPage > 0) {
            return $query->paginate($perPage);
        }
        
        return $query->get();
    }

    /**
     * Empty all trash for a model type or all models.
     *
     * @param string $modelType Optional model type to restrict purging
     * @param int $olderThanDays Only purge items deleted more than this many days ago
     * @return array Results of the trash emptying operation
     * 
     * @throws Exception If trash emptying fails
     */
    public function emptyTrash(string $modelType = null, int $olderThanDays = 0)
    {
        $modelType = $modelType ?? $this->modelType;
        
        // If no model type specified, this is an admin operation to purge all trash
        if (!$modelType) {
            return $this->emptyAllTrash($olderThanDays);
        }
        
        try {
            // Start a transaction
            DB::beginTransaction();
            
            // Find items to purge
            $query = $modelType::onlyTrashed();
            
            // Apply age filter if specified
            if ($olderThanDays > 0) {
                $deletedAtColumn = (new $modelType)->getDeletedAtColumn();
                $cutoffDate = Carbon::now()->subDays($olderThanDays);
                $query->where($deletedAtColumn, '<', $cutoffDate);
            }
            
            // Count items before deletion for reporting
            $count = $query->count();
            
            // Delete the items
            $query->forceDelete();
            
            DB::commit();
            
            Log::info("Trash emptied successfully", [
                'model' => $modelType,
                'items_purged' => $count,
                'older_than_days' => $olderThanDays
            ]);
            
            return [
                'success' => true,
                'model' => $modelType, 
                'purged_count' => $count,
                'older_than_days' => $olderThanDays
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to empty trash: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to empty trash: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Configure notifications for trash operations.
     *
     * @param array $options Notification configuration options
     * @return $this
     */
    public function configureNotifications(array $options = [])
    {
        // Default notification configuration
        $notificationConfig = [
            'enabled' => true,
            'notify_on_restore' => false,
            'notify_on_permanent_delete' => true,
            'notify_on_empty_trash' => true,
            'recipients' => $options['recipients'] ?? null,
            'channels' => $options['channels'] ?? ['mail', 'database']
        ];
        
        // Merge with provided options
        $this->config['notifications'] = array_merge($notificationConfig, $options);
        
        Log::debug("Trash notifications configured", [
            'enabled' => $this->config['notifications']['enabled'],
            'channels' => $this->config['notifications']['channels']
        ]);
        
        return $this;
    }

    /**
     * Check if a model type has been set.
     *
     * @throws Exception If no model type has been set
     * @return void
     */
    protected function checkModelType()
    {
        if (!$this->modelType) {
            throw new Exception("Model type not specified. Call manageTrash() first.");
        }
    }

    /**
     * Apply retention policies to purge old trash items.
     *
     * @return array Results of the retention policy application
     */
    public function applyRetentionPolicies()
    {
        $this->checkModelType();
        
        $days = $this->config['retention_days'] ?? $this->config['default_retention_days'];
        
        if ($days <= 0) {
            return ['success' => true, 'message' => 'No retention policy applied'];
        }
        
        return $this->emptyTrash($this->modelType, $days);
    }

    /**
     * Restore related items for a model.
     *
     * @param Model $model The model whose related items should be restored
     * @return void
     */
    protected function restoreRelatedItems(Model $model)
    {
        // This is a placeholder implementation
        // In a real-world scenario, you would need to analyze the model's relationships
        // and restore related models that were cascade-deleted with this one
        
        if (empty($this->cascadeConfig)) {
            // No cascade configuration, so nothing to restore
            return;
        }
        
        foreach ($this->cascadeConfig as $relationship => $config) {
            if (!method_exists($model, $relationship)) {
                continue;
            }
            
            // Only process relationships configured for cascade restore
            if (!($config['cascade_restore'] ?? false)) {
                continue;
            }
            
            $related = $model->$relationship()->withTrashed()->get();
            
            foreach ($related as $item) {
                if ($item->trashed()) {
                    $item->restore();
                    
                    Log::debug("Restored related item", [
                        'parent_model' => get_class($model),
                        'parent_id' => $model->getKey(),
                        'related_model' => get_class($item),
                        'related_id' => $item->getKey(),
                        'relationship' => $relationship
                    ]);
                }
            }
        }
    }

    /**
     * Handle cascade deletion of related models.
     *
     * @param Model $model The model whose related items should be deleted
     * @return void
     */
    protected function handleCascadeDeletion(Model $model)
    {
        foreach ($this->cascadeConfig as $relationship => $config) {
            if (!method_exists($model, $relationship)) {
                continue;
            }
            
            // Check if this relationship should be cascade deleted
            if (!($config['cascade_delete'] ?? $this->config['enable_cascade_deletion'])) {
                continue;
            }
            
            $related = $model->$relationship()->withTrashed()->get();
            
            foreach ($related as $item) {
                // Permanently delete the related item
                $item->forceDelete();
                
                Log::debug("Cascade-deleted related item", [
                    'parent_model' => get_class($model),
                    'parent_id' => $model->getKey(),
                    'related_model' => get_class($item),
                    'related_id' => $item->getKey(),
                    'relationship' => $relationship
                ]);
            }
        }
    }

    /**
     * Detect relationships that should cascade based on database constraints.
     *
     * @return array Detected cascade relationships
     */
    protected function detectCascadeRelationships()
    {
        // This is a simplified placeholder implementation
        // In a real application, you would analyze the database schema and model relationships
        
        $cascadeConfig = [];
        $modelInstance = new $this->modelType();
        $reflection = new \ReflectionClass($this->modelType);
        
        // Try to detect relationships by method names in the model
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip non-relationship methods and constructor
            if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                continue;
            }
            
            // Check if this looks like a relationship method
            $name = $method->getName();
            $returnType = $method->getReturnType();
            
            if (!$returnType) {
                continue;
            }
            
            $returnTypeName = $returnType->getName();
            
            // Check if return type matches a relationship type
            $isRelationship = (
                strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relations\\') !== false ||
                is_subclass_of($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relations\\Relation')
            );
            
            if ($isRelationship) {
                // Found a relationship method
                $cascadeConfig[$name] = [
                    'cascade_delete' => true,
                    'cascade_restore' => true
                ];
            }
        }
        
        return $cascadeConfig;
    }

    /**
     * Notify about permanent deletion.
     *
     * @param Model $model The permanently deleted model
     * @return void
     */
    protected function notifyPermanentDeletion(Model $model)
    {
        if (!$this->config['notify_on_permanent_deletion']) {
            return;
        }
        
        // This is a placeholder implementation
        // In a real application, you would implement notifications here
        
        Log::info("Notification would be sent for permanent deletion", [
            'model' => get_class($model),
            'id' => $model->getKey()
        ]);
    }

    /**
     * Empty trash for all soft-deletable models in the application.
     *
     * @param int $olderThanDays Only purge items deleted more than this many days ago
     * @return array Results of the trash emptying operation
     */
    protected function emptyAllTrash(int $olderThanDays = 0)
    {
        $results = [
            'success' => true,
            'models_processed' => 0,
            'total_items_purged' => 0,
            'details' => []
        ];
        
        // Get all model classes in the application
        // This is a simplified approach - in a real app, you'd need a more robust way to get all models
        $models = $this->getSoftDeletableModels();
        
        foreach ($models as $modelClass) {
            try {
                $result = $this->emptyTrash($modelClass, $olderThanDays);
                $results['models_processed']++;
                $results['total_items_purged'] += $result['purged_count'];
                $results['details'][$modelClass] = $result;
            } catch (Exception $e) {
                Log::warning("Failed to empty trash for model: {$modelClass}", [
                    'error' => $e->getMessage()
                ]);
                $results['details'][$modelClass] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        Log::info("All trash emptied", [
            'models_processed' => $results['models_processed'],
            'total_items_purged' => $results['total_items_purged']
        ]);
        
        return $results;
    }

    /**
     * Get a list of all models that use soft deletes.
     *
     * @return array List of model class names
     */
    protected function getSoftDeletableModels()
    {
        // This is a simplified implementation
        // In a real application, you would scan the models directory
        // and check which models use the SoftDeletes trait
        
        // Example approach (would need to be customized based on your application)
        $potentialModels = [];
        
        // For this example, we'll assume we're checking tables instead
        $tables = Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();
        
        foreach ($tables as $table) {
            // Skip certain tables like migrations, etc.
            if (in_array($table, ['migrations', 'failed_jobs', 'password_resets'])) {
                continue;
            }
            
            // Check if the table has a deleted_at column
            if (Schema::hasColumn($table, 'deleted_at')) {
                // Try to determine model class from table name
                $modelName = Str::studly(Str::singular($table));
                $modelClass = "App\\Models\\{$modelName}";
                
                // Check if the model class exists and uses SoftDeletes
                if (class_exists($modelClass)) {
                    $reflection = new \ReflectionClass($modelClass);
                    foreach ($reflection->getTraitNames() as $trait) {
                        if (strpos($trait, 'SoftDeletes') !== false) {
                            $potentialModels[] = $modelClass;
                            break;
                        }
                    }
                }
            }
        }
        
        return $potentialModels;
    }
}