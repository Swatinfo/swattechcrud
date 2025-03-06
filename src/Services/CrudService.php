<?php

namespace SwatTech\Crud\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * CrudService
 *
 * This service extends the base service with specialized CRUD operations,
 * including batch processing, import/export functionality, workflow handling,
 * audit trails, and complex business rule validation.
 *
 * @package SwatTech\Crud\Services
 */
class CrudService extends BaseService
{
    /**
     * Business rules to apply to data.
     *
     * @var array
     */
    protected $businessRules = [];

    /**
     * Workflow definitions.
     *
     * @var array
     */
    protected $workflows = [];

    /**
     * Audit trail configuration.
     *
     * @var array
     */
    protected $auditConfig = [
        'enabled' => true,
        'user_field' => 'created_by',
        'store_old_values' => true,
        'excluded_fields' => ['updated_at', 'created_at']
    ];

    /**
     * Import/export configuration.
     *
     * @var array
     */
    protected $exportConfig = [
        'formats' => ['csv', 'xlsx', 'pdf'],
        'default_format' => 'csv',
        'chunk_size' => 1000
    ];

    /**
     * Create a new CrudService instance.
     *
     * @param RepositoryInterface $repository The repository instance
     */
    public function __construct(RepositoryInterface $repository)
    {
        parent::__construct($repository);
    }

    /**
     * Get all records with additional business logic.
     *
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return Collection A collection of model instances
     */
    public function getAll(array $filters = [], array $sorts = []): Collection
    {
        // Apply any additional pre-query logic here
        $this->applyPreQueryHooks('getAll', $filters, $sorts);

        $result = parent::getAll($filters, $sorts);

        // Apply any post-processing logic here
        return $this->applyPostQueryHooks('getAll', $result);
    }

    /**
     * Get paginated results with additional business logic.
     *
     * @param int $page The page number to retrieve (starting from 1)
     * @param int $perPage The number of records per page
     * @param array $filters An associative array of filter criteria (field => value)
     * @param array $sorts An associative array of sort criteria (field => direction)
     * @return LengthAwarePaginator A paginator instance with records and metadata
     */
    public function getPaginated(int $page = 1, int $perPage = 15, array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        // Apply any additional pre-query logic
        $this->applyPreQueryHooks('getPaginated', $filters, $sorts);

        $result = parent::getPaginated($page, $perPage, $filters, $sorts);

        // Apply any post-processing
        return $this->applyPostQueryHooks('getPaginated', $result);
    }

    /**
     * Create a new record with extended validation and business rules.
     *
     * @param array $data The data to create the record with
     * @return Model The created model
     * @throws ValidationException If validation fails
     */
    public function create(array $data): Model
    {
        // Validate complex business rules
        $data = $this->validateComplexRules($data);

        // Validate relationships
        $data = $this->validateRelationships($data);

        // Apply business rules
        $data = $this->applyBusinessRules($data);

        // Begin transaction
        $this->beginTransaction();

        try {
            // Create record
            $model = parent::create($data);

            // Handle related data
            $this->handleRelatedData($model->id, $data);

            // Create audit trail
            $this->createAuditTrail('create', $model->id, [], $data);

            // Commit transaction
            $this->commitTransaction();

            return $model;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService create: {$e->getMessage()}", ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Update an existing record with extended validation and business rules.
     *
     * @param int $id The ID of the record to update
     * @param array $data The data to update the record with
     * @return Model|null The updated model or null if not found
     * @throws ValidationException If validation fails
     */
    public function update(int $id, array $data): ?Model
    {
        // Get the record before update for audit trail
        $before = $this->findById($id);

        if (!$before) {
            return null;
        }

        // Validate complex business rules
        $data = $this->validateComplexRules($data, $id);

        // Validate relationships
        $data = $this->validateRelationships($data, $id);

        // Apply business rules
        $data = $this->applyBusinessRules($data, $id);

        $this->beginTransaction();

        try {
            // Update record
            $model = parent::update($id, $data);

            if ($model) {
                // Handle related data
                $this->handleRelatedData($id, $data);

                // Create audit trail
                $this->createAuditTrail(
                    'update',
                    $id,
                    $before->toArray(),
                    $model->toArray()
                );
            }

            $this->commitTransaction();

            return $model;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService update: {$e->getMessage()}", ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    /**
     * Delete a record with additional cleanup logic.
     *
     * @param int $id The ID of the record to delete
     * @return bool True if deletion was successful, false otherwise
     */
    public function delete(int $id): bool
    {
        // Get the record before delete for audit trail
        $before = $this->findById($id);

        if (!$before) {
            return false;
        }

        $this->beginTransaction();

        try {
            // Delete record
            $result = parent::delete($id);

            if ($result) {
                // Create audit trail
                $this->createAuditTrail(
                    'delete',
                    $id,
                    $before->toArray(),
                    []
                );
            }

            $this->commitTransaction();

            return $result;
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService delete: {$e->getMessage()}", ['id' => $id]);
            throw $e;
        }
    }

    /**
     * Create multiple records in a batch operation.
     *
     * @param array $items An array of data items to create
     * @param bool $continueOnError Whether to continue on validation error
     * @return array An array with created models and errors
     */
    public function batchCreate(array $items, bool $continueOnError = false): array
    {
        $results = [
            'created' => [],
            'errors' => []
        ];

        $this->beginTransaction();

        try {
            foreach ($items as $key => $data) {
                try {
                    // Validate and apply rules
                    $data = $this->validateComplexRules($data);
                    $data = $this->validateRelationships($data);
                    $data = $this->applyBusinessRules($data);

                    // Create record
                    $model = $this->repository->create($data);

                    // Handle related data
                    $this->handleRelatedData($model->id, $data);

                    // Create audit trail
                    $this->createAuditTrail('batch-create', $model->id, [], $data);

                    $results['created'][] = $model;
                } catch (\Exception $e) {
                    $results['errors'][$key] = $e->getMessage();
                    if (!$continueOnError) {
                        throw $e;
                    }
                }
            }

            $this->commitTransaction();
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService batchCreate: {$e->getMessage()}", ['items' => $items]);
            throw $e;
        }

        return $results;
    }

    /**
     * Update multiple records in a batch operation.
     *
     * @param array $items An array of data items to update, each must contain an 'id' key
     * @param bool $continueOnError Whether to continue on validation error
     * @return array An array with updated models and errors
     */
    public function batchUpdate(array $items, bool $continueOnError = false): array
    {
        $results = [
            'updated' => [],
            'errors' => []
        ];

        $this->beginTransaction();

        try {
            foreach ($items as $key => $data) {
                if (!isset($data['id'])) {
                    $results['errors'][$key] = "ID field is required for batch update";
                    if (!$continueOnError) {
                        throw new \InvalidArgumentException("ID field is required for batch update");
                    }
                    continue;
                }

                $id = $data['id'];
                unset($data['id']); // Remove ID from data array

                try {
                    // Get the record before update for audit trail
                    $before = $this->findById($id);

                    if (!$before) {
                        $results['errors'][$key] = "Record with ID {$id} not found";
                        if (!$continueOnError) {
                            throw new \InvalidArgumentException("Record with ID {$id} not found");
                        }
                        continue;
                    }

                    // Validate and apply rules
                    $data = $this->validateComplexRules($data, $id);
                    $data = $this->validateRelationships($data, $id);
                    $data = $this->applyBusinessRules($data, $id);

                    // Update record
                    $model = $this->repository->update($id, $data);

                    // Handle related data
                    $this->handleRelatedData($id, $data);

                    // Create audit trail
                    $this->createAuditTrail(
                        'batch-update',
                        $id,
                        $before->toArray(),
                        $model->toArray()
                    );

                    $results['updated'][] = $model;
                } catch (\Exception $e) {
                    $results['errors'][$key] = $e->getMessage();
                    if (!$continueOnError) {
                        throw $e;
                    }
                }
            }

            $this->commitTransaction();
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService batchUpdate: {$e->getMessage()}", ['items' => $items]);
            throw $e;
        }

        return $results;
    }

    /**
     * Delete multiple records in a batch operation.
     *
     * @param array $ids An array of IDs to delete
     * @param bool $continueOnError Whether to continue on error
     * @return array An array with results and errors
     */
    public function batchDelete(array $ids, bool $continueOnError = false): array
    {
        $results = [
            'deleted' => [],
            'errors' => []
        ];

        $this->beginTransaction();

        try {
            foreach ($ids as $key => $id) {
                try {
                    // Get the record before delete for audit trail
                    $before = $this->findById($id);

                    if (!$before) {
                        $results['errors'][$key] = "Record with ID {$id} not found";
                        if (!$continueOnError) {
                            throw new \InvalidArgumentException("Record with ID {$id} not found");
                        }
                        continue;
                    }

                    // Delete record
                    $result = $this->repository->delete($id);

                    if ($result) {
                        // Create audit trail
                        $this->createAuditTrail(
                            'batch-delete',
                            $id,
                            $before->toArray(),
                            []
                        );

                        $results['deleted'][] = $id;
                    } else {
                        $results['errors'][$key] = "Failed to delete record with ID {$id}";
                        if (!$continueOnError) {
                            throw new \RuntimeException("Failed to delete record with ID {$id}");
                        }
                    }
                } catch (\Exception $e) {
                    $results['errors'][$key] = $e->getMessage();
                    if (!$continueOnError) {
                        throw $e;
                    }
                }
            }

            $this->commitTransaction();
        } catch (\Exception $e) {
            $this->rollbackTransaction();
            Log::error("Error in CrudService batchDelete: {$e->getMessage()}", ['ids' => $ids]);
            throw $e;
        }

        return $results;
    }

    /**
     * Export data based on filters and format.
     *
     * @param string $format The export format (csv, xlsx, pdf)
     * @param array $filters An associative array of filter criteria
     * @param array $sorts An associative array of sort criteria
     * @return mixed The exported data or file path depending on format
     * @throws \InvalidArgumentException If format is not supported
     */
    public function export(string $format, array $filters = [], array $sorts = [])
    {
        // Validate export format
        if (!in_array($format, $this->exportConfig['formats'])) {
            throw new \InvalidArgumentException("Export format '{$format}' is not supported");
        }

        // Get data
        $data = $this->getAll($filters, $sorts);

        // Create audit trail for export action
        $this->createAuditTrail(
            'export',
            0,
            ['format' => $format, 'filters' => $filters],
            ['count' => count($data)]
        );

        // Process export based on format
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($data);

            case 'xlsx':
                return $this->exportToExcel($data);

            case 'pdf':
                return $this->exportToPdf($data);

            default:
                throw new \InvalidArgumentException("Export format '{$format}' is not implemented");
        }
    }

    /**
     * Import data from an uploaded file.
     *
     * @param string $format The import format (csv, xlsx)
     * @param UploadedFile $file The uploaded file
     * @param bool $validateOnly Whether to only validate without importing
     * @return array Import results with statistics
     * @throws \InvalidArgumentException If format is not supported
     */
    public function import(string $format, UploadedFile $file, bool $validateOnly = false): array
    {
        // Validate import format
        if (!in_array($format, $this->exportConfig['formats'])) {
            throw new \InvalidArgumentException("Import format '{$format}' is not supported");
        }

        $results = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => [],
            'validation_only' => $validateOnly
        ];

        // Process import based on format
        try {
            switch ($format) {
                case 'csv':
                    $data = $this->parseImportCsv($file);
                    break;

                case 'xlsx':
                    $data = $this->parseImportExcel($file);
                    break;

                default:
                    throw new \InvalidArgumentException("Import format '{$format}' is not implemented");
            }

            // Validate and optionally import data
            $results = $this->processImportData($data, $validateOnly);

            // Create audit trail for import action
            $this->createAuditTrail(
                'import',
                0,
                ['format' => $format, 'filename' => $file->getClientOriginalName()],
                $results
            );

            return $results;
        } catch (\Exception $e) {
            Log::error("Error in CrudService import: {$e->getMessage()}", ['format' => $format]);
            throw $e;
        }
    }

    /**
     * Validate relationships in input data.
     *
     * @param array $data The data to validate
     * @param int|null $id The record ID for updates
     * @return array The validated data
     * @throws ValidationException If validation fails
     */
    public function validateRelationships(array $data, ?int $id = null): array
    {
        $model = $this->repository->getModel();
        $modelClass = get_class($model);

        // Get relationship methods from model
        $relationships = $this->getModelRelationships($model);

        foreach ($relationships as $relation => $type) {
            // Check if relation data exists in input
            if (!isset($data[$relation])) {
                continue;
            }

            // Validate based on relationship type
            switch ($type) {
                case 'BelongsTo':
                    // Check if related model exists
                    $this->validateBelongsTo($relation, $data[$relation]);
                    break;

                case 'HasMany':
                case 'BelongsToMany':
                    // Check if array of IDs is valid
                    $this->validateHasMany($relation, $data[$relation]);
                    break;

                case 'MorphTo':
                    // Check polymorphic relations
                    $this->validateMorphTo($relation, $data[$relation]);
                    break;
            }
        }

        return $data;
    }

    /**
     * Handle related data for a record.
     *
     * @param int $id The ID of the record
     * @param array $relations Array of relation data
     * @return void
     */
    public function handleRelatedData(int $id, array $relations): void
    {
        $model = $this->repository->find($id);

        if (!$model) {
            return;
        }

        $modelRelationships = $this->getModelRelationships($model);

        foreach ($modelRelationships as $relation => $type) {
            // Check if relation data exists in input
            if (!isset($relations[$relation])) {
                continue;
            }

            // Handle based on relationship type
            switch ($type) {
                case 'HasOne':
                    $this->handleHasOne($model, $relation, $relations[$relation]);
                    break;

                case 'HasMany':
                    $this->handleHasMany($model, $relation, $relations[$relation]);
                    break;

                case 'BelongsToMany':
                    $this->handleBelongsToMany($model, $relation, $relations[$relation]);
                    break;

                case 'MorphMany':
                    $this->handleMorphMany($model, $relation, $relations[$relation]);
                    break;
            }
        }
    }

    /**
     * Apply business rules to data.
     *
     * @param array $data The data to apply rules to
     * @param int|null $id The record ID for updates
     * @return array The processed data
     */
    public function applyBusinessRules(array $data, ?int $id = null): array
    {
        foreach ($this->businessRules as $field => $rules) {
            if (!isset($data[$field])) {
                continue;
            }

            foreach ($rules as $rule => $params) {
                switch ($rule) {
                    case 'uppercase':
                        $data[$field] = strtoupper($data[$field]);
                        break;

                    case 'lowercase':
                        $data[$field] = strtolower($data[$field]);
                        break;

                    case 'trim':
                        $data[$field] = trim($data[$field]);
                        break;

                    case 'default':
                        if (empty($data[$field])) {
                            $data[$field] = $params;
                        }
                        break;

                    case 'format':
                        if (is_callable($params)) {
                            $data[$field] = $params($data[$field], $data, $id);
                        }
                        break;
                }
            }
        }

        return $data;
    }

    /**
     * Apply workflow transition to a record.
     *
     * @param int $id The ID of the record
     * @param string $transition The transition name
     * @param array $additionalData Additional data for the transition
     * @return Model|null The updated model or null if transition fails
     * @throws \InvalidArgumentException If transition is not valid
     */
    public function applyWorkflowTransition(int $id, string $transition, array $additionalData = []): ?Model
    {
        $model = $this->findById($id);

        if (!$model) {
            return null;
        }

        // Check if model has a status or state field
        $stateField = $this->getStateField($model);

        if (!$stateField) {
            throw new \InvalidArgumentException("Model does not have a status or state field for workflow");
        }

        $currentState = $model->$stateField;

        // Check if transition is valid from current state
        if (!$this->isValidTransition($currentState, $transition)) {
            throw new \InvalidArgumentException("Transition '{$transition}' is not valid from state '{$currentState}'");
        }

        // Get target state for this transition
        $targetState = $this->getTargetState($currentState, $transition);

        // Merge additional data with state change
        $data = array_merge($additionalData, [$stateField => $targetState]);

        // Update the record
        $model = $this->update($id, $data);

        // Dispatch workflow event
        $this->dispatchEvent('workflow_transition', [
            'model' => $model,
            'transition' => $transition,
            'from_state' => $currentState,
            'to_state' => $targetState
        ]);

        return $model;
    }

    /**
     * Create an audit trail for an action.
     *
     * @param string $action The action performed
     * @param int $id The record ID
     * @param array $before The data before the action
     * @param array $after The data after the action
     * @return void
     */
    public function createAuditTrail(string $action, int $id, array $before, array $after): void
    {
        if (!$this->auditConfig['enabled']) {
            return;
        }

        // Remove excluded fields
        foreach ($this->auditConfig['excluded_fields'] as $field) {
            unset($before[$field], $after[$field]);
        }

        // Calculate changes
        $changes = [];
        foreach ($after as $key => $value) {
            if (!isset($before[$key]) || $before[$key] !== $value) {
                $changes[$key] = [
                    'from' => $before[$key] ?? null,
                    'to' => $value
                ];
            }
        }

        // For deletions, track all removed fields
        if ($action === 'delete' || $action === 'batch-delete') {
            foreach ($before as $key => $value) {
                if (!isset($after[$key])) {
                    $changes[$key] = [
                        'from' => $value,
                        'to' => null
                    ];
                }
            }
        }

        // Log the audit trail
        Log::info("Audit: {$action} on {$this->repository->getModel()->getTable()} ID {$id}", [
            'action' => $action,
            'table' => $this->repository->getModel()->getTable(),
            'record_id' => $id,
            'user_id' => auth()->id() ?? 0,
            'changes' => $changes,
            'timestamp' => now()
        ]);

        // In a real application, you would store this in the database
    }

    /**
     * Validate complex business rules beyond simple Laravel validation.
     *
     * @param array $data The data to validate
     * @param int|null $id The record ID for updates
     * @return array The validated data
     * @throws ValidationException If validation fails
     */
    public function validateComplexRules(array $data, ?int $id = null): array
    {
        $errors = [];

        // Check for conditional validation rules
        foreach ($data as $field => $value) {
            // Example: field_a is required when field_b equals specific value
            if (isset($this->rules['conditional'][$field])) {
                foreach ($this->rules['conditional'][$field] as $condition) {
                    $targetField = $condition['field'];
                    $targetValue = $condition['value'];
                    $rule = $condition['rule'];

                    if (isset($data[$targetField]) && $data[$targetField] == $targetValue) {
                        $validator = Validator::make([$field => $value], [$field => $rule]);

                        if ($validator->fails()) {
                            $errors[$field] = $validator->errors()->first($field);
                        }
                    }
                }
            }

            // Example: custom business logic validation
            if (isset($this->rules['custom'][$field])) {
                $customRule = $this->rules['custom'][$field];

                if (is_callable($customRule)) {
                    $result = $customRule($value, $data, $id);

                    if ($result !== true) {
                        $errors[$field] = $result;
                    }
                }
            }
        }

        // Check for cross-field validation rules
        if (isset($this->rules['cross_field'])) {
            foreach ($this->rules['cross_field'] as $rule) {
                $fields = $rule['fields'];
                $validator = $rule['validator'];

                $fieldData = [];
                $allFieldsPresent = true;

                foreach ($fields as $field) {
                    if (!isset($data[$field])) {
                        $allFieldsPresent = false;
                        break;
                    }

                    $fieldData[$field] = $data[$field];
                }

                if ($allFieldsPresent) {
                    $result = $validator($fieldData, $data, $id);

                    if ($result !== true) {
                        foreach ($fields as $field) {
                            $errors[$field] = $result;
                        }
                    }
                }
            }
        }

        // Throw exception if errors exist
        if (!empty($errors)) {
            $validator = Validator::make($data, []);
            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            throw new ValidationException($validator);
        }

        return $data;
    }

    /**
     * Find records by a specific field with extended functionality.
     *
     * @param string $field The field to search on
     * @param mixed $value The value to search for
     * @param bool $exactMatch Whether to do an exact match or a LIKE search
     * @return Collection The matching records
     */
    public function findByField(string $field, $value, bool $exactMatch = true): Collection
    {
        if ($exactMatch) {
            return $this->repository->findBy($field, $value);
        }

        return $this->searchInField($field, $value);
    }

    /**
     * Find records by multiple fields (AND condition).
     *
     * @param array $criteria An associative array of field => value pairs
     * @return Collection The matching records
     */
    public function findWhere(array $criteria): Collection
    {
        $query = $this->repository->getModel()->newQuery();

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get();
    }

    /**
     * Search for text in specified fields.
     *
     * @param string $searchTerm The text to search for
     * @param array $fields The fields to search in
     * @return Collection The matching records
     */
    public function search(string $searchTerm, array $fields = []): Collection
    {
        $query = $this->repository->getModel()->newQuery();

        if (empty($fields)) {
            // If no fields specified, search in all string fields
            $model = $this->repository->getModel();
            $table = $model->getTable();

            // Get all string columns from the table
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                $type = Schema::getColumnType($table, $column);
                if (in_array($type, ['string', 'text'])) {
                    $fields[] = $column;
                }
            }
        }

        $query->where(function ($q) use ($searchTerm, $fields) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'LIKE', "%{$searchTerm}%");
            }
        });

        return $query->get();
    }

    /**
     * Get records created between two dates.
     *
     * @param \DateTime $startDate The start date
     * @param \DateTime $endDate The end date
     * @param string $dateField The date field to check (defaults to created_at)
     * @return Collection The matching records
     */

    public function getByDateRange(\DateTime $startDate, \DateTime $endDate, string $dateField = 'created_at'): Collection
    {
        $query = $this->repository->getModel()->newQuery();

        return $query->whereBetween($dateField, [$startDate, $endDate])->get();
    }

    /**
     * Get the state/status field name for a model.
     *
     * @param Model $model The model to check
     * @return string|null The state field name or null if not found
     */
    protected function getStateField(Model $model): ?string
    {
        $stateFields = ['status', 'state', 'workflow_status', 'workflow_state'];
        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);

        foreach ($stateFields as $field) {
            if (in_array($field, $columns)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Check if a transition is valid from the current state.
     *
     * @param string $currentState The current state
     * @param string $transition The transition to check
     * @return bool True if the transition is valid
     */
    protected function isValidTransition(string $currentState, string $transition): bool
    {
        if (!isset($this->workflows[$currentState])) {
            return false;
        }

        return isset($this->workflows[$currentState]['transitions'][$transition]);
    }

    /**
     * Get the target state for a transition.
     *
     * @param string $currentState The current state
     * @param string $transition The transition name
     * @return string The target state
     */
    protected function getTargetState(string $currentState, string $transition): string
    {
        return $this->workflows[$currentState]['transitions'][$transition];
    }

    /**
     * Apply pre-query hooks for specific methods.
     *
     * @param string $method The method name
     * @param mixed ...$args The method arguments
     * @return void
     */
    protected function applyPreQueryHooks(string $method, &...$args): void
    {
        $hookMethod = 'before' . ucfirst($method);

        if (method_exists($this, $hookMethod)) {
            $this->$hookMethod(...$args);
        }
    }

    /**
     * Apply post-query hooks for specific methods.
     *
     * @param string $method The method name
     * @param mixed $result The method result
     * @return mixed The modified result
     */
    protected function applyPostQueryHooks(string $method, $result)
    {
        $hookMethod = 'after' . ucfirst($method);

        if (method_exists($this, $hookMethod)) {
            return $this->$hookMethod($result);
        }

        return $result;
    }

    /**
     * Validate a BelongsTo relationship.
     *
     * @param string $relation The relation name
     * @param mixed $value The relation value
     * @return bool True if valid
     * @throws ValidationException If validation fails
     */
    protected function validateBelongsTo(string $relation, $value): bool
    {
        $model = $this->repository->getModel();
        $relationObject = $model->$relation();
        $relatedModel = $relationObject->getRelated();
        $relatedTable = $relatedModel->getTable();

        // Check if the related model exists
        $exists = DB::table($relatedTable)->where('id', $value)->exists();

        if (!$exists) {
            $validator = Validator::make([], []);
            $validator->errors()->add($relation, "Related {$relation} with ID {$value} does not exist");
            throw new ValidationException($validator);
        }

        return true;
    }

    /**
     * Validate HasMany or BelongsToMany relationships.
     *
     * @param string $relation The relation name
     * @param array $values The relation values (array of IDs)
     * @return bool True if valid
     * @throws ValidationException If validation fails
     */
    protected function validateHasMany(string $relation, array $values): bool
    {
        if (empty($values)) {
            return true;
        }

        $model = $this->repository->getModel();
        $relationObject = $model->$relation();
        $relatedModel = $relationObject->getRelated();
        $relatedTable = $relatedModel->getTable();

        // Check if all related models exist
        $foundCount = DB::table($relatedTable)
            ->whereIn('id', $values)
            ->count();

        if ($foundCount !== count($values)) {
            $validator = Validator::make([], []);
            $validator->errors()->add($relation, "One or more {$relation} IDs do not exist");
            throw new ValidationException($validator);
        }

        return true;
    }

    /**
     * Validate MorphTo relationship.
     *
     * @param string $relation The relation name
     * @param array $value The relation value with type and id keys
     * @return bool True if valid
     * @throws ValidationException If validation fails
     */
    protected function validateMorphTo(string $relation, array $value): bool
    {
        if (!isset($value['type']) || !isset($value['id'])) {
            $validator = Validator::make([], []);
            $validator->errors()->add($relation, "Polymorphic relation requires type and id");
            throw new ValidationException($validator);
        }

        $type = $value['type'];
        $id = $value['id'];

        // Check if the model class exists
        if (!class_exists($type)) {
            $validator = Validator::make([], []);
            $validator->errors()->add($relation, "Polymorphic type {$type} does not exist");
            throw new ValidationException($validator);
        }

        // Check if the record exists
        $instance = new $type();
        $exists = DB::table($instance->getTable())->where('id', $id)->exists();

        if (!$exists) {
            $validator = Validator::make([], []);
            $validator->errors()->add($relation, "Related {$type} with ID {$id} does not exist");
            throw new ValidationException($validator);
        }

        return true;
    }

    /**
     * Handle HasOne relationship.
     *
     * @param Model $model The parent model
     * @param string $relation The relation name
     * @param array $data The relation data
     * @return void
     */
    protected function handleHasOne(Model $model, string $relation, array $data): void
    {
        $relationObject = $model->$relation();
        $related = $model->$relation;

        if ($related) {
            // Update existing relation
            $related->update($data);
        } else {
            // Create new relation
            $relationObject->create($data);
        }
    }

    /**
     * Handle HasMany relationship.
     *
     * @param Model $model The parent model
     * @param string $relation The relation name
     * @param array $items The relation data items
     * @return void
     */
    protected function handleHasMany(Model $model, string $relation, array $items): void
    {
        $relationObject = $model->$relation();
        $foreignKey = $relationObject->getForeignKeyName();

        // Get existing IDs
        $existingIds = $model->$relation()->pluck('id')->toArray();
        $newIds = array_column($items, 'id');

        // Items to delete (exist in DB but not in new data)
        $deleteIds = array_diff($existingIds, $newIds);
        if (!empty($deleteIds)) {
            $relationObject->whereIn('id', $deleteIds)->delete();
        }

        // Process each item
        foreach ($items as $item) {
            if (!empty($item['id'])) {
                // Update existing
                $relationObject->where('id', $item['id'])->update($item);
            } else {
                // Create new
                $relationObject->create($item);
            }
        }
    }

    /**
     * Handle BelongsToMany relationship.
     *
     * @param Model $model The parent model
     * @param string $relation The relation name
     * @param array $items The relation data (IDs or arrays with pivot data)
     * @return void
     */
    protected function handleBelongsToMany(Model $model, string $relation, array $items): void
    {
        $relationData = [];

        // Process items based on format
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                // Array with pivot data
                $id = $item['id'];
                unset($item['id']);
                $relationData[$id] = $item; // Remaining data is pivot data
            } else {
                // Just an ID
                $relationData[$item] = [];
            }
        }

        // Sync the relationships
        $model->$relation()->sync($relationData);
    }

    /**
     * Handle MorphMany relationship.
     *
     * @param Model $model The parent model
     * @param string $relation The relation name
     * @param array $items The relation data items
     * @return void
     */
    protected function handleMorphMany(Model $model, string $relation, array $items): void
    {
        $relationObject = $model->$relation();
        $morphType = $relationObject->getMorphType();
        $morphClass = get_class($model);
        $foreignKey = $relationObject->getForeignKeyName();

        // Get existing IDs
        $existingIds = $model->$relation()->pluck('id')->toArray();
        $newIds = array_column($items, 'id');

        // Items to delete (exist in DB but not in new data)
        $deleteIds = array_diff($existingIds, $newIds);
        if (!empty($deleteIds)) {
            $relationObject->whereIn('id', $deleteIds)->delete();
        }

        // Process each item
        foreach ($items as $item) {
            // Add morph data
            $item[$morphType] = $morphClass;
            $item[$foreignKey] = $model->id;

            if (!empty($item['id'])) {
                // Update existing
                $id = $item['id'];
                unset($item['id']);
                $relationObject->where('id', $id)->update($item);
            } else {
                // Create new
                $relationObject->create($item);
            }
        }
    }

    /**
     * Get relationship types from a model.
     *
     * @param Model $model The model to analyze
     * @return array An associative array of relation name => relation type
     */
    protected function getModelRelationships(Model $model): array
    {
        $relationships = [];
        $reflection = new \ReflectionClass($model);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip methods that require parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Try to call the method and check if it returns a relationship
            try {
                $result = $method->invoke($model);
                $returnType = $result ? get_class($result) : null;

                if ($returnType) {
                    $relationTypes = [
                        'Illuminate\\Database\\Eloquent\\Relations\\HasOne' => 'HasOne',
                        'Illuminate\\Database\\Eloquent\\Relations\\HasMany' => 'HasMany',
                        'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo' => 'BelongsTo',
                        'Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany' => 'BelongsToMany',
                        'Illuminate\\Database\\Eloquent\\Relations\\MorphTo' => 'MorphTo',
                        'Illuminate\\Database\\Eloquent\\Relations\\MorphOne' => 'MorphOne',
                        'Illuminate\\Database\\Eloquent\\Relations\\MorphMany' => 'MorphMany',
                    ];

                    foreach ($relationTypes as $class => $type) {
                        if ($result instanceof $class) {
                            $relationships[$method->getName()] = $type;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Not a relationship method or error in method
                continue;
            }
        }

        return $relationships;
    }

    /**
     * Search in a specific field.
     *
     * @param string $field The field to search in
     * @param string $value The search term
     * @return Collection The matching records
     */
    protected function searchInField(string $field, string $value): Collection
    {
        $query = $this->repository->getModel()->newQuery();
        return $query->where($field, 'LIKE', "%{$value}%")->get();
    }

    /**
     * Export data to CSV format.
     *
     * @param Collection $data The data to export
     * @return string The CSV content
     */
    protected function exportToCsv(Collection $data): string
    {
        if ($data->isEmpty()) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Add headers
        $headers = array_keys($data->first()->toArray());
        fputcsv($output, $headers);

        // Add rows
        foreach ($data as $item) {
            fputcsv($output, $item->toArray());
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export data to Excel format.
     *
     * @param Collection $data The data to export
     * @return string The file path to the Excel file
     */
    protected function exportToExcel(Collection $data): string
    {
        // In a real implementation, you would use a library like PhpSpreadsheet
        // This is a placeholder implementation
        $filePath = storage_path('app/exports/' . uniqid('export_') . '.xlsx');

        // Create the directory if it doesn't exist
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // For now, export as CSV (would be replaced with actual Excel export)
        $csv = $this->exportToCsv($data);
        file_put_contents($filePath, $csv);

        return $filePath;
    }

    /**
     * Export data to PDF format.
     *
     * @param Collection $data The data to export
     * @return string The file path to the PDF file
     */
    protected function exportToPdf(Collection $data): string
    {
        // In a real implementation, you would use a PDF generation library like TCPDF or Dompdf
        // This is a placeholder implementation
        $filePath = storage_path('app/exports/' . uniqid('export_') . '.pdf');

        // Create the directory if it doesn't exist
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // For now, create a simple text file (would be replaced with actual PDF export)
        $content = "PDF Export\n\n";
        foreach ($data as $item) {
            $content .= json_encode($item) . "\n";
        }
        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * Parse data from a CSV file.
     *
     * @param UploadedFile $file The uploaded CSV file
     * @return array The parsed data
     */
    protected function parseImportCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $data = [];

        if (($handle = fopen($path, 'r')) !== false) {
            // Get header row
            $headers = fgetcsv($handle, 1000, ',');

            // Read data rows
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $item = [];
                foreach ($headers as $i => $header) {
                    $item[$header] = $row[$i] ?? null;
                }
                $data[] = $item;
            }

            fclose($handle);
        }

        return $data;
    }

    /**
     * Parse data from an Excel file.
     *
     * @param UploadedFile $file The uploaded Excel file
     * @return array The parsed data
     */
    protected function parseImportExcel(UploadedFile $file): array
    {
        // In a real implementation, you would use a library like PhpSpreadsheet
        // This is a placeholder implementation that processes the file as CSV
        return $this->parseImportCsv($file);
    }

    /**
     * Process import data.
     *
     * @param array $data The data to process
     * @param bool $validateOnly Whether to only validate without importing
     * @return array The import results
     */
    protected function processImportData(array $data, bool $validateOnly = false): array
    {
        $results = [
            'processed' => count($data),
            'created' => 0,
            'updated' => 0,
            'errors' => [],
            'validation_only' => $validateOnly
        ];

        foreach ($data as $index => $item) {
            try {
                // Check if this is an update (has ID) or create
                $isUpdate = !empty($item['id']);

                // Validate data
                $validatedData = $this->validateComplexRules($item, $isUpdate ? $item['id'] : null);
                $validatedData = $this->validateRelationships($validatedData, $isUpdate ? $item['id'] : null);
                $validatedData = $this->applyBusinessRules($validatedData, $isUpdate ? $item['id'] : null);

                if (!$validateOnly) {
                    if ($isUpdate) {
                        $id = $item['id'];
                        unset($validatedData['id']);
                        $this->update($id, $validatedData);
                        $results['updated']++;
                    } else {
                        $this->create($validatedData);
                        $results['created']++;
                    }
                }
            } catch (\Exception $e) {
                $results['errors'][$index] = $e->getMessage();
            }
        }

        return $results;
    }
}
