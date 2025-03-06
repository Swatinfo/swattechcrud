<?php

namespace SwatTech\Crud\Features\AuditTrail;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use SwatTech\Crud\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use SwatTech\Crud\Contracts\RepositoryInterface;

/**
 * AuditManager
 *
 * A service class for managing audit trails of model changes in the application.
 * Provides functionality for tracking model changes, user actions, and generating
 * audit reports and timelines.
 *
 * @package SwatTech\Crud\Features\AuditTrail
 */
class AuditManager extends BaseService
{
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
     * Configuration for audit trail functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Fields to exclude from audit trail.
     *
     * @var array
     */
    protected $excludedFields = [
        'created_at',
        'updated_at',
        'password',
        'remember_token'
    ];

    /**
     * Create a new AuditManager instance.
     *
     * @param RepositoryInterface $modelRepository
     * @param RepositoryInterface $userRepository
     * @return void
     */
    public function __construct(RepositoryInterface $modelRepository, RepositoryInterface $userRepository)
    {
        $this->modelRepository = $modelRepository;
        $this->userRepository = $userRepository;
        $this->config = config('crud.features.audit_trail', [
            'enabled' => true,
            'retention_days' => 90,
            'log_ip_address' => true,
            'log_user_agent' => true,
            'include_request_data' => false,
            'versioning_enabled' => true
        ]);
    }

    /**
     * Create an audit record for a model change.
     *
     * @param string $action The type of action performed (create, update, delete, restore)
     * @param \Illuminate\Database\Eloquent\Model $model The model being modified
     * @param array $changes Array containing 'before' and 'after' states of the model
     * @return mixed The audit record that was created
     */
    public function createAuditRecord(string $action, Model $model, array $changes = [])
    {
        if (!$this->config['enabled']) {
            return null;
        }

        $user = $this->trackUser();
        
        $auditData = [
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'changes' => $this->formatChanges($changes),
            'created_at' => Carbon::now()
        ];

        // Add IP and User Agent information if enabled
        if ($this->config['log_ip_address'] || $this->config['log_user_agent']) {
            $auditData = array_merge($auditData, $this->logIpAndAgent());
        }

        // Store request data if enabled
        if ($this->config['include_request_data']) {
            $auditData['request_data'] = $this->getRequestData();
        }

        // Create the audit entry
        $audit = DB::table('audit_trails')->insertGetId($auditData);

        // Log the audit action
        Log::info("Audit: {$action} on " . class_basename($model) . " (ID: {$model->id}) by User ID: " . ($user ? $user->id : 'System'));

        // Create a version record if versioning is enabled
        if ($this->config['versioning_enabled'] && in_array($action, ['create', 'update'])) {
            $this->implementVersioning($model, $audit, $changes);
        }

        return $audit;
    }

    /**
     * Get the current authenticated user or return null.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function trackUser()
    {
        return Auth::user();
    }

    /**
     * Detect changes between before and after states of a model.
     *
     * @param array $before The model state before changes
     * @param array $after The model state after changes
     * @return array The detected changes
     */
    public function detectChanges(array $before, array $after)
    {
        $changes = [];
        
        // Remove excluded fields
        foreach ($this->excludedFields as $field) {
            unset($before[$field], $after[$field]);
        }
        
        // Detect added and modified fields
        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before)) {
                $changes[$key] = [
                    'old' => null,
                    'new' => $value
                ];
            } elseif ($before[$key] !== $value) {
                $changes[$key] = [
                    'old' => $before[$key],
                    'new' => $value
                ];
            }
        }
        
        // Detect removed fields
        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after)) {
                $changes[$key] = [
                    'old' => $value,
                    'new' => null
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Capture IP address and User Agent information from the current request.
     *
     * @return array The IP and User Agent data
     */
    public function logIpAndAgent()
    {
        $data = [];
        
        if ($this->config['log_ip_address']) {
            $data['ip_address'] = Request::ip();
        }
        
        if ($this->config['log_user_agent']) {
            $data['user_agent'] = Request::header('User-Agent');
        }
        
        return $data;
    }

    /**
     * Implement versioning for models to track their complete state history.
     *
     * @param \Illuminate\Database\Eloquent\Model $model The model to version
     * @param int $auditId The associated audit record ID
     * @param array $changes The changes that triggered the version
     * @return mixed The version record
     */
    public function implementVersioning(Model $model, int $auditId, array $changes)
    {
        // Create a version record with complete model state
        return DB::table('model_versions')->insertGetId([
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'audit_id' => $auditId,
            'data' => json_encode($model->getAttributes()),
            'created_at' => Carbon::now()
        ]);
    }

    /**
     * Create an audit timeline for a specific model.
     *
     * @param int $modelId The model ID to create timeline for
     * @param string $modelType The model class name
     * @param array $options Additional options for the timeline query
     * @return \Illuminate\Support\Collection The timeline collection
     */
    public function createAuditTimeline(int $modelId, string $modelType, array $options = [])
    {
        $query = DB::table('audit_trails')
            ->where('model_id', $modelId)
            ->where('model_type', $modelType)
            ->orderBy('created_at', 'desc');

        if (isset($options['user_id'])) {
            $query->where('user_id', $options['user_id']);
        }

        if (isset($options['action'])) {
            $query->where('action', $options['action']);
        }

        if (isset($options['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($options['from_date']));
        }

        if (isset($options['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($options['to_date']));
        }

        $timeline = $query->get();

        // Enhance timeline with user information
        $userIds = $timeline->pluck('user_id')->filter()->unique();
        $users = $this->userRepository->findWhereIn('id', $userIds->toArray());

        return $timeline->map(function ($item) use ($users) {
            if ($item->user_id) {
                $user = $users->firstWhere('id', $item->user_id);
                $item->user = $user ? $user->only(['id', 'name', 'email']) : null;
            }
            
            return $item;
        });
    }

    /**
     * Set up functionality to restore a model to a previous state.
     *
     * @param int $modelId The model ID to restore
     * @param string $modelType The model class name
     * @param int $versionId The version ID to restore to
     * @return bool Whether the restoration was successful
     */
    public function setupRestoration(int $modelId, string $modelType, int $versionId)
    {
        // Find the version record
        $version = DB::table('model_versions')
            ->where('id', $versionId)
            ->where('model_id', $modelId)
            ->where('model_type', $modelType)
            ->first();
            
        if (!$version) {
            return false;
        }

        // Get the current model state for audit purposes
        $model = app($modelType)->findOrFail($modelId);
        $beforeState = $model->getAttributes();
        
        // Update model with version data
        $versionData = json_decode($version->data, true);
        $model->fill($versionData);
        
        // Save the model
        $result = $model->save();
        
        if ($result) {
            // Create an audit record for the restoration
            $this->createAuditRecord('restore_version', $model, [
                'before' => $beforeState,
                'after' => $model->getAttributes(),
                'restored_version_id' => $versionId
            ]);
        }
        
        return $result;
    }

    /**
     * Add search and filtering capabilities to audit logs.
     *
     * @param array $filters Search and filter parameters
     * @param int $page Page number for pagination
     * @param int $perPage Items per page
     * @return \Illuminate\Pagination\LengthAwarePaginator Paginated results
     */
    public function addSearchAndFiltering(array $filters = [], int $page = 1, int $perPage = 15)
    {
        $query = DB::table('audit_trails');

        // Apply filters
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('model_type', 'like', $searchTerm)
                  ->orWhere('action', 'like', $searchTerm)
                  ->orWhere('changes', 'like', $searchTerm);
            });
        }
        
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        
        if (!empty($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }
        
        if (!empty($filters['model_id'])) {
            $query->where('model_id', $filters['model_id']);
        }
        
        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date']));
        }
        
        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date']));
        }
        
        // Apply sorting
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);
        
        // Get paginated results
        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
                       ->take($perPage)
                       ->get();
        
        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => Request::url(), 'query' => Request::query()]
        );
    }

    /**
     * Implement retention policies for audit logs.
     *
     * @param int|null $days Number of days to retain audit logs (null for config default)
     * @return int Number of records deleted
     */
    public function implementRetentionPolicies(int $days = null)
    {
        if ($days === null) {
            $days = $this->config['retention_days'] ?? 90;
        }
        
        if ($days <= 0) {
            return 0; // Don't delete if days is 0 or negative
        }
        
        $cutoffDate = Carbon::now()->subDays($days);
        
        // Delete audit records older than the cutoff date
        $auditDeleted = DB::table('audit_trails')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
            
        // Delete associated version records
        $versionsDeleted = DB::table('model_versions')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
            
        Log::info("Audit retention policy executed: {$auditDeleted} audit records and {$versionsDeleted} version records deleted (older than {$days} days)");
        
        return $auditDeleted + $versionsDeleted;
    }

    /**
     * Create functionality to export audit logs in various formats.
     *
     * @param string $format The export format (csv, excel, json, pdf)
     * @param array $filters Filters to apply before export
     * @return mixed The exported data or a download response
     */
    public function createExportFunctionality(string $format, array $filters = [])
    {
        // Get filtered data without pagination
        $query = DB::table('audit_trails');
        
        // Apply filters
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        
        if (!empty($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }
        
        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date']));
        }
        
        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date']));
        }
        
        $data = $query->orderBy('created_at', 'desc')->get();
        
        // Transform data for export
        $exportData = $data->map(function ($item) {
            return [
                'id' => $item->id,
                'user_id' => $item->user_id,
                'action' => $item->action,
                'model_type' => $item->model_type,
                'model_id' => $item->model_id,
                'changes' => is_string($item->changes) ? $item->changes : json_encode($item->changes),
                'ip_address' => $item->ip_address ?? null,
                'created_at' => $item->created_at
            ];
        });

        // Generate the export based on format
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCsv($exportData);
                
            case 'excel':
                return $this->exportToExcel($exportData);
                
            case 'pdf':
                return $this->exportToPdf($exportData);
                
            case 'json':
            default:
                return response()->json($exportData);
        }
    }
    
    /**
     * Format changes for storage in the database.
     *
     * @param array $changes The changes to format
     * @return string JSON encoded changes
     */
    protected function formatChanges(array $changes)
    {
        // Filter out excluded fields
        foreach ($this->excludedFields as $field) {
            if (isset($changes['before'][$field])) {
                unset($changes['before'][$field]);
            }
            if (isset($changes['after'][$field])) {
                unset($changes['after'][$field]);
            }
        }
        
        return json_encode($changes);
    }
    
    /**
     * Get relevant request data for auditing.
     *
     * @return array Request data
     */
    protected function getRequestData()
    {
        return [
            'url' => Request::url(),
            'method' => Request::method(),
            'inputs' => $this->filterSensitiveInputs(Request::except(['password', 'password_confirmation', 'token'])),
            'headers' => $this->filterSensitiveHeaders(Request::header())
        ];
    }
    
    /**
     * Filter sensitive data from request inputs.
     *
     * @param array $inputs Request inputs
     * @return array Filtered inputs
     */
    protected function filterSensitiveInputs(array $inputs)
    {
        $sensitiveFields = array_merge(
            $this->excludedFields, 
            ['token', 'api_token', 'password', 'secret']
        );
        
        foreach ($sensitiveFields as $field) {
            if (isset($inputs[$field])) {
                $inputs[$field] = '[REDACTED]';
            }
        }
        
        return $inputs;
    }
    
    /**
     * Filter sensitive data from request headers.
     *
     * @param array $headers Request headers
     * @return array Filtered headers
     */
    protected function filterSensitiveHeaders(array $headers)
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'php-auth-user',
            'php-auth-pw'
        ];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = '[REDACTED]';
            }
        }
        
        return $headers;
    }
    
    /**
     * Export data to CSV format.
     *
     * @param Collection $data The data to export
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function exportToCsv(Collection $data)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit_log_' . date('Y-m-d') . '.csv"',
        ];
        
        return response()->stream(function () use ($data) {
            $handle = fopen('php://output', 'w');
            
            // Add header row
            if ($data->count() > 0) {
                fputcsv($handle, array_keys($data->first()));
            }
            
            // Add data rows
            foreach ($data as $row) {
                fputcsv($handle, $row);
            }
            
            fclose($handle);
        }, 200, $headers);
    }
    
    /**
     * Export data to Excel format.
     *
     * Note: This is a simplified implementation. In a real application,
     * you might use a package like PhpSpreadsheet or Laravel Excel.
     *
     * @param Collection $data The data to export
     * @return mixed
     */
    protected function exportToExcel(Collection $data)
    {
        // Placeholder for Excel export logic
        // In a real implementation, you'd use Laravel Excel or similar
        return $this->exportToCsv($data); // Fallback to CSV for demo
    }
    
    /**
     * Export data to PDF format.
     *
     * Note: This is a simplified implementation. In a real application,
     * you might use a package like DomPDF or Snappy PDF.
     *
     * @param Collection $data The data to export
     * @return mixed
     */
    protected function exportToPdf(Collection $data)
    {
        // Placeholder for PDF export logic
        // In a real implementation, you'd use a PDF generation library
        return response()->json([
            'message' => 'PDF export functionality requires a PDF generation library',
            'data' => $data
        ]);
    }
}