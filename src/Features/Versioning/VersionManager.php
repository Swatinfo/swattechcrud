<?php

namespace SwatTech\Crud\Features\Versioning;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use SwatTech\Crud\Services\BaseService;
use SwatTech\Crud\Contracts\RepositoryInterface;
use Carbon\Carbon;

/**
 * VersionManager
 *
 * A service class for managing data versioning in the application.
 * Handles version history, diff visualization, restoration, branching,
 * merging, and approval workflows.
 *
 * @package SwatTech\Crud\Features\Versioning
 */
class VersionManager extends BaseService
{
    /**
     * The version repository instance.
     *
     * @var RepositoryInterface
     */
    protected $versionRepository;

    /**
     * The model repository instance.
     *
     * @var RepositoryInterface
     */
    protected $modelRepository;

    /**
     * The user repository instance.
     *
     * @var RepositoryInterface
     */
    protected $userRepository;

    /**
     * Configuration for versioning functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Create a new VersionManager instance.
     *
     * @param RepositoryInterface $versionRepository
     * @param RepositoryInterface $modelRepository
     * @param RepositoryInterface $userRepository
     * @return void
     */
    public function __construct(
        RepositoryInterface $versionRepository,
        RepositoryInterface $modelRepository,
        RepositoryInterface $userRepository
    ) {
        $this->versionRepository = $versionRepository;
        $this->modelRepository = $modelRepository;
        $this->userRepository = $userRepository;
        $this->config = config('crud.features.versioning', [
            'enabled' => true,
            'retention_days' => 90,
            'max_versions_per_model' => 50,
            'approve_changes' => false,
            'track_user' => true,
            'diffable_fields' => ['*'],
            'excluded_fields' => ['created_at', 'updated_at', 'id'],
            'enable_branching' => false,
        ]);
    }

    /**
     * Create a version history entry for a model.
     *
     * @param int $modelId The ID of the model
     * @param array $data The data to store in the version
     * @param string|null $modelType The model class (for polymorphic versioning)
     * @return Model|null The created version record or null on failure
     * 
     * @throws \Exception If version creation fails
     */
    public function createVersionHistory(int $modelId, array $data, string $modelType = null)
    {
        if (!$this->config['enabled']) {
            return null;
        }

        // Retrieve the model if type is specified
        $model = null;
        if ($modelType) {
            $model = $modelType::find($modelId);
        } else {
            $model = $this->modelRepository->find($modelId);
        }

        if (!$model) {
            throw new \Exception("Model with ID {$modelId} not found.");
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Clean excluded fields from data
            $versionData = $this->removeExcludedFields($data);

            // Get the current user ID
            $userId = Auth::id() ?? 0;
            
            // Calculate the version number
            $latestVersion = $this->versionRepository->findWhere([
                'model_id' => $modelId,
                'model_type' => $modelType ?? get_class($model),
            ], ['order_by' => 'version_number', 'direction' => 'desc', 'limit' => 1]);
            
            $versionNumber = $latestVersion ? $latestVersion->version_number + 1 : 1;

            // Create the version record
            $version = $this->versionRepository->create([
                'model_id' => $modelId,
                'model_type' => $modelType ?? get_class($model),
                'version_number' => $versionNumber,
                'data' => json_encode($versionData),
                'created_by' => $userId,
                'created_at' => now(),
                'status' => $this->config['approve_changes'] ? 'pending' : 'approved',
                'branch' => 'main',
                'parent_version_id' => $latestVersion ? $latestVersion->id : null,
            ]);

            // Enforce retention policy if needed
            $this->enforceRetentionPolicy($modelId, $modelType ?? get_class($model));

            DB::commit();

            Log::info("Created version history for model ID {$modelId}", [
                'version_number' => $versionNumber,
                'model_type' => $modelType ?? get_class($model)
            ]);

            return $version;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create version history for model ID {$modelId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate and visualize differences between two versions.
     *
     * @param int $versionId First version ID
     * @param int $compareToId Second version ID to compare against
     * @return array An array of differences with field, old value, and new value
     * 
     * @throws \Exception If either version doesn't exist
     */
    public function implementDiffVisualization(int $versionId, int $compareToId)
    {
        // Get both versions
        $version = $this->versionRepository->find($versionId);
        $compareTo = $this->versionRepository->find($compareToId);

        if (!$version || !$compareTo) {
            throw new \Exception("One or both versions not found.");
        }

        // Ensure versions are for the same model
        if ($version->model_id != $compareTo->model_id || $version->model_type != $compareTo->model_type) {
            throw new \Exception("Cannot compare versions from different models.");
        }

        // Get the data from each version
        $versionData = json_decode($version->data, true);
        $compareToData = json_decode($compareTo->data, true);

        // Generate the diff
        $diff = [];
        
        // First find all fields in either version
        $allFields = array_unique(array_merge(array_keys($versionData), array_keys($compareToData)));
        
        foreach ($allFields as $field) {
            $oldValue = $compareToData[$field] ?? null;
            $newValue = $versionData[$field] ?? null;
            
            // Only add to diff if values are different
            if ($oldValue !== $newValue) {
                $diff[] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'change_type' => $this->determineChangeType($oldValue, $newValue)
                ];
            }
        }

        return $diff;
    }

    /**
     * Restore a model to a previous version.
     *
     * @param int $modelId The ID of the model
     * @param int $versionId The ID of the version to restore
     * @param string|null $modelType The model class (for polymorphic versioning)
     * @return bool True if restoration was successful
     * 
     * @throws \Exception If restoration fails
     */
    public function setupRestoration(int $modelId, int $versionId, string $modelType = null)
    {
        // Get the version to restore
        $version = $this->versionRepository->find($versionId);
        
        if (!$version) {
            throw new \Exception("Version with ID {$versionId} not found.");
        }
        
        // Verify the version belongs to the specified model
        if ($version->model_id != $modelId || 
            ($modelType && $version->model_type != $modelType)) {
            throw new \Exception("Version does not belong to the specified model.");
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Get the version data
            $versionData = json_decode($version->data, true);
            
            // Retrieve the model
            $model = null;
            if ($modelType) {
                $model = $modelType::find($modelId);
            } else {
                $model = $this->modelRepository->find($modelId);
                $modelType = get_class($model);
            }
            
            if (!$model) {
                throw new \Exception("Model with ID {$modelId} not found.");
            }
            
            // First create a backup of current state
            $currentData = $this->extractModelData($model);
            $this->createVersionHistory($modelId, $currentData, $modelType);
            
            // Update the model with the version data
            $model->fill($versionData);
            $model->save();
            
            // Create a restoration note
            $this->implementVersionNotes($versionId, "Restored from version {$version->version_number}");
            
            DB::commit();
            
            Log::info("Restored model ID {$modelId} to version {$version->version_number}", [
                'model_type' => $modelType
            ]);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to restore model ID {$modelId} to version {$versionId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Track the author/user of a specific version.
     *
     * @param int $versionId The ID of the version
     * @param int $userId The ID of the user who created the version
     * @return bool True if tracking was successful
     * 
     * @throws \Exception If tracking fails
     */
    public function trackAuthor(int $versionId, int $userId)
    {
        if (!$this->config['track_user']) {
            return false;
        }

        // Verify the version exists
        $version = $this->versionRepository->find($versionId);
        if (!$version) {
            throw new \Exception("Version with ID {$versionId} not found.");
        }

        // Verify the user exists
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \Exception("User with ID {$userId} not found.");
        }

        // Update the version with the user ID
        $this->versionRepository->update($versionId, [
            'created_by' => $userId,
            'updated_at' => now()
        ]);

        Log::info("Updated author of version ID {$versionId} to user {$userId}");

        return true;
    }

    /**
     * Add notes to a version.
     *
     * @param int $versionId The ID of the version
     * @param string $notes The notes to add to the version
     * @return bool True if notes were added successfully
     * 
     * @throws \Exception If adding notes fails
     */
    public function implementVersionNotes(int $versionId, string $notes)
    {
        // Verify the version exists
        $version = $this->versionRepository->find($versionId);
        if (!$version) {
            throw new \Exception("Version with ID {$versionId} not found.");
        }

        // Update the version with notes
        $this->versionRepository->update($versionId, [
            'notes' => $notes,
            'updated_at' => now()
        ]);

        Log::info("Added notes to version ID {$versionId}");

        return true;
    }

    /**
     * Create a new branch from a specific version.
     *
     * @param int $versionId The ID of the version to branch from
     * @param string $branchName The name of the new branch
     * @return Model|null The created branch version or null if branching is disabled
     * 
     * @throws \Exception If branching fails
     */
    public function createBranching(int $versionId, string $branchName)
    {
        if (!$this->config['enable_branching']) {
            Log::info("Branching is disabled in configuration");
            return null;
        }

        // Validate branch name
        if (!$this->isValidBranchName($branchName)) {
            throw new \Exception("Invalid branch name. Branch names can only contain alphanumeric characters, dashes, and underscores.");
        }

        // Get the version to branch from
        $version = $this->versionRepository->find($versionId);
        if (!$version) {
            throw new \Exception("Version with ID {$versionId} not found.");
        }

        // Check if the branch already exists
        $existingBranch = $this->versionRepository->findWhere([
            'model_id' => $version->model_id,
            'model_type' => $version->model_type,
            'branch' => $branchName
        ]);

        if ($existingBranch) {
            throw new \Exception("Branch with name '{$branchName}' already exists for this model.");
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Create a new version with the branch name
            $branchedVersion = $this->versionRepository->create([
                'model_id' => $version->model_id,
                'model_type' => $version->model_type,
                'version_number' => 1, // Start with version 1 for the new branch
                'data' => $version->data,
                'created_by' => Auth::id() ?? 0,
                'created_at' => now(),
                'status' => 'approved', // Assume branches start as approved
                'branch' => $branchName,
                'parent_version_id' => $version->id,
                'notes' => "Branched from {$version->branch} version {$version->version_number}"
            ]);

            DB::commit();

            Log::info("Created branch '{$branchName}' from version ID {$versionId}", [
                'model_id' => $version->model_id,
                'model_type' => $version->model_type
            ]);

            return $branchedVersion;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create branch from version ID {$versionId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Set up merging between branches.
     *
     * @param int $branchId The ID of the branch version to merge from
     * @param int $targetBranchId The ID of the target branch version to merge into
     * @return array|null The merge result with conflict information or null on failure
     * 
     * @throws \Exception If merging fails
     */
    public function setupMerging(int $branchId, int $targetBranchId)
    {
        if (!$this->config['enable_branching']) {
            Log::info("Branching is disabled in configuration");
            return null;
        }

        // Get both branch versions
        $branchVersion = $this->versionRepository->find($branchId);
        $targetVersion = $this->versionRepository->find($targetBranchId);

        if (!$branchVersion || !$targetVersion) {
            throw new \Exception("One or both branch versions not found.");
        }

        // Ensure versions are for the same model
        if ($branchVersion->model_id != $targetVersion->model_id || 
            $branchVersion->model_type != $targetVersion->model_type) {
            throw new \Exception("Cannot merge versions from different models.");
        }

        // Get the data from each branch
        $branchData = json_decode($branchVersion->data, true);
        $targetData = json_decode($targetVersion->data, true);

        // Detect conflicts
        $conflicts = $this->detectMergeConflicts($branchData, $targetData);
        
        // If there are no conflicts, we can auto-merge
        if (empty($conflicts)) {
            return $this->performMerge($branchVersion, $targetVersion);
        }

        // Return conflicts that need resolution
        return [
            'status' => 'conflicts',
            'conflicts' => $conflicts,
            'branch_version_id' => $branchId,
            'target_version_id' => $targetBranchId
        ];
    }

    /**
     * Add conflict resolution for merging branches.
     *
     * @param int $branchId The ID of the branch version to merge from
     * @param int $targetBranchId The ID of the target branch version to merge into
     * @param array $resolutions Array of field => value pairs for conflict resolution
     * @return Model|null The new merged version or null on failure
     * 
     * @throws \Exception If conflict resolution fails
     */
    public function addConflictResolution(int $branchId, int $targetBranchId, array $resolutions)
    {
        if (!$this->config['enable_branching']) {
            Log::info("Branching is disabled in configuration");
            return null;
        }

        // Get both branch versions
        $branchVersion = $this->versionRepository->find($branchId);
        $targetVersion = $this->versionRepository->find($targetBranchId);

        if (!$branchVersion || !$targetVersion) {
            throw new \Exception("One or both branch versions not found.");
        }

        // Get the data from each branch
        $branchData = json_decode($branchVersion->data, true);
        $targetData = json_decode($targetVersion->data, true);
        
        // Create merged data with manual resolutions
        $mergedData = array_merge($targetData, $branchData);
        
        // Apply manual resolutions
        foreach ($resolutions as $field => $value) {
            $mergedData[$field] = $value;
        }
        
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Calculate the next version number for the target branch
            $latestTargetVersion = $this->versionRepository->findWhere([
                'model_id' => $targetVersion->model_id,
                'model_type' => $targetVersion->model_type,
                'branch' => $targetVersion->branch
            ], ['order_by' => 'version_number', 'direction' => 'desc', 'limit' => 1]);
            
            $newVersionNumber = $latestTargetVersion ? $latestTargetVersion->version_number + 1 : 1;
            
            // Create a new version with merged data
            $mergedVersion = $this->versionRepository->create([
                'model_id' => $targetVersion->model_id,
                'model_type' => $targetVersion->model_type,
                'version_number' => $newVersionNumber,
                'data' => json_encode($mergedData),
                'created_by' => Auth::id() ?? 0,
                'created_at' => now(),
                'status' => $this->config['approve_changes'] ? 'pending' : 'approved',
                'branch' => $targetVersion->branch,
                'parent_version_id' => $targetVersion->id,
                'notes' => "Merged from branch '{$branchVersion->branch}' version {$branchVersion->version_number} to '{$targetVersion->branch}' version {$targetVersion->version_number} with manual conflict resolution"
            ]);

            DB::commit();

            Log::info("Merged branch '{$branchVersion->branch}' to '{$targetVersion->branch}' with conflict resolution", [
                'model_id' => $targetVersion->model_id,
                'model_type' => $targetVersion->model_type,
                'conflicts_resolved' => count($resolutions)
            ]);

            return $mergedVersion;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to merge branches with conflict resolution", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Implement retention policies for version history.
     *
     * @param int $days Number of days to retain version history (0 for no limit)
     * @return int Number of versions deleted
     */
    public function implementRetentionPolicies(int $days = 0)
    {
        if ($days <= 0) {
            $days = $this->config['retention_days'];
            
            // If still not set or set to unlimited, don't delete anything
            if ($days <= 0) {
                return 0;
            }
        }

        $cutoffDate = Carbon::now()->subDays($days);
        
        // Start a database transaction
        DB::beginTransaction();
        
        try {
            // Find old versions to delete
            $versionsToDelete = $this->versionRepository->findWhere([
                'created_at' => ['<', $cutoffDate->toDateTimeString()],
                'status' => 'approved' // Only delete approved versions
            ]);
            
            $deleteCount = count($versionsToDelete);
            
            if ($deleteCount > 0) {
                foreach ($versionsToDelete as $version) {
                    $this->versionRepository->delete($version->id);
                }
                
                DB::commit();
                
                Log::info("Deleted {$deleteCount} old versions older than {$days} days");
            } else {
                DB::rollBack();
            }
            
            return $deleteCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to implement retention policy", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create approval workflows for versions.
     *
     * @param array $workflow Array defining the approval workflow with approvers and rules
     * @return bool True if workflow was created successfully
     */
    public function createApprovalWorkflows(array $workflow = [])
    {
        if (!$this->config['approve_changes']) {
            Log::info("Approval workflow is disabled in configuration");
            return false;
        }
        
        // Validate workflow structure
        if (!isset($workflow['steps']) || !is_array($workflow['steps'])) {
            throw new \Exception("Workflow must contain a 'steps' array");
        }
        
        // Store workflow configuration
        $workflowConfig = array_merge([
            'name' => 'Default Approval Workflow',
            'description' => 'Standard approval process for version changes',
            'model_types' => ['*'], // Apply to all model types by default
            'steps' => [
                [
                    'name' => 'Initial Review',
                    'approvers' => ['role:editor'],
                    'min_approvals' => 1
                ],
                [
                    'name' => 'Final Approval',
                    'approvers' => ['role:admin'],
                    'min_approvals' => 1
                ]
            ]
        ], $workflow);
        
        // Store the workflow configuration
        DB::table('version_workflows')->updateOrInsert(
            ['name' => $workflowConfig['name']],
            [
                'description' => $workflowConfig['description'],
                'model_types' => json_encode($workflowConfig['model_types']),
                'steps' => json_encode($workflowConfig['steps']),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
        
        Log::info("Created approval workflow: {$workflowConfig['name']}");
        
        return true;
    }

    /**
     * Enforce the retention policy for a specific model.
     *
     * @param int $modelId The ID of the model
     * @param string $modelType The model class
     * @return void
     */
    protected function enforceRetentionPolicy(int $modelId, string $modelType)
    {
        // Check if we need to limit the number of versions
        $maxVersions = $this->config['max_versions_per_model'];
        
        if ($maxVersions > 0) {
            // Get all versions for this model, sorted by version number
            $versions = $this->versionRepository->findWhere([
                'model_id' => $modelId,
                'model_type' => $modelType
            ], ['order_by' => 'version_number', 'direction' => 'desc']);
            
            // If we have more versions than allowed, delete the oldest ones
            if (count($versions) > $maxVersions) {
                $versionsToDelete = array_slice($versions->toArray(), $maxVersions);
                
                foreach ($versionsToDelete as $version) {
                    $this->versionRepository->delete($version->id);
                }
                
                Log::info("Deleted " . count($versionsToDelete) . " old versions for model ID {$modelId} due to retention policy");
            }
        }
    }

    /**
     * Remove excluded fields from data array.
     *
     * @param array $data The data array to clean
     * @return array Cleaned data array
     */
    protected function removeExcludedFields(array $data)
    {
        $excludedFields = $this->config['excluded_fields'];
        
        foreach ($excludedFields as $field) {
            if (isset($data[$field])) {
                unset($data[$field]);
            }
        }
        
        return $data;
    }

    /**
     * Determine the type of change between two values.
     *
     * @param mixed $oldValue Old value
     * @param mixed $newValue New value
     * @return string Change type (added, removed, modified)
     */
    protected function determineChangeType($oldValue, $newValue)
    {
        if ($oldValue === null && $newValue !== null) {
            return 'added';
        } elseif ($oldValue !== null && $newValue === null) {
            return 'removed';
        } else {
            return 'modified';
        }
    }

    /**
     * Extract data from a model for versioning.
     *
     * @param Model $model The model to extract data from
     * @return array The extracted data
     */
    protected function extractModelData(Model $model)
    {
        $data = $model->toArray();
        return $this->removeExcludedFields($data);
    }

    /**
     * Validate a branch name.
     *
     * @param string $branchName The branch name to validate
     * @return bool True if the branch name is valid
     */
    protected function isValidBranchName(string $branchName)
    {
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $branchName);
    }

    /**
     * Detect conflicts when merging two data sets.
     *
     * @param array $branchData Data from the source branch
     * @param array $targetData Data from the target branch
     * @return array Array of field names with conflicts
     */
    protected function detectMergeConflicts(array $branchData, array $targetData)
    {
        $conflicts = [];
        
        foreach ($branchData as $field => $value) {
            // If the field exists in the target data and values are different, it's a conflict
            if (isset($targetData[$field]) && $targetData[$field] !== $value) {
                $conflicts[] = [
                    'field' => $field,
                    'branch_value' => $value,
                    'target_value' => $targetData[$field]
                ];
            }
        }
        
        return $conflicts;
    }

    /**
     * Perform the merge between two branches without conflicts.
     *
     * @param Model $branchVersion The source branch version
     * @param Model $targetVersion The target branch version
     * @return Model The new merged version
     */
    protected function performMerge(Model $branchVersion, Model $targetVersion)
    {
        // Get the data from each branch
        $branchData = json_decode($branchVersion->data, true);
        $targetData = json_decode($targetVersion->data, true);
        
        // Merge data (branch data overrides target data)
        $mergedData = array_merge($targetData, $branchData);
        
        // Calculate the next version number for the target branch
        $latestTargetVersion = $this->versionRepository->findWhere([
            'model_id' => $targetVersion->model_id,
            'model_type' => $targetVersion->model_type,
            'branch' => $targetVersion->branch
        ], ['order_by' => 'version_number', 'direction' => 'desc', 'limit' => 1]);
        
        $newVersionNumber = $latestTargetVersion ? $latestTargetVersion->version_number + 1 : 1;
        
        // Create a new version with merged data
        $mergedVersion = $this->versionRepository->create([
            'model_id' => $targetVersion->model_id,
            'model_type' => $targetVersion->model_type,
            'version_number' => $newVersionNumber,
            'data' => json_encode($mergedData),
            'created_by' => Auth::id() ?? 0,
            'created_at' => now(),
            'status' => $this->config['approve_changes'] ? 'pending' : 'approved',
            'branch' => $targetVersion->branch,
            'parent_version_id' => $targetVersion->id,
            'notes' => "Merged from branch '{$branchVersion->branch}' version {$branchVersion->version_number}"
        ]);

        Log::info("Merged branch '{$branchVersion->branch}' to '{$targetVersion->branch}' without conflicts", [
            'model_id' => $targetVersion->model_id,
            'model_type' => $targetVersion->model_type
        ]);
        
        return $mergedVersion;
    }
}