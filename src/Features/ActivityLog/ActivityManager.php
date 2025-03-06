<?php

namespace SwatTech\Crud\Features\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SwatTech\Crud\Services\BaseService;
use Exception;

/**
 * ActivityManager
 *
 * A service class for managing user activity logging in the application.
 * Provides functionality for tracking user actions, generating activity timelines,
 * filtering activities, and analyzing user behavior.
 *
 * @package SwatTech\Crud\Features\ActivityLog
 */
class ActivityManager extends BaseService
{
    /**
     * The repository instance for activity records.
     *
     * @var mixed
     */
    protected $activityRepository;

    /**
     * The repository instance for user data.
     *
     * @var mixed
     */
    protected $userRepository;

    /**
     * Configuration for activity logging functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Activity categories configuration.
     *
     * @var array
     */
    protected $categories = [];

    /**
     * Alert triggers configuration.
     *
     * @var array
     */
    protected $alertTriggers = [];

    /**
     * Create a new ActivityManager instance.
     *
     * @param mixed $activityRepository Repository for activity records
     * @param mixed $userRepository Repository for user data
     * @return void
     */
    public function __construct($activityRepository = null, $userRepository = null)
    {
        $this->activityRepository = $activityRepository;
        $this->userRepository = $userRepository;

        $this->config = config('crud.features.activity_log', [
            'table' => 'activity_logs',
            'user_agent_tracking' => true,
            'ip_tracking' => true,
            'store_request_data' => false,
            'log_anonymous_actions' => false,
            'retention_days' => 90,
            'default_categories' => [
                'auth' => 'Authentication activities',
                'crud' => 'Data manipulation activities',
                'admin' => 'Administrative activities',
                'system' => 'System activities',
            ],
            'enable_analytics' => true,
            'enable_alerting' => false,
            'excluded_paths' => [
                '/health-check',
                '/ping',
                '/favicon.ico',
            ],
        ]);
        
        // Initialize categories with defaults
        $this->categories = $this->config['default_categories'];
    }

    /**
     * Record a user activity.
     *
     * @param string $action The action performed
     * @param int|null $userId The user ID (null for anonymous/system)
     * @param mixed $subject The subject of the activity (model instance, ID, or null)
     * @param array $data Additional activity data
     * @return mixed The created activity record
     * 
     * @throws Exception If activity recording fails
     */
    public function recordActivity(string $action, ?int $userId = null, $subject = null, array $data = [])
    {
        try {
            // Allow current authenticated user if not specified
            if ($userId === null && Auth::check()) {
                $userId = Auth::id();
            }
            
            // If anonymous actions are disabled and no user ID, return early
            if ($userId === null && !$this->config['log_anonymous_actions']) {
                return null;
            }

            // Determine activity category
            $category = $this->determineCategory($action);
            
            // Extract subject data
            $subjectId = null;
            $subjectType = null;
            
            if ($subject !== null) {
                if (is_object($subject)) {
                    $subjectId = method_exists($subject, 'getKey') ? $subject->getKey() : null;
                    $subjectType = get_class($subject);
                } elseif (is_array($subject) && isset($subject['id']) && isset($subject['type'])) {
                    $subjectId = $subject['id'];
                    $subjectType = $subject['type'];
                } elseif (is_numeric($subject)) {
                    $subjectId = $subject;
                }
            }

            // Prepare activity data
            $activityData = [
                'user_id' => $userId,
                'action' => $action,
                'category' => $category,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'data' => !empty($data) ? json_encode($data) : null,
                'created_at' => now(),
            ];

            // Add IP and user agent if enabled
            if ($this->config['ip_tracking'] || $this->config['user_agent_tracking']) {
                $requestData = $this->getRequestData();
                
                if ($this->config['ip_tracking'] && isset($requestData['ip'])) {
                    $activityData['ip_address'] = $requestData['ip'];
                }
                
                if ($this->config['user_agent_tracking'] && isset($requestData['user_agent'])) {
                    $activityData['user_agent'] = $requestData['user_agent'];
                }
            }

            // Store the activity
            $activity = null;
            if ($this->activityRepository) {
                $activity = $this->activityRepository->create($activityData);
            } else {
                $id = DB::table($this->config['table'])->insertGetId($activityData);
                $activity = (object) array_merge(['id' => $id], $activityData);
            }

            // Check alert triggers
            if ($this->config['enable_alerting'] && !empty($this->alertTriggers)) {
                $this->checkAlertTriggers($activity);
            }

            Log::debug("Activity recorded", [
                'action' => $action,
                'user_id' => $userId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);

            return $activity;
        } catch (Exception $e) {
            Log::error("Failed to record activity: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to record activity: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Track user activities from a request.
     *
     * @param Request $request The request to track
     * @param string $action The action name (defaults to route name)
     * @return mixed|null The recorded activity or null
     */
    public function trackUser(Request $request, string $action = null)
    {
        // Skip tracking for excluded paths
        $path = $request->path();
        foreach ($this->config['excluded_paths'] as $excludedPath) {
            if (Str::is($excludedPath, $path)) {
                return null;
            }
        }
        
        // Get current user
        $userId = Auth::check() ? Auth::id() : null;
        
        // If no user and anonymous logging disabled, return early
        if ($userId === null && !$this->config['log_anonymous_actions']) {
            return null;
        }

        // If action not provided, use route name or path
        if ($action === null) {
            $action = $request->route() ? $request->route()->getName() : $request->method() . ' ' . $request->path();
        }
        
        // Determine if we should capture request data
        $requestData = [];
        if ($this->config['store_request_data']) {
            $requestData = [
                'method' => $request->method(),
                'path' => $request->path(),
                'params' => $this->sanitizeRequestData($request->all())
            ];
        }
        
        return $this->recordActivity($action, $userId, null, $requestData);
    }

    /**
     * Configure activity categories for organization and filtering.
     *
     * @param array $categories Array of category definitions
     * @return $this
     */
    public function categorizeActivities(array $categories)
    {
        $this->categories = array_merge($this->categories, $categories);
        
        Log::debug("Activity categories configured", ['categories' => array_keys($categories)]);
        
        return $this;
    }

    /**
     * Store contextual data with activity records.
     *
     * @param array $context The contextual data to store
     * @param string $action The action to associate with this context
     * @param int|null $userId The user ID (null for current user)
     * @return mixed The created activity record
     */
    public function storeContextualData(array $context, string $action = 'context_update', ?int $userId = null)
    {
        if ($userId === null && Auth::check()) {
            $userId = Auth::id();
        }
        
        return $this->recordActivity($action, $userId, null, $context);
    }

    /**
     * Create a visualization of a user's activity timeline.
     *
     * @param int $userId The user ID to generate timeline for
     * @param array $options Timeline customization options
     * @return array The timeline data structure
     */
    public function createTimelineVisualization(int $userId, array $options = [])
    {
        $defaults = [
            'days' => 30,
            'group_by' => 'day',
            'categories' => [],
            'include_subject_details' => false,
            'limit' => 100,
        ];
        
        $options = array_merge($defaults, $options);
        
        // Calculate date range
        $endDate = now();
        $startDate = (clone $endDate)->subDays($options['days']);
        
        // Get activities
        $activities = $this->getUserActivities(
            $userId, 
            $startDate, 
            $endDate, 
            $options['categories'], 
            $options['limit']
        );
        
        // Group activities
        $groupedActivities = [];
        
        if ($options['group_by'] === 'day') {
            $format = 'Y-m-d';
        } elseif ($options['group_by'] === 'hour') {
            $format = 'Y-m-d H:00';
        } else {
            // Default to daily
            $format = 'Y-m-d';
        }
        
        foreach ($activities as $activity) {
            $dateKey = Carbon::parse($activity->created_at)->format($format);
            
            if (!isset($groupedActivities[$dateKey])) {
                $groupedActivities[$dateKey] = [];
            }
            
            // Add subject details if requested
            if ($options['include_subject_details'] && $activity->subject_id && $activity->subject_type) {
                $activity->subject_details = $this->getSubjectDetails($activity->subject_type, $activity->subject_id);
            }
            
            $groupedActivities[$dateKey][] = $activity;
        }
        
        // Build the timeline structure
        $timeline = [
            'user_id' => $userId,
            'start_date' => $startDate->toDateTimeString(),
            'end_date' => $endDate->toDateTimeString(),
            'total_activities' => count($activities),
            'groups' => []
        ];
        
        // Sort keys chronologically
        ksort($groupedActivities);
        
        // Add each group to the timeline
        foreach ($groupedActivities as $dateKey => $activities) {
            $timeline['groups'][] = [
                'date' => $dateKey,
                'count' => count($activities),
                'activities' => $activities
            ];
        }
        
        Log::info("Timeline generated for user", [
            'user_id' => $userId,
            'period' => $options['days'] . ' days',
            'activities_count' => count($activities)
        ]);
        
        return $timeline;
    }

    /**
     * Set up filtering for activity logs.
     *
     * @param array $filters Filter criteria
     * @return Collection Filtered activity records
     */
    public function setupFiltering(array $filters)
    {
        $query = DB::table($this->config['table']);
        
        // Apply user filter
        if (isset($filters['user_id']) && $filters['user_id'] !== null) {
            $query->where('user_id', $filters['user_id']);
        }
        
        // Apply date range filter
        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date']));
        }
        
        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date'])->endOfDay());
        }
        
        // Apply action filter
        if (isset($filters['action']) && $filters['action']) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }
        
        // Apply category filter
        if (isset($filters['category']) && $filters['category']) {
            $query->where('category', $filters['category']);
        }
        
        // Apply subject type filter
        if (isset($filters['subject_type']) && $filters['subject_type']) {
            $query->where('subject_type', $filters['subject_type']);
        }
        
        // Apply subject ID filter
        if (isset($filters['subject_id']) && $filters['subject_id']) {
            $query->where('subject_id', $filters['subject_id']);
        }
        
        // Apply IP address filter
        if (isset($filters['ip_address']) && $filters['ip_address']) {
            $query->where('ip_address', $filters['ip_address']);
        }
        
        // Apply pagination
        $perPage = $filters['per_page'] ?? 15;
        $page = $filters['page'] ?? 1;
        
        // Get paginated results
        $activities = $query->orderBy('created_at', 'desc')
                          ->paginate($perPage, ['*'], 'page', $page);
        
        Log::debug("Activity logs filtered", [
            'filters' => $filters,
            'results_count' => $activities->count(),
            'total_results' => $activities->total()
        ]);
        
        return $activities;
    }

    /**
     * Configure retention policy for activity logs.
     *
     * @param int $days Number of days to retain logs (0 for unlimited)
     * @return int The number of deleted records
     */
    public function configureRetention(int $days)
    {
        if ($days <= 0) {
            // Unlimited retention, don't delete anything
            return 0;
        }
        
        $cutoffDate = now()->subDays($days);
        
        try {
            $deletedCount = DB::table($this->config['table'])
                ->where('created_at', '<', $cutoffDate)
                ->delete();
            
            Log::info("Activity log retention policy executed", [
                'days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'records_deleted' => $deletedCount
            ]);
            
            return $deletedCount;
        } catch (Exception $e) {
            Log::error("Failed to apply activity log retention policy: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to apply activity log retention policy: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Export activity logs to various formats.
     *
     * @param string $format The export format (csv, excel, json, pdf)
     * @param array $filters Filters to apply before export
     * @return mixed The exported data or a download response
     * 
     * @throws Exception If export format is not supported
     */
    public function implementExport(string $format, array $filters = [])
    {
        // Get filtered data without pagination
        $query = DB::table($this->config['table']);
        
        // Apply user filter
        if (isset($filters['user_id']) && $filters['user_id'] !== null) {
            $query->where('user_id', $filters['user_id']);
        }
        
        // Apply date range filter
        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date']));
        }
        
        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date'])->endOfDay());
        }
        
        // Apply other filters (action, category, etc.)
        foreach (['action', 'category', 'subject_type', 'subject_id', 'ip_address'] as $field) {
            if (isset($filters[$field]) && $filters[$field]) {
                $query->where($field, 'like', '%' . $filters[$field] . '%');
            }
        }
        
        // Get the data
        $data = $query->orderBy('created_at', 'desc')->get();
        
        // Transform data for export
        $exportData = $data->map(function ($item) {
            $user = $this->userRepository ? 
                    $this->userRepository->find($item->user_id) : 
                    DB::table('users')->where('id', $item->user_id)->first();
                    
            $userName = $user ? ($user->name ?? 'Unknown') : 'System';
            
            return [
                'id' => $item->id,
                'user' => $userName,
                'action' => $item->action,
                'category' => $item->category,
                'subject_type' => $item->subject_type ?? 'N/A',
                'subject_id' => $item->subject_id ?? 'N/A',
                'ip_address' => $item->ip_address ?? 'N/A',
                'created_at' => $item->created_at,
                'data' => $item->data ? json_encode(json_decode($item->data, true)) : 'N/A'
            ];
        })->toArray();
        
        // Generate the export based on format
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCsv($exportData);
                
            case 'json':
                return json_encode($exportData);
                
            case 'excel':
                // This is a placeholder - you would typically use Laravel Excel or similar package
                throw new Exception("Excel export requires laravel-excel package. Please install it.");
                
            case 'pdf':
                // This is a placeholder - you would typically use a PDF library
                throw new Exception("PDF export requires a PDF generation library. Please install one.");
                
            default:
                throw new Exception("Unsupported export format: {$format}");
        }
    }

    /**
     * Set up analytics capabilities for user activities.
     *
     * @param array $options Analytics configuration options
     * @return array Analytics results
     */
    public function setupAnalytics(array $options = [])
    {
        if (!$this->config['enable_analytics']) {
            return ['enabled' => false];
        }
        
        $defaultOptions = [
            'period' => 30, // days
            'metrics' => ['users', 'actions', 'categories'],
            'group_by' => 'day',
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        $endDate = now();
        $startDate = (clone $endDate)->subDays($options['period']);
        
        $results = [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'metrics' => [],
        ];
        
        // Format for date grouping
        $dateFormat = match($options['group_by']) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d'
        };
        
        // Get total counts
        $results['metrics']['total'] = [
            'activities' => DB::table($this->config['table'])
                              ->whereBetween('created_at', [$startDate, $endDate])
                              ->count(),
                              
            'users' => DB::table($this->config['table'])
                         ->whereBetween('created_at', [$startDate, $endDate])
                         ->whereNotNull('user_id')
                         ->distinct('user_id')
                         ->count('user_id'),
                         
            'actions' => DB::table($this->config['table'])
                            ->whereBetween('created_at', [$startDate, $endDate])
                            ->distinct('action')
                            ->count('action'),
        ];
        
        // Get top users
        if (in_array('users', $options['metrics'])) {
            $results['metrics']['top_users'] = DB::table($this->config['table'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('user_id')
                ->select('user_id', DB::raw('COUNT(*) as count'))
                ->groupBy('user_id')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    $user = $this->userRepository ? 
                            $this->userRepository->find($item->user_id) : 
                            DB::table('users')->where('id', $item->user_id)->first();
                    
                    return [
                        'user_id' => $item->user_id,
                        'name' => $user ? ($user->name ?? 'Unknown') : 'System',
                        'count' => $item->count,
                    ];
                });
        }
        
        // Get top actions
        if (in_array('actions', $options['metrics'])) {
            $results['metrics']['top_actions'] = DB::table($this->config['table'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select('action', DB::raw('COUNT(*) as count'))
                ->groupBy('action')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get();
        }
        
        // Get activity by category
        if (in_array('categories', $options['metrics'])) {
            $results['metrics']['categories'] = DB::table($this->config['table'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select('category', DB::raw('COUNT(*) as count'))
                ->groupBy('category')
                ->orderBy('count', 'desc')
                ->get();
        }
        
        // Get activity over time
        $results['metrics']['timeline'] = DB::table($this->config['table'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as date"), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
            
        Log::info("Activity analytics generated", [
            'period' => $options['period'],
            'total_activities' => $results['metrics']['total']['activities'],
            'group_by' => $options['group_by']
        ]);
        
        return $results;
    }

    /**
     * Configure alerting rules for specific user activities.
     *
     * @param array $triggers Alert trigger definitions
     * @return $this
     */
    public function configureAlerting(array $triggers)
    {
        if (!$this->config['enable_alerting']) {
            Log::warning("Activity alerting is disabled in configuration");
            return $this;
        }
        
        $this->alertTriggers = $triggers;
        
        Log::debug("Activity alerting configured", ['triggers_count' => count($triggers)]);
        
        return $this;
    }

    /**
     * Get activities for a specific user.
     *
     * @param int $userId The user ID
     * @param Carbon $startDate Start date for activities
     * @param Carbon $endDate End date for activities
     * @param array $categories Categories to filter by
     * @param int $limit Maximum number of records to return
     * @return Collection The user's activities
     */
    protected function getUserActivities(int $userId, Carbon $startDate, Carbon $endDate, array $categories = [], int $limit = 100)
    {
        $query = DB::table($this->config['table'])
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->limit($limit);
            
        if (!empty($categories)) {
            $query->whereIn('category', $categories);
        }
        
        return $query->get();
    }

    /**
     * Get subject details for an activity.
     *
     * @param string $subjectType The subject model class
     * @param mixed $subjectId The subject ID
     * @return object|null The subject information
     */
    protected function getSubjectDetails(string $subjectType, $subjectId)
    {
        try {
            $model = app($subjectType);
            $instance = $model->find($subjectId);
            
            if (!$instance) {
                return null;
            }
            
            // Return a standard representation
            return (object) [
                'id' => $instance->id,
                'title' => $instance->name ?? $instance->title ?? $instance->id,
                'summary' => method_exists($instance, 'getActivitySummary') ? 
                             $instance->getActivitySummary() : 
                             $this->generateSubjectSummary($instance),
            ];
        } catch (Exception $e) {
            Log::warning("Could not get subject details for activity", [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate a summary for an activity subject.
     *
     * @param object $subject The subject instance
     * @return string A summary of the subject
     */
    protected function generateSubjectSummary(object $subject): string
    {
        $fields = ['name', 'title', 'description', 'email', 'username'];
        
        foreach ($fields as $field) {
            if (isset($subject->$field)) {
                return Str::limit($subject->$field, 100);
            }
        }
        
        return "ID: " . $subject->getKey();
    }

    /**
     * Sanitize request data to remove sensitive information.
     *
     * @param array $requestData The raw request data
     * @return array Sanitized data
     */
    protected function sanitizeRequestData(array $requestData): array
    {
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'auth', 'key', 'secret', 'credit_card'];
        
        $sanitized = [];
        
        foreach ($requestData as $key => $value) {
            // Skip sensitive fields
            if (in_array(strtolower($key), $sensitiveFields) || Str::contains(strtolower($key), $sensitiveFields)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            
            // Handle nested arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeRequestData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Get information from the current request.
     *
     * @return array Request data including IP and user agent
     */
    protected function getRequestData(): array
    {
        $data = [];
        
        if (app()->has('request')) {
            $request = app('request');
            
            if ($this->config['ip_tracking']) {
                $data['ip'] = $request->ip();
            }
            
            if ($this->config['user_agent_tracking']) {
                $data['user_agent'] = $request->header('User-Agent');
            }
        }
        
        return $data;
    }

    /**
     * Determine the category for an action.
     *
     * @param string $action The action name
     * @return string The category name
     */
    protected function determineCategory(string $action): string
    {
        // Auth related actions
        if (Str::startsWith($action, ['login', 'logout', 'register', 'password.'])) {
            return 'auth';
        }
        
        // CRUD operations
        if (Str::endsWith($action, ['.create', '.store', '.update', '.edit', '.destroy', '.delete'])) {
            return 'crud';
        }
        
        // Admin actions
        if (Str::startsWith($action, ['admin', 'settings'])) {
            return 'admin';
        }
        
        return 'general';
    }

    /**
     * Export data to CSV format.
     *
     * @param array $data The data to export
     * @return string The CSV content
     */
    protected function exportToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Add headers
        fputcsv($output, array_keys($data[0]));
        
        // Add data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Check if an activity triggers any alerts.
     *
     * @param object $activity The activity to check
     * @return void
     */
    protected function checkAlertTriggers(object $activity): void
    {
        foreach ($this->alertTriggers as $trigger) {
            $matches = true;
            
            // Check conditions
            foreach ($trigger['conditions'] as $field => $value) {
                if (!property_exists($activity, $field) || $activity->$field !== $value) {
                    $matches = false;
                    break;
                }
            }
            
            if ($matches) {
                // Execute the alert action
                if (isset($trigger['callback']) && is_callable($trigger['callback'])) {
                    call_user_func($trigger['callback'], $activity);
                }
                
                Log::notice("Activity alert triggered", [
                    'trigger' => $trigger['name'] ?? 'unnamed',
                    'activity_id' => $activity->id,
                    'action' => $activity->action
                ]);
            }
        }
    }
}