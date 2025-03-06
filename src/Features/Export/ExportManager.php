<?php

namespace SwatTech\Crud\Features\Export;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SwatTech\Crud\Services\BaseService;
use Carbon\Carbon;
use Exception;

/**
 * ExportManager
 *
 * A service class for managing data exports in various formats.
 * Supports CSV, Excel, and PDF exports with customization options,
 * background processing, scheduling, and security features.
 *
 * @package SwatTech\Crud\Features\Export
 */
class ExportManager extends BaseService
{
    /**
     * The repository instance for handling data access.
     *
     * @var mixed
     */
    protected $repository;

    /**
     * The model class name for export meta information.
     *
     * @var string
     */
    protected $modelClass;

    /**
     * Configuration for export functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Default export options.
     *
     * @var array
     */
    protected $defaultOptions = [
        'includeHeaders' => true,
        'delimiter' => ',',
        'enclosure' => '"',
        'filename' => null,
        'disk' => 'local',
        'directory' => 'exports',
        'orientation' => 'portrait',
        'paperSize' => 'a4',
        'enableCompression' => false,
        'includeTimestamp' => true,
        'batchSize' => 1000,
    ];

    /**
     * Create a new ExportManager instance.
     *
     * @param mixed $repository The repository instance for data access
     * @param string|null $modelClass The model class name
     * @return void
     */
    public function __construct($repository = null, string $modelClass = null)
    {
        $this->repository = $repository;
        $this->modelClass = $modelClass;
        $this->config = config('crud.features.export', [
            'formats' => ['csv', 'excel', 'pdf'],
            'enable_background_processing' => true,
            'enable_scheduling' => true,
            'enable_notifications' => true,
            'security' => [
                'enable_encryption' => false,
                'enable_password_protection' => false,
                'enable_permissions' => true,
            ],
            'max_records_direct_download' => 5000,
            'expiry_days' => 7,
        ]);
    }

    /**
     * Export data to CSV format.
     *
     * @param array|Collection $data The data to export
     * @param array $options Export options
     * @return string|bool Path to the exported file or boolean on direct output
     * 
     * @throws Exception If the export fails
     */
    public function exportToCsv($data, array $options = [])
    {
        $options = array_merge($this->defaultOptions, $options);
        $data = $this->prepareDataForExport($data, $options);

        try {
            // Generate a filename if not provided
            if (empty($options['filename'])) {
                $options['filename'] = $this->generateFileName('csv', $options);
            }

            $path = $options['directory'] . '/' . $options['filename'];
            $fullPath = Storage::disk($options['disk'])->path($path);
            
            // Create directory if it doesn't exist
            $directory = dirname($fullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Open file for writing
            $handle = fopen($fullPath, 'w');
            
            // Add BOM for Excel compatibility if needed
            if (!empty($options['useBom'])) {
                fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            }
            
            // Write headers if required
            if ($options['includeHeaders'] && !empty($data)) {
                $headers = array_keys(reset($data));
                fputcsv($handle, $headers, $options['delimiter'], $options['enclosure']);
            }
            
            // Write data rows
            foreach ($data as $row) {
                fputcsv($handle, $row, $options['delimiter'], $options['enclosure']);
            }
            
            fclose($handle);

            // Compress the file if requested
            if ($options['enableCompression']) {
                $zipPath = $options['directory'] . '/' . pathinfo($options['filename'], PATHINFO_FILENAME) . '.zip';
                $this->compressFile($path, $zipPath, $options['disk']);
                $path = $zipPath;
            }

            Log::info("CSV export completed: {$path}");
            
            return Storage::disk($options['disk'])->url($path);
        } catch (Exception $e) {
            Log::error("CSV export failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to export data to CSV: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Export data to Excel format.
     *
     * @param array|Collection $data The data to export
     * @param array $options Export options
     * @return string Path to the exported file
     * 
     * @throws Exception If the export fails
     */
    public function exportToExcel($data, array $options = [])
    {
        $options = array_merge($this->defaultOptions, $options);
        $data = $this->prepareDataForExport($data, $options);

        try {
            // Generate a filename if not provided
            if (empty($options['filename'])) {
                $options['filename'] = $this->generateFileName('xlsx', $options);
            }

            // In a real implementation, you would use Laravel Excel or PhpSpreadsheet
            // This is a simplified example using a library placeholder
            if (class_exists('\Maatwebsite\Excel\Facades\Excel')) {
                // Using Laravel Excel package
                $export = new \App\Exports\DataExport($data, $options);
                \Maatwebsite\Excel\Facades\Excel::store(
                    $export,
                    $options['filename'],
                    $options['disk'],
                    \Maatwebsite\Excel\Excel::XLSX
                );
            } else {
                // Fallback to CSV if Excel package is not available
                Log::warning("Excel export requested but Laravel Excel package is not installed. Falling back to CSV.");
                return $this->exportToCsv($data, array_merge($options, ['filename' => str_replace('.xlsx', '.csv', $options['filename'])]));
            }

            $path = $options['directory'] . '/' . $options['filename'];
            
            // Compress the file if requested
            if ($options['enableCompression']) {
                $zipPath = $options['directory'] . '/' . pathinfo($options['filename'], PATHINFO_FILENAME) . '.zip';
                $this->compressFile($path, $zipPath, $options['disk']);
                $path = $zipPath;
            }

            Log::info("Excel export completed: {$path}");
            
            return Storage::disk($options['disk'])->url($path);
        } catch (Exception $e) {
            Log::error("Excel export failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to export data to Excel: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Export data to PDF format.
     *
     * @param array|Collection $data The data to export
     * @param array $options Export options
     * @return string Path to the exported file
     * 
     * @throws Exception If the export fails
     */
    public function exportToPdf($data, array $options = [])
    {
        $options = array_merge($this->defaultOptions, $options);
        $data = $this->prepareDataForExport($data, $options);

        try {
            // Generate a filename if not provided
            if (empty($options['filename'])) {
                $options['filename'] = $this->generateFileName('pdf', $options);
            }

            // In a real implementation, you would use a PDF library like DOMPDF, TCPDF, or Snappy
            // This is a simplified example using a library placeholder
            if (class_exists('\Barryvdh\DomPDF\Facade')) {
                $pdf = \Barryvdh\DomPDF\Facade::loadView('exports.data-table', [
                    'data' => $data,
                    'headers' => !empty($data) ? array_keys(reset($data)) : [],
                    'title' => $options['title'] ?? 'Data Export',
                    'options' => $options
                ]);
                
                // Set PDF options
                $pdf->setPaper($options['paperSize'], $options['orientation']);
                
                // Generate and save the PDF
                $path = $options['directory'] . '/' . $options['filename'];
                Storage::disk($options['disk'])->put($path, $pdf->output());
            } else {
                // Fallback to CSV if PDF library is not available
                Log::warning("PDF export requested but PDF library is not installed. Falling back to CSV.");
                return $this->exportToCsv($data, array_merge($options, ['filename' => str_replace('.pdf', '.csv', $options['filename'])]));
            }
            
            // Compress the file if requested
            if ($options['enableCompression']) {
                $zipPath = $options['directory'] . '/' . pathinfo($options['filename'], PATHINFO_FILENAME) . '.zip';
                $this->compressFile($path, $zipPath, $options['disk']);
                $path = $zipPath;
            }

            Log::info("PDF export completed: {$path}");
            
            return Storage::disk($options['disk'])->url($path);
        } catch (Exception $e) {
            Log::error("PDF export failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to export data to PDF: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up template customization for exports.
     *
     * @param string $format The export format (csv, excel, pdf)
     * @param mixed $template Template file path or template data
     * @return $this
     * 
     * @throws Exception If the template format is not supported
     */
    public function setupTemplateCustomization(string $format, $template)
    {
        if (!in_array($format, $this->config['formats'])) {
            throw new Exception("Format '{$format}' is not supported for template customization");
        }

        // Store template in the session for future use
        session()->put("export.template.{$format}", $template);
        
        Log::info("Template customization set up for {$format} format");

        return $this;
    }

    /**
     * Add column selection for export.
     *
     * @param array $columns Array of columns to include in the export
     * @return $this
     */
    public function addColumnSelection(array $columns)
    {
        // Store selected columns in the session for future use
        session()->put("export.columns", $columns);
        
        Log::info("Column selection set up for export", ['columns' => $columns]);

        return $this;
    }

    /**
     * Implement filters and sorting for data export.
     *
     * @param array $filters Filters to apply to the data
     * @param array $sorts Sorting rules to apply to the data
     * @return $this
     */
    public function implementFiltersAndSorting(array $filters = [], array $sorts = [])
    {
        // Store filters and sorts in the session for future use
        session()->put("export.filters", $filters);
        session()->put("export.sorts", $sorts);
        
        Log::info("Filters and sorting set up for export", [
            'filters' => $filters,
            'sorts' => $sorts
        ]);

        return $this;
    }

    /**
     * Create background processing for large exports.
     *
     * @param array $data The data to export
     * @param string $format Export format (csv, excel, pdf)
     * @param array $options Export options
     * @return string Job ID for tracking the export
     * 
     * @throws Exception If background processing is not enabled
     */
    public function createBackgroundProcessing($data, string $format, array $options = [])
    {
        if (!$this->config['enable_background_processing']) {
            throw new Exception("Background processing is disabled in configuration");
        }

        // Generate a unique job ID
        $jobId = (string) Str::uuid();
        
        // Create an export job and dispatch it to the queue
        // In a real implementation, you'd use a proper job class
        $exportJob = [
            'id' => $jobId,
            'data' => $data instanceof Collection ? $data->toArray() : $data,
            'format' => $format,
            'options' => $options,
            'created_at' => now(),
        ];
        
        // Store job info for status tracking
        Cache::put("export.job.{$jobId}", [
            'status' => 'queued',
            'progress' => 0,
            'format' => $format,
            'created_at' => now(),
        ], now()->addDays(1));
        
        // Dispatch to queue (placeholder for actual implementation)
        // In a real application, you'd use Laravel's queue system
        // Queue::push(new ExportJob($exportJob));
        
        Log::info("Background export job created", ['job_id' => $jobId, 'format' => $format]);
        
        return $jobId;
    }

    /**
     * Set up notifications for export completion.
     *
     * @param array $options Notification options (channels, recipients, etc.)
     * @return $this
     * 
     * @throws Exception If notifications are not enabled
     */
    public function setupNotification(array $options = [])
    {
        if (!$this->config['enable_notifications']) {
            throw new Exception("Notifications are disabled in configuration");
        }

        $defaultOptions = [
            'channels' => ['mail'],
            'recipient' => auth()->user(),
            'subject' => 'Export Completed',
            'message' => 'Your export has been completed and is ready for download.',
        ];
        
        $notificationOptions = array_merge($defaultOptions, $options);
        
        // Store notification options in the session for use when export completes
        session()->put("export.notification", $notificationOptions);
        
        Log::info("Export notifications set up", ['options' => $notificationOptions]);

        return $this;
    }

    /**
     * Implement scheduled exports.
     *
     * @param string $frequency Cron expression for scheduling (e.g., '0 0 * * *' for daily at midnight)
     * @param array $options Scheduling options (callback for data, export format, etc.)
     * @return string Schedule ID for reference
     * 
     * @throws Exception If scheduling is not enabled
     */
    public function implementScheduling(string $frequency, array $options = [])
    {
        if (!$this->config['enable_scheduling']) {
            throw new Exception("Scheduling is disabled in configuration");
        }

        // Generate a unique schedule ID
        $scheduleId = 'schedule_' . Str::random(10);
        
        $defaultOptions = [
            'name' => 'Scheduled Export',
            'format' => 'csv',
            'data_callback' => null,
            'notify_on_completion' => true,
            'active' => true,
        ];
        
        $scheduleOptions = array_merge($defaultOptions, $options);
        $scheduleOptions['frequency'] = $frequency;
        
        // Store the schedule in the database
        DB::table('scheduled_exports')->insert([
            'id' => $scheduleId,
            'name' => $scheduleOptions['name'],
            'frequency' => $frequency,
            'format' => $scheduleOptions['format'],
            'options' => json_encode($scheduleOptions),
            'next_run' => $this->calculateNextRunTime($frequency),
            'created_by' => auth()->id() ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
            'active' => $scheduleOptions['active'],
        ]);
        
        Log::info("Scheduled export created", ['id' => $scheduleId, 'frequency' => $frequency]);
        
        return $scheduleId;
    }

    /**
     * Set up security options for exports.
     *
     * @param array $options Security options (encryption, password protection, etc.)
     * @return $this
     */
    public function setupSecurity(array $options = [])
    {
        $defaultOptions = [
            'enable_encryption' => $this->config['security']['enable_encryption'],
            'enable_password_protection' => $this->config['security']['enable_password_protection'],
            'password' => null,
            'encrypt_with_user_key' => false,
            'allow_access_for' => ['creator'], // Roles or user IDs that can access this export
        ];
        
        $securityOptions = array_merge($defaultOptions, $options);
        
        // Store security options in the session
        session()->put("export.security", $securityOptions);
        
        // If password protection is enabled but no password is provided, generate one
        if ($securityOptions['enable_password_protection'] && empty($securityOptions['password'])) {
            $password = Str::random(12);
            session()->put("export.security.password", $password);
            
            // In a real implementation, you might want to store this password securely
            // or send it to the user via a secure channel
            Log::info("Generated password for protected export", ['password' => $password]);
        }
        
        Log::info("Export security options configured", [
            'encryption' => $securityOptions['enable_encryption'],
            'password_protected' => $securityOptions['enable_password_protection'],
        ]);

        return $this;
    }

    /**
     * Create style customization for exports.
     *
     * @param array $styles Style configuration (colors, fonts, etc.)
     * @return $this
     */
    public function createStyleCustomization(array $styles)
    {
        // Store style customization in the session
        session()->put("export.styles", $styles);
        
        Log::info("Export style customization configured");

        return $this;
    }

    /**
     * Set up header and footer options for exports.
     *
     * @param array $options Header/footer configuration options
     * @return $this
     */
    public function setupHeaderFooterOptions(array $options = [])
    {
        $defaultOptions = [
            'show_header' => true,
            'header_text' => null,
            'header_logo' => null,
            'show_footer' => true,
            'footer_text' => null,
            'page_numbers' => true,
            'date' => true,
        ];
        
        $headerFooterOptions = array_merge($defaultOptions, $options);
        
        // Store header/footer options in the session
        session()->put("export.header_footer", $headerFooterOptions);
        
        Log::info("Export header/footer options configured");

        return $this;
    }

    /**
     * Prepare data for export by applying filters, sorting, and column selection.
     *
     * @param mixed $data The data to prepare
     * @param array $options Export options
     * @return array Prepared data ready for export
     */
    protected function prepareDataForExport($data, array $options = [])
    {
        // Convert to collection if it's not already
        if (!$data instanceof Collection) {
            $data = collect($data);
        }

        // Apply column selection if specified
        $selectedColumns = session()->get("export.columns");
        if (!empty($selectedColumns)) {
            $data = $data->map(function ($item) use ($selectedColumns) {
                $result = [];
                foreach ($selectedColumns as $column) {
                    $result[$column] = data_get($item, $column);
                }
                return $result;
            });
        }

        // Apply filters if specified
        $filters = session()->get("export.filters");
        if (!empty($filters)) {
            $data = $data->filter(function ($item) use ($filters) {
                foreach ($filters as $column => $value) {
                    if (is_array($value)) {
                        // Range filter
                        if (isset($value['min']) && data_get($item, $column) < $value['min']) {
                            return false;
                        }
                        if (isset($value['max']) && data_get($item, $column) > $value['max']) {
                            return false;
                        }
                    } else {
                        // Exact match filter
                        if (data_get($item, $column) != $value) {
                            return false;
                        }
                    }
                }
                return true;
            });
        }

        // Apply sorting if specified
        $sorts = session()->get("export.sorts");
        if (!empty($sorts)) {
            foreach ($sorts as $column => $direction) {
                $data = $data->sortBy($column, SORT_REGULAR, strtolower($direction) === 'desc');
            }
        }

        // Convert back to array
        return $data->toArray();
    }

    /**
     * Generate a filename for the export based on format and options.
     *
     * @param string $extension File extension without dot
     * @param array $options Export options
     * @return string Generated filename
     */
    protected function generateFileName(string $extension, array $options = [])
    {
        $baseName = $options['baseFileName'] ?? $this->modelClass ?? 'export';
        $timestamp = $options['includeTimestamp'] ? '_' . date('YmdHis') : '';
        
        return Str::slug($baseName) . $timestamp . '.' . $extension;
    }

    /**
     * Compress a file into a ZIP archive.
     *
     * @param string $sourcePath Source file path
     * @param string $destinationPath Destination ZIP file path
     * @param string $disk Storage disk to use
     * @return bool True on success
     * 
     * @throws Exception If compression fails
     */
    protected function compressFile(string $sourcePath, string $destinationPath, string $disk = 'local')
    {
        try {
            $sourceContents = Storage::disk($disk)->get($sourcePath);
            
            $zip = new \ZipArchive();
            $zipPath = Storage::disk($disk)->path($destinationPath);
            
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== TRUE) {
                throw new Exception("Cannot create ZIP file");
            }
            
            $filename = basename($sourcePath);
            $zip->addFromString($filename, $sourceContents);
            $zip->close();
            
            // Delete the original file
            Storage::disk($disk)->delete($sourcePath);
            
            return true;
        } catch (Exception $e) {
            Log::error("File compression failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to compress file: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Calculate the next run time for a scheduled task based on cron expression.
     *
     * @param string $cronExpression Cron expression for scheduling
     * @return string Next run time in MySQL datetime format
     */
    protected function calculateNextRunTime(string $cronExpression)
    {
        // In a real implementation, you would use a cron expression parser
        // This is a simplified example that assumes basic expressions
        
        $now = Carbon::now();
        
        // Simple parsing for common expressions
        if ($cronExpression === '0 0 * * *') {
            // Daily at midnight
            return $now->copy()->addDay()->startOfDay()->toDateTimeString();
        } elseif ($cronExpression === '0 0 * * 0') {
            // Weekly on Sunday
            return $now->copy()->next(Carbon::SUNDAY)->startOfDay()->toDateTimeString();
        } elseif ($cronExpression === '0 0 1 * *') {
            // Monthly on the 1st
            return $now->copy()->addMonth()->startOfMonth()->toDateTimeString();
        } else {
            // Default to tomorrow
            return $now->copy()->addDay()->startOfDay()->toDateTimeString();
        }
    }
}