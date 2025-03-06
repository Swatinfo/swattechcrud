<?php

namespace SwatTech\Crud\Features\Notifications;

use SwatTech\Crud\Services\BaseService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Exception;

/**
 * NotificationManager
 *
 * A service class for managing notification templates and delivery.
 * Supports multiple channels, template customization, scheduling,
 * personalization, and user preferences.
 *
 * @package SwatTech\Crud\Features\Notifications
 */
class NotificationManager extends BaseService
{
    /**
     * The repository instance for template storage.
     *
     * @var mixed
     */
    protected $templateRepository;

    /**
     * The repository instance for notification tracking.
     *
     * @var mixed
     */
    protected $notificationRepository;

    /**
     * The repository instance for user preferences.
     *
     * @var mixed
     */
    protected $preferenceRepository;

    /**
     * Configuration for notification functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Available notification channels.
     *
     * @var array
     */
    protected $availableChannels = [
        'mail',
        'database',
        'broadcast',
        'slack',
        'sms',
        'push'
    ];

    /**
     * Placeholder patterns and their validators.
     *
     * @var array
     */
    protected $placeholders = [];

    /**
     * Throttling configuration.
     *
     * @var array|null
     */
    protected $throttlingConfig = null;

    /**
     * Create a new NotificationManager instance.
     *
     * @param mixed $templateRepository Repository for notification templates
     * @param mixed $notificationRepository Repository for notification records
     * @param mixed $preferenceRepository Repository for user preferences
     * @return void
     */
    public function __construct($templateRepository = null, $notificationRepository = null, $preferenceRepository = null)
    {
        $this->templateRepository = $templateRepository;
        $this->notificationRepository = $notificationRepository;
        $this->preferenceRepository = $preferenceRepository;

        $this->config = config('crud.features.notifications', [
            'default_channels' => ['mail', 'database'],
            'enable_scheduling' => true,
            'enable_tracking' => true,
            'enable_preferences' => true,
            'throttle' => [
                'enabled' => true,
                'default_limit' => 5,
                'default_period' => 'hour'
            ],
            'templates_table' => 'notification_templates',
            'notifications_table' => 'notifications',
            'preferences_table' => 'notification_preferences',
        ]);
    }

    /**
     * Create a notification template.
     *
     * @param string $name Template name/identifier
     * @param array $channels Notification channels for this template
     * @param string $content Template content with placeholder markers
     * @param array $options Additional template options
     * @return mixed The created template
     * 
     * @throws Exception If template creation fails
     */
    public function createTemplate(string $name, array $channels, string $content, array $options = [])
    {
        // Validate channels
        $validChannels = array_intersect($channels, $this->availableChannels);
        if (empty($validChannels)) {
            throw new Exception("No valid notification channels specified");
        }

        try {
            // Prepare template data
            $templateData = [
                'name' => $name,
                'slug' => Str::slug($name),
                'channels' => json_encode($validChannels),
                'content' => $content,
                'subject' => $options['subject'] ?? $name,
                'active' => $options['active'] ?? true,
                'metadata' => isset($options['metadata']) ? json_encode($options['metadata']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Store the template
            if ($this->templateRepository) {
                $template = $this->templateRepository->create($templateData);
            } else {
                $template = DB::table($this->config['templates_table'])->insertGetId($templateData);
            }

            // Extract placeholders from content
            $extractedPlaceholders = $this->extractPlaceholders($content);
            if (!empty($extractedPlaceholders)) {
                // Store placeholder information with the template if needed
                if (isset($options['validate_placeholders']) && $options['validate_placeholders']) {
                    $this->configurePlaceholders($extractedPlaceholders);
                }
            }

            Log::info("Notification template created: {$name}");

            return $template;
        } catch (Exception $e) {
            Log::error("Failed to create notification template: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to create notification template: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up channel handling for notifications.
     *
     * @param array $channels Array of channel configurations
     * @return $this
     * 
     * @throws Exception If channel setup fails
     */
    public function setupChannelHandling(array $channels)
    {
        try {
            foreach ($channels as $channel => $config) {
                if (!in_array($channel, $this->availableChannels)) {
                    Log::warning("Unsupported notification channel: {$channel}");
                    continue;
                }

                // Store channel-specific configurations
                switch ($channel) {
                    case 'mail':
                        // Configure mail settings
                        $mailConfig = $config['mail'] ?? [];
                        if (!empty($mailConfig)) {
                            // You would typically configure mail-specific settings here
                            // For example, default from address, email layout templates, etc.
                        }
                        break;

                    case 'slack':
                        // Configure Slack webhook
                        if (isset($config['webhook_url'])) {
                            config(['services.slack.webhook_url' => $config['webhook_url']]);
                        }
                        break;

                    case 'sms':
                        // Configure SMS provider
                        $smsConfig = $config['sms'] ?? [];
                        if (!empty($smsConfig) && isset($smsConfig['provider'])) {
                            // Configure SMS provider settings
                        }
                        break;

                    case 'push':
                        // Configure push notification service
                        $pushConfig = $config['push'] ?? [];
                        if (!empty($pushConfig)) {
                            // Configure push notification settings
                        }
                        break;

                    case 'database':
                    case 'broadcast':
                        // These typically use Laravel's default configuration
                        break;
                }
            }

            Log::info("Notification channels configured", ['channels' => array_keys($channels)]);

            return $this;
        } catch (Exception $e) {
            Log::error("Failed to setup notification channels: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to setup notification channels: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Configure placeholders for notification templates.
     *
     * @param array $placeholders Array of placeholder definitions
     * @return $this
     */
    public function configurePlaceholders(array $placeholders)
    {
        foreach ($placeholders as $placeholder => $config) {
            // If config is just a string (description), convert to array
            if (is_string($config)) {
                $config = ['description' => $config];
            }

            $this->placeholders[$placeholder] = array_merge([
                'required' => false,
                'default' => null,
                'validator' => null,
                'description' => 'Placeholder for ' . $placeholder,
            ], $config);
        }

        Log::info("Notification placeholders configured", ['count' => count($placeholders)]);

        return $this;
    }

    /**
     * Schedule a notification for future delivery.
     *
     * @param string $templateId ID or slug of the notification template
     * @param array $recipients Recipients who should receive the notification
     * @param Carbon $sendAt When the notification should be sent
     * @param array $data Data to populate template placeholders
     * @return mixed The scheduled notification record
     * 
     * @throws Exception If scheduling is disabled or fails
     */
    public function scheduleNotification(string $templateId, array $recipients, Carbon $sendAt, array $data = [])
    {
        if (!$this->config['enable_scheduling']) {
            throw new Exception("Notification scheduling is disabled in configuration");
        }

        if ($sendAt->isPast()) {
            throw new Exception("Cannot schedule notification in the past");
        }

        try {
            // Fetch the template
            $template = $this->getTemplate($templateId);
            if (!$template) {
                throw new Exception("Notification template not found: {$templateId}");
            }

            // Validate data against placeholders if configured
            if (!empty($this->placeholders)) {
                $this->validatePlaceholders($data);
            }

            // Create schedule record
            $scheduleData = [
                'template_id' => is_object($template) ? $template->id : $template,
                'recipients' => json_encode($recipients),
                'data' => json_encode($data),
                'scheduled_at' => $sendAt->toDateTimeString(),
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($this->notificationRepository) {
                $schedule = $this->notificationRepository->create($scheduleData);
            } else {
                $schedule = DB::table($this->config['notifications_table'])->insertGetId($scheduleData);
            }

            Log::info("Notification scheduled", [
                'template' => $templateId,
                'scheduled_at' => $sendAt->toDateTimeString(),
                'recipients_count' => count($recipients),
            ]);

            return $schedule;
        } catch (Exception $e) {
            Log::error("Failed to schedule notification: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to schedule notification: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Implement grouping for multiple notifications.
     *
     * @param array $notifications Array of notification data to group
     * @param array $options Grouping options
     * @return array Grouped notifications
     */
    public function implementGrouping(array $notifications, array $options = [])
    {
        $groupingStrategy = $options['strategy'] ?? 'time';
        $maxGroupSize = $options['max_group_size'] ?? 5;
        $groupingWindow = $options['window'] ?? 60; // minutes

        $groupedNotifications = [];

        switch ($groupingStrategy) {
            case 'time':
                // Group by time window
                $timeGroups = [];
                foreach ($notifications as $notification) {
                    $timestamp = isset($notification['created_at'])
                        ? Carbon::parse($notification['created_at'])
                        : Carbon::now();

                    $timeKey = $timestamp->startOfHour()->format('Y-m-d H:i');

                    if (!isset($timeGroups[$timeKey])) {
                        $timeGroups[$timeKey] = [];
                    }

                    $timeGroups[$timeKey][] = $notification;
                }

                // Now create the actual groups
                foreach ($timeGroups as $time => $items) {
                    $chunks = array_chunk($items, $maxGroupSize);
                    foreach ($chunks as $index => $group) {
                        $groupedNotifications[] = [
                            'title' => count($group) > 1
                                ? count($group) . " notifications from " . $time
                                : "Notification from " . $time,
                            'items' => $group,
                            'count' => count($group),
                            'timestamp' => $time,
                        ];
                    }
                }
                break;

            case 'type':
                // Group by notification type
                $typeGroups = [];
                foreach ($notifications as $notification) {
                    $type = $notification['type'] ?? 'general';

                    if (!isset($typeGroups[$type])) {
                        $typeGroups[$type] = [];
                    }

                    $typeGroups[$type][] = $notification;
                }

                // Convert type groups to output format
                foreach ($typeGroups as $type => $items) {
                    $chunks = array_chunk($items, $maxGroupSize);
                    foreach ($chunks as $index => $group) {
                        $groupedNotifications[] = [
                            'title' => count($group) > 1
                                ? count($group) . " {$type} notifications"
                                : "1 {$type} notification",
                            'items' => $group,
                            'count' => count($group),
                            'type' => $type,
                        ];
                    }
                }
                break;

            case 'sender':
                // Group by sender
                $senderGroups = [];
                foreach ($notifications as $notification) {
                    $sender = $notification['sender'] ?? 'system';

                    if (!isset($senderGroups[$sender])) {
                        $senderGroups[$sender] = [];
                    }

                    $senderGroups[$sender][] = $notification;
                }

                // Convert sender groups to output format
                foreach ($senderGroups as $sender => $items) {
                    $chunks = array_chunk($items, $maxGroupSize);
                    foreach ($chunks as $index => $group) {
                        $groupedNotifications[] = [
                            'title' => count($group) > 1
                                ? count($group) . " notifications from {$sender}"
                                : "Notification from {$sender}",
                            'items' => $group,
                            'count' => count($group),
                            'sender' => $sender,
                        ];
                    }
                }
                break;

            default:
                // No grouping, just chunk it
                $chunks = array_chunk($notifications, $maxGroupSize);
                foreach ($chunks as $index => $group) {
                    $groupedNotifications[] = [
                        'title' => "Notification group " . ($index + 1),
                        'items' => $group,
                        'count' => count($group)
                    ];
                }
        }

        Log::info("Notifications grouped", [
            'strategy' => $groupingStrategy,
            'original_count' => count($notifications),
            'group_count' => count($groupedNotifications)
        ]);

        return $groupedNotifications;
    }

    /**
     * Set up personalization for notifications.
     *
     * @param array $userData User data for personalization
     * @return array Personalized content and settings
     */
    public function setupPersonalization(array $userData)
    {
        // Extract common user attributes for personalization
        $defaults = [
            'name' => $userData['name'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'greeting' => null,
            'language' => $userData['language'] ?? 'en',
            'timezone' => $userData['timezone'] ?? 'UTC',
            'preferences' => $userData['preferences'] ?? [],
        ];

        // Generate greeting based on time of day and user name
        if (isset($userData['timezone'])) {
            $userTime = Carbon::now()->timezone($userData['timezone']);
            $hour = (int) $userTime->format('H');

            $greeting = 'Hello';
            if ($hour < 12) {
                $greeting = 'Good morning';
            } elseif ($hour < 18) {
                $greeting = 'Good afternoon';
            } else {
                $greeting = 'Good evening';
            }

            if (isset($userData['first_name'])) {
                $greeting .= ', ' . $userData['first_name'];
            } elseif (isset($userData['name'])) {
                $greeting .= ', ' . $userData['name'];
            }

            $defaults['greeting'] = $greeting;
        }

        // Format date/times according to user locale
        $dateFormat = 'Y-m-d';
        $timeFormat = 'H:i';

        if (isset($userData['language'])) {
            switch ($userData['language']) {
                case 'en-US':
                    $dateFormat = 'm/d/Y';
                    $timeFormat = 'h:i A';
                    break;
                case 'en-GB':
                    $dateFormat = 'd/m/Y';
                    $timeFormat = 'H:i';
                    break;
                case 'fr':
                    $dateFormat = 'd/m/Y';
                    $timeFormat = 'H:i';
                    break;
                case 'de':
                    $dateFormat = 'd.m.Y';
                    $timeFormat = 'H:i';
                    break;
                    // Add more locale-specific formats as needed
            }
        }

        $personalization = [
            'user' => $defaults,
            'formats' => [
                'date' => $dateFormat,
                'time' => $timeFormat,
                'datetime' => $dateFormat . ' ' . $timeFormat,
            ],
            'channels' => $this->getPreferredChannels($userData),
        ];

        Log::info("Notification personalization prepared", [
            'user_id' => $userData['id'] ?? 'unknown',
            'language' => $userData['language'] ?? 'en',
            'timezone' => $userData['timezone'] ?? 'UTC',
        ]);

        return $personalization;
    }

    /**
     * Configure tracking for notification delivery and engagement.
     *
     * @param array $options Tracking options
     * @return $this
     * 
     * @throws Exception If tracking is disabled
     */
    public function configureTracking(array $options = [])
    {
        if (!$this->config['enable_tracking']) {
            throw new Exception("Notification tracking is disabled in configuration");
        }

        $trackingConfig = array_merge([
            'track_opens' => true,
            'track_clicks' => true,
            'track_deliveries' => true,
            'attribution_window' => 30, // days
            'pixel_tracking' => true,
            'track_conversions' => false,
            'store_user_agent' => true,
            'store_ip_address' => false, // Default false for privacy
        ], $options);

        // Apply configuration to the instance
        $this->config['tracking'] = $trackingConfig;

        Log::info("Notification tracking configured", ['options' => array_keys($trackingConfig)]);

        return $this;
    }

    /**
     * Set up throttling to limit notification frequency.
     *
     * @param int $limit Maximum number of notifications
     * @param string $period Time period for the limit (minute, hour, day)
     * @return $this
     */
    public function setupThrottling(int $limit, string $period)
    {
        $validPeriods = ['minute', 'hour', 'day', 'week'];
        if (!in_array(strtolower($period), $validPeriods)) {
            throw new Exception("Invalid throttling period. Valid periods are: " . implode(', ', $validPeriods));
        }

        $this->throttlingConfig = [
            'enabled' => true,
            'limit' => $limit,
            'period' => strtolower($period),
        ];

        Log::info("Notification throttling configured", [
            'limit' => $limit,
            'period' => $period
        ]);

        return $this;
    }

    /**
     * Manage user notification preferences.
     *
     * @param int $userId User ID
     * @param array $preferences Notification preferences
     * @return array Updated preferences
     * 
     * @throws Exception If preference management fails
     */
    public function managePreferences(int $userId, array $preferences)
    {
        if (!$this->config['enable_preferences']) {
            throw new Exception("Notification preferences are disabled in configuration");
        }

        try {
            // Get existing preferences
            $existingPreferences = [];

            if ($this->preferenceRepository) {
                $existing = $this->preferenceRepository->findBy('user_id', $userId);
                $existingPreferences = $existing ? json_decode($existing->preferences, true) : [];
            } else {
                $existing = DB::table($this->config['preferences_table'])
                    ->where('user_id', $userId)
                    ->first();
                $existingPreferences = $existing ? json_decode($existing->preferences, true) : [];
            }

            // Merge with new preferences
            $mergedPreferences = array_merge($existingPreferences, $preferences);

            // Ensure we have channel preferences
            if (!isset($mergedPreferences['channels'])) {
                $mergedPreferences['channels'] = $this->config['default_channels'];
            }

            // Save to database
            $preferenceData = [
                'user_id' => $userId,
                'preferences' => json_encode($mergedPreferences),
                'updated_at' => now(),
            ];

            if ($existing) {
                if ($this->preferenceRepository) {
                    $this->preferenceRepository->update($existing->id, $preferenceData);
                } else {
                    DB::table($this->config['preferences_table'])
                        ->where('id', $existing->id)
                        ->update($preferenceData);
                }
            } else {
                $preferenceData['created_at'] = now();

                if ($this->preferenceRepository) {
                    $this->preferenceRepository->create($preferenceData);
                } else {
                    DB::table($this->config['preferences_table'])->insert($preferenceData);
                }
            }

            Log::info("User notification preferences updated", [
                'user_id' => $userId,
                'preference_count' => count($mergedPreferences)
            ]);

            return $mergedPreferences;
        } catch (Exception $e) {
            Log::error("Failed to manage notification preferences: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to manage notification preferences: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create tools for testing notifications.
     *
     * @param array $options Testing options
     * @return array Testing tools and utilities
     */
    public function createTestingTools(array $options = [])
    {
        $tools = [
            'preview' => function ($templateId, $data = []) {
                // Generate a preview of the notification without sending it
                $template = $this->getTemplate($templateId);
                if (!$template) {
                    throw new Exception("Template not found");
                }

                $content = is_object($template) ? $template->content : $template['content'];
                return $this->parsePlaceholders($content, $data);
            },

            'test' => function ($templateId, $recipients, $data = []) {
                // Send a test notification that won't be tracked or counted against throttling
                $template = $this->getTemplate($templateId);
                if (!$template) {
                    throw new Exception("Template not found");
                }

                // Ensure template content is accessible regardless of template format
                $content = is_object($template) ? $template->content : $template['content'];
                $subject = is_object($template) ? $template->subject : $template['subject'];
                $channels = is_object($template) ? json_decode($template->channels, true) : json_decode($template['channels'], true);

                // Create test notification
                $testNotification = new TestNotification($subject, $content, $data, $channels);

                // Send to test recipients
                Notification::send($recipients, $testNotification);

                return [
                    'success' => true,
                    'message' => 'Test notification sent',
                    'recipients' => count($recipients),
                    'channels' => $channels
                ];
            },

            'validate' => function ($templateId, $data = []) {
                // Validate data against template placeholders
                $template = $this->getTemplate($templateId);
                if (!$template) {
                    throw new Exception("Template not found");
                }

                $content = is_object($template) ? $template->content : $template['content'];
                $placeholders = $this->extractPlaceholders($content);

                $missingRequired = [];
                $invalidValues = [];

                foreach ($placeholders as $placeholder) {
                    // Check if placeholder is required and missing
                    $placeholderConfig = $this->placeholders[$placeholder] ?? null;
                    if ($placeholderConfig && isset($placeholderConfig['required']) && $placeholderConfig['required']) {
                        if (!isset($data[$placeholder]) || $data[$placeholder] === '') {
                            $missingRequired[] = $placeholder;
                        }
                    }

                    // Check if value is valid according to validator
                    if (isset($data[$placeholder]) && isset($placeholderConfig['validator']) && is_callable($placeholderConfig['validator'])) {
                        if (!call_user_func($placeholderConfig['validator'], $data[$placeholder])) {
                            $invalidValues[$placeholder] = $data[$placeholder];
                        }
                    }
                }

                return [
                    'valid' => empty($missingRequired) && empty($invalidValues),
                    'missing_required' => $missingRequired,
                    'invalid_values' => $invalidValues,
                    'placeholders_found' => $placeholders
                ];
            },

            'channels' => $this->availableChannels,
        ];

        if (isset($options['include_templates']) && $options['include_templates']) {
            // Include a list of available templates
            if ($this->templateRepository) {
                $tools['templates'] = $this->templateRepository->all();
            } else {
                $tools['templates'] = DB::table($this->config['templates_table'])
                    ->where('active', true)
                    ->get();
            }
        }

        Log::info("Notification testing tools created");

        return $tools;
    }

    /**
     * Get a notification template by ID or slug.
     *
     * @param string $templateId ID or slug of the template
     * @return mixed Template object/array or null if not found
     */
    protected function getTemplate(string $templateId)
    {
        if ($this->templateRepository) {
            // Try to find by ID first
            $template = $this->templateRepository->find($templateId);

            // If not found, try by slug
            if (!$template && !is_numeric($templateId)) {
                $template = $this->templateRepository->findBy('slug', $templateId);
            }

            return $template;
        } else {
            // Use direct DB queries
            $template = DB::table($this->config['templates_table'])
                ->where('id', $templateId)
                ->orWhere('slug', $templateId)
                ->first();

            return $template;
        }
    }

    /**
     * Extract placeholders from template content.
     *
     * @param string $content Template content
     * @return array Extracted placeholder names
     */
    protected function extractPlaceholders(string $content)
    {
        $matches = [];
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);

        $placeholders = [];
        if (!empty($matches[1])) {
            foreach ($matches[1] as $placeholder) {
                $placeholders[] = trim($placeholder);
            }
        }

        return $placeholders;
    }

    /**
     * Replace placeholders in content with actual values.
     *
     * @param string $content Template content
     * @param array $data Data for replacement
     * @return string Processed content
     */
    protected function parsePlaceholders(string $content, array $data)
    {
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        // Remove any remaining placeholders
        $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);

        return $content;
    }

    /**
     * Validate data against required placeholders.
     *
     * @param array $data Data to validate
     * @return bool True if validation passes
     * 
     * @throws Exception If validation fails
     */
    protected function validatePlaceholders(array $data)
    {
        $missingRequired = [];
        $invalidValues = [];

        foreach ($this->placeholders as $placeholder => $config) {
            // Check required placeholders
            if ($config['required'] && (!isset($data[$placeholder]) || $data[$placeholder] === '')) {
                $missingRequired[] = $placeholder;
            }

            // Check validators
            if (isset($data[$placeholder]) && isset($config['validator']) && is_callable($config['validator'])) {
                if (!call_user_func($config['validator'], $data[$placeholder])) {
                    $invalidValues[$placeholder] = $data[$placeholder];
                }
            }
        }

        if (!empty($missingRequired)) {
            throw new Exception("Missing required placeholders: " . implode(', ', $missingRequired));
        }

        if (!empty($invalidValues)) {
            $invalidList = [];
            foreach ($invalidValues as $key => $value) {
                $invalidList[] = "{$key}: {$value}";
            }
            throw new Exception("Invalid placeholder values: " . implode(', ', $invalidList));
        }

        return true;
    }

    /**
     * Get user's preferred notification channels.
     *
     * @param array $userData User data containing preferences
     * @return array Preferred channels
     */
    protected function getPreferredChannels(array $userData)
    {
        // Default to system defaults
        $channels = $this->config['default_channels'];

        // Override with user preferences if available
        if (isset($userData['preferences']['channels'])) {
            $channels = $userData['preferences']['channels'];
        }

        // Ensure channels are valid
        $validChannels = array_intersect($channels, $this->availableChannels);

        return empty($validChannels) ? $this->config['default_channels'] : $validChannels;
    }

    /**
     * Check if notification should be throttled.
     *
     * @param int $userId User ID
     * @param string $type Notification type
     * @return bool True if notification should be throttled
     */
    protected function shouldThrottle(int $userId, string $type): bool
    {
        if (!$this->throttlingConfig || !$this->throttlingConfig['enabled']) {
            return false;
        }

        $limit = $this->throttlingConfig['limit'];
        $period = $this->throttlingConfig['period'];

        // Calculate the time window based on the period
        $since = null;
        switch ($period) {
            case 'minute':
                $since = Carbon::now()->subMinute();
                break;
            case 'hour':
                $since = Carbon::now()->subHour();
                break;
            case 'day':
                $since = Carbon::now()->subDay();
                break;
            case 'week':
                $since = Carbon::now()->subWeek();
                break;
            default:
                $since = Carbon::now()->subHour(); // Default to hour if period is invalid
        }

        // Count notifications in the period
        $count = 0;

        if ($this->notificationRepository) {
            $count = $this->notificationRepository->countByUserAndType($userId, $type, $since);
        } else {
            $count = DB::table($this->config['notifications_table'])
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('created_at', '>=', $since)
                ->count();
        }

        // Return true if we should throttle (count exceeds limit)
        return $count >= $limit;
    }

    /**
     * Track notification delivery for throttling and analytics.
     *
     * @param int $userId User ID
     * @param string $type Notification type
     * @param string $channel Channel used for delivery
     * @return void
     */
    protected function trackDelivery(int $userId, string $type, string $channel): void
    {
        if (!$this->config['enable_tracking']) {
            return;
        }

        $trackingData = [
            'user_id' => $userId,
            'type' => $type,
            'channel' => $channel,
            'delivered_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ];

        if ($this->notificationRepository) {
            $this->notificationRepository->trackDelivery($trackingData);
        } else {
            DB::table($this->config['notifications_table'] . '_tracking')->insert($trackingData);
        }

        Log::debug("Notification delivery tracked", [
            'user_id' => $userId,
            'type' => $type,
            'channel' => $channel
        ]);
    }

    /**
     * Send a notification using the specified template.
     * 
     * @param string $templateId ID or slug of the template
     * @param mixed $recipients Recipients who should receive the notification
     * @param array $data Data to populate template placeholders
     * @param array $options Additional options for sending
     * @return array Result of the send operation
     * 
     * @throws Exception If sending fails
     */
    public function send(string $templateId, $recipients, array $data = [], array $options = [])
    {
        try {
            // Get template
            $template = $this->getTemplate($templateId);
            if (!$template) {
                throw new Exception("Notification template not found: {$templateId}");
            }

            // Validate data against placeholders if configured
            if (!empty($this->placeholders)) {
                $this->validatePlaceholders($data);
            }

            // Get channels from template
            $channels = is_object($template)
                ? json_decode($template->channels, true)
                : json_decode($template['channels'], true);

            // Override channels if specified in options
            if (isset($options['channels']) && !empty($options['channels'])) {
                $channels = array_intersect($options['channels'], $this->availableChannels);

                if (empty($channels)) {
                    throw new Exception("No valid notification channels specified in options");
                }
            }

            // Handle throttling if enabled
            $skipThrottling = $options['skip_throttling'] ?? false;
            $type = $options['type'] ?? (is_object($template) ? $template->slug : $template['slug']);

            if (!$skipThrottling && $this->throttlingConfig && $this->throttlingConfig['enabled']) {
                if (is_array($recipients)) {
                    foreach ($recipients as $recipient) {
                        if (is_object($recipient) && method_exists($recipient, 'getKey')) {
                            $userId = $recipient->getKey();
                            if ($this->shouldThrottle($userId, $type)) {
                                Log::info("Notification throttled for user", [
                                    'user_id' => $userId,
                                    'type' => $type
                                ]);
                                continue; // Skip this recipient
                            }
                        }
                    }
                } elseif (is_object($recipients) && method_exists($recipients, 'getKey')) {
                    $userId = $recipients->getKey();
                    if ($this->shouldThrottle($userId, $type)) {
                        Log::info("Notification throttled for user", [
                            'user_id' => $userId,
                            'type' => $type
                        ]);
                        return [
                            'success' => false,
                            'message' => 'Notification throttled',
                            'recipients' => 0
                        ];
                    }
                }
            }

            // Create notification
            $subject = is_object($template) ? $template->subject : $template['subject'];
            $content = is_object($template) ? $template->content : $template['content'];
            $notification = new \SwatTech\Crud\Notifications\DatabaseNotification(
                $subject,
                $this->parsePlaceholders($content, $data),
                $data,
                $channels
            );

            // Send the notification
            Notification::send($recipients, $notification);

            // Track delivery if enabled
            if ($this->config['enable_tracking']) {
                if (is_array($recipients)) {
                    foreach ($recipients as $recipient) {
                        if (is_object($recipient) && method_exists($recipient, 'getKey')) {
                            foreach ($channels as $channel) {
                                $this->trackDelivery($recipient->getKey(), $type, $channel);
                            }
                        }
                    }
                } elseif (is_object($recipients) && method_exists($recipients, 'getKey')) {
                    foreach ($channels as $channel) {
                        $this->trackDelivery($recipients->getKey(), $type, $channel);
                    }
                }
            }

            // Return success
            $recipientCount = is_array($recipients) ? count($recipients) : 1;
            Log::info("Notification sent", [
                'template' => $templateId,
                'recipients' => $recipientCount,
                'channels' => $channels
            ]);

            return [
                'success' => true,
                'message' => 'Notification sent successfully',
                'recipients' => $recipientCount,
                'channels' => $channels
            ];
        } catch (Exception $e) {
            Log::error("Failed to send notification: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to send notification: {$e->getMessage()}", 0, $e);
        }
    }
}