<?php

namespace SwatTech\Crud\Features\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SwatTech\Crud\Services\BaseService;
use SwatTech\Crud\Contracts\RepositoryInterface;
use Carbon\Carbon;
use Exception;
use SplFileObject;

/**
 * ImportManager
 *
 * A service class for managing data imports from various file formats.
 * Supports CSV and Excel imports with validation, mapping, batch processing,
 * conflict resolution, and rollback capabilities.
 *
 * @package SwatTech\Crud\Features\Import
 */
class ImportManager extends BaseService
{
    /**
     * The repository instance for handling data access.
     *
     * @var RepositoryInterface|null
     */
    protected $repository;

    /**
     * The model class name for import meta information.
     *
     * @var string|null
     */
    protected $modelClass;

    /**
     * Configuration for import functionality.
     *
     * @var array
     */
    protected $config;

    /**
     * Column mapping from file columns to database columns.
     *
     * @var array
     */
    protected $columnMapping = [];

    /**
     * Validation rules for import data.
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * Validation custom messages.
     *
     * @var array
     */
    protected $validationMessages = [];

    /**
     * Callback function for tracking progress.
     *
     * @var callable|null
     */
    protected $progressCallback = null;

    /**
     * Strategy for handling import conflicts.
     *
     * @var string
     */
    protected $conflictStrategy = 'skip';

    /**
     * Whether to enable transaction rollback.
     *
     * @var bool
     */
    protected $enableRollback = true;

    /**
     * Create a new ImportManager instance.
     *
     * @param RepositoryInterface|null $repository The repository instance for data access
     * @param string|null $modelClass The model class name
     * @return void
     */
    public function __construct(?RepositoryInterface $repository = null, ?string $modelClass = null)
    {
        $this->repository = $repository;
        $this->modelClass = $modelClass;
        $this->config = config('crud.features.import', [
            'formats' => ['csv', 'excel'],
            'chunk_size' => 500,
            'max_rows' => 10000,
            'allowed_file_size' => 10 * 1024, // 10MB
            'column_matching' => 'auto',
            'validation' => [
                'required' => true,
                'batch' => true,
            ],
            'conflict_strategies' => [
                'skip',
                'update',
                'duplicate',
            ],
            'disk' => 'local',
            'directory' => 'imports',
        ]);
    }

    /**
     * Import data from a CSV file.
     *
     * @param UploadedFile $file The uploaded CSV file
     * @param array $options Import options
     * @return array Import results statistics
     * 
     * @throws Exception If import fails
     */
    public function importFromCsv(UploadedFile $file, array $options = [])
    {
        $options = array_merge([
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
            'has_header_row' => true,
            'skip_rows' => 0,
            'max_rows' => $this->config['max_rows'],
            'batch_size' => $this->config['chunk_size'],
        ], $options);

        try {
            // Validate the file
            $this->validateFile($file, 'csv');

            // Store the file temporarily
            $filePath = $this->storeFile($file);

            // Open the file
            $csv = new SplFileObject(Storage::disk($this->config['disk'])->path($filePath), 'r');
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
            $csv->setCsvControl($options['delimiter'], $options['enclosure'], $options['escape']);

            // Skip rows if needed
            for ($i = 0; $i < $options['skip_rows']; $i++) {
                $csv->current();
                $csv->next();
            }

            // Extract headers if present
            $headers = [];
            if ($options['has_header_row']) {
                $headers = $csv->current();
                $csv->next();

                // Clean headers
                $headers = array_map(function ($header) {
                    return trim(strtolower(str_replace(' ', '_', $header)));
                }, $headers);
            }

            // Prepare column mapping if auto-matching is enabled
            if (empty($this->columnMapping) && $this->config['column_matching'] === 'auto' && !empty($headers)) {
                $this->autoMapColumns($headers);
            }

            // Process the data
            $data = [];
            $rowNumber = $options['skip_rows'] + ($options['has_header_row'] ? 1 : 0);
            $maxRows = $options['max_rows'];

            while (!$csv->eof() && $rowNumber < $options['skip_rows'] + $maxRows) {
                $row = $csv->current();

                if (!empty($row) && !$this->isRowEmpty($row)) {
                    if (!empty($headers)) {
                        // Associate with headers
                        $rowData = array_combine($headers, $row);
                    } else {
                        // Use numeric keys
                        $rowData = $row;
                    }

                    // Apply column mapping
                    $rowData = $this->applyColumnMapping($rowData);

                    // Add to data collection
                    $data[] = $rowData;
                }

                $csv->next();
                $rowNumber++;

                // Process in batches if enabled
                if (count($data) >= $options['batch_size']) {
                    $batchResult = $this->processBatch($data, $options);
                    $results = $batchResult;
                    $data = []; // Reset for next batch
                }

                // Update progress if callback is set
                if ($this->progressCallback !== null) {
                    call_user_func($this->progressCallback, min(100, round(($rowNumber / $maxRows) * 100)));
                }
            }

            // Process any remaining data
            if (!empty($data)) {
                $batchResult = $this->processBatch($data, $options);
                $results = isset($results) ? $this->mergeResults($results, $batchResult) : $batchResult;
            }

            // Clean up temporary file
            Storage::disk($this->config['disk'])->delete($filePath);

            // Log the success
            Log::info("CSV import completed", [
                'file' => $file->getClientOriginalName(),
                'rows_processed' => $results['processed'] ?? 0,
                'successful' => $results['successful'] ?? 0,
                'failures' => $results['failures'] ?? 0,
            ]);

            return $results ?? ['processed' => 0, 'successful' => 0, 'failures' => 0, 'errors' => []];
        } catch (Exception $e) {
            // Clean up temporary file if it exists
            if (isset($filePath) && Storage::disk($this->config['disk'])->exists($filePath)) {
                Storage::disk($this->config['disk'])->delete($filePath);
            }

            Log::error("CSV import failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to import CSV data: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Import data from an Excel file.
     *
     * @param UploadedFile $file The uploaded Excel file
     * @param array $options Import options
     * @return array Import results statistics
     * 
     * @throws Exception If import fails
     */
    public function importFromExcel(UploadedFile $file, array $options = [])
    {
        $options = array_merge([
            'sheet_name' => null, // Default to first sheet if null
            'has_header_row' => true,
            'skip_rows' => 0,
            'max_rows' => $this->config['max_rows'],
            'batch_size' => $this->config['chunk_size'],
        ], $options);

        try {
            // Validate the file
            $this->validateFile($file, 'excel');

            // Store the file temporarily
            $filePath = $this->storeFile($file);

            // Check if we have Laravel Excel or PhpSpreadsheet
            if (class_exists('\Maatwebsite\Excel\Facades\Excel')) {
                $results = $this->importWithLaravelExcel($filePath, $options);
            } elseif (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                $results = $this->importWithPhpSpreadsheet($filePath, $options);
            } else {
                throw new Exception("Excel import requires Laravel Excel or PhpSpreadsheet library");
            }

            // Clean up temporary file
            Storage::disk($this->config['disk'])->delete($filePath);

            // Log the success
            Log::info("Excel import completed", [
                'file' => $file->getClientOriginalName(),
                'rows_processed' => $results['processed'] ?? 0,
                'successful' => $results['successful'] ?? 0,
                'failures' => $results['failures'] ?? 0,
            ]);

            return $results;
        } catch (Exception $e) {
            // Clean up temporary file if it exists
            if (isset($filePath) && Storage::disk($this->config['disk'])->exists($filePath)) {
                Storage::disk($this->config['disk'])->delete($filePath);
            }

            Log::error("Excel import failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to import Excel data: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up validation rules for import data.
     *
     * @param array $rules Validation rules for data fields
     * @param array $messages Custom error messages (optional)
     * @return $this
     */
    public function setupValidation(array $rules, array $messages = [])
    {
        $this->validationRules = $rules;
        $this->validationMessages = $messages;

        Log::debug("Import validation rules configured", ['rules' => $rules]);

        return $this;
    }

    /**
     * Set up mapping between file columns and database columns.
     *
     * @param array $columnMapping Array mapping file columns to database columns
     * @return $this
     */
    public function setupMapping(array $columnMapping)
    {
        $this->columnMapping = $columnMapping;

        Log::debug("Column mapping configured", ['mapping' => $columnMapping]);

        return $this;
    }

    /**
     * Handle errors during import process.
     *
     * @param array $errors Array of error information
     * @return array Processed errors with additional context
     */
    public function handleErrors(array $errors)
    {
        $processedErrors = [];

        foreach ($errors as $index => $error) {
            // Format error messages
            if (is_string($error)) {
                $processedErrors[] = [
                    'row' => $index + 1, // Adding 1 to convert from 0-indexed to 1-indexed for user display
                    'message' => $error,
                    'timestamp' => now()->toDateTimeString(),
                ];
            } else {
                $errorMessage = is_array($error) && isset($error['message'])
                    ? $error['message']
                    : "Error in row " . ($index + 1);

                $processedErrors[] = [
                    'row' => $index + 1,
                    'message' => $errorMessage,
                    'field' => $error['field'] ?? null,
                    'value' => $error['value'] ?? null,
                    'timestamp' => now()->toDateTimeString(),
                ];
            }
        }

        // Log errors
        if (!empty($processedErrors)) {
            Log::warning("Import errors occurred", ['count' => count($processedErrors), 'errors' => $processedErrors]);
        }

        return $processedErrors;
    }

    /**
     * Set up a callback function to track import progress.
     *
     * @param callable $callback Function to call with progress updates (takes percentage as parameter)
     * @return $this
     */
    public function trackProgress(callable $callback)
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Create a preview of the import file.
     *
     * @param UploadedFile $file The uploaded file
     * @param int $rows Number of preview rows to return
     * @return array Preview data including headers and sample rows
     * 
     * @throws Exception If preview creation fails
     */
    public function createPreview(UploadedFile $file, int $rows = 10)
    {
        try {
            $extension = strtolower($file->getClientOriginalExtension());

            // Store the file temporarily
            $filePath = $this->storeFile($file);

            // Get preview data based on file type
            if ($extension === 'csv' || $extension === 'txt') {
                $preview = $this->createCsvPreview($filePath, $rows);
            } elseif (in_array($extension, ['xlsx', 'xls'])) {
                $preview = $this->createExcelPreview($filePath, $rows);
            } else {
                throw new Exception("Unsupported file format for preview");
            }

            // Clean up temporary file
            Storage::disk($this->config['disk'])->delete($filePath);

            return $preview;
        } catch (Exception $e) {
            // Clean up temporary file if it exists
            if (isset($filePath) && Storage::disk($this->config['disk'])->exists($filePath)) {
                Storage::disk($this->config['disk'])->delete($filePath);
            }

            Log::error("Preview creation failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to create import preview: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set up strategy for resolving conflicts during import.
     *
     * @param string $strategy Conflict resolution strategy ('skip', 'update', 'duplicate')
     * @return $this
     * 
     * @throws Exception If the strategy is not supported
     */
    public function setupConflictResolution(string $strategy)
    {
        if (!in_array($strategy, $this->config['conflict_strategies'])) {
            throw new Exception("Unsupported conflict resolution strategy: {$strategy}");
        }

        $this->conflictStrategy = $strategy;
        Log::debug("Conflict resolution strategy set to: {$strategy}");

        return $this;
    }

    /**
     * Set up batch processing for large imports.
     *
     * @param int $batchSize Number of records to process in each batch
     * @return $this
     */
    public function implementBatchProcessing(int $batchSize)
    {
        $this->config['chunk_size'] = $batchSize;
        Log::debug("Import batch size set to: {$batchSize}");

        return $this;
    }

    /**
     * Enable or disable transaction rollback for failed imports.
     *
     * @param bool $enable Whether to enable transaction rollback
     * @return $this
     */
    public function setupRollback(bool $enable = true)
    {
        $this->enableRollback = $enable;
        Log::debug("Import rollback " . ($enable ? "enabled" : "disabled"));

        return $this;
    }

    /**
     * Set up detailed logging for the import process.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param bool $logDetails Whether to log detailed information
     * @return $this
     */
    public function createLogging(string $level = 'info', bool $logDetails = false)
    {
        $this->config['logging'] = [
            'level' => $level,
            'details' => $logDetails,
        ];

        Log::debug("Import logging configured", ['level' => $level, 'details' => $logDetails]);

        return $this;
    }

    /**
     * Process a batch of import data.
     *
     * @param array $data The data batch to process
     * @param array $options Processing options
     * @return array Results of the batch processing
     */
    protected function processBatch(array $data, array $options)
    {
        $results = [
            'processed' => count($data),
            'successful' => 0,
            'failures' => 0,
            'errors' => [],
        ];

        // Start a transaction if rollback is enabled
        if ($this->enableRollback) {
            DB::beginTransaction();
        }

        try {
            foreach ($data as $index => $record) {
                $validationResult = $this->validateRecord($record);

                if ($validationResult['valid']) {
                    // Handle potential conflicts
                    $conflictResult = $this->handleConflict($record);

                    if ($conflictResult['success']) {
                        $results['successful']++;
                    } else {
                        $results['failures']++;
                        $results['errors'][] = [
                            'row' => $index + 1,
                            'message' => $conflictResult['message'] ?? 'Conflict resolution failed',
                        ];
                    }
                } else {
                    $results['failures']++;
                    $results['errors'][] = [
                        'row' => $index + 1,
                        'message' => 'Validation failed: ' . implode(', ', $validationResult['errors']),
                    ];
                }
            }

            // Commit transaction if no exceptions
            if ($this->enableRollback) {
                DB::commit();
            }
        } catch (Exception $e) {
            // Rollback transaction on exception
            if ($this->enableRollback) {
                DB::rollBack();
            }

            $results['failures'] = count($data);
            $results['successful'] = 0;
            $results['errors'][] = [
                'message' => "Batch processing failed: {$e->getMessage()}",
                'exception' => $e->getMessage(),
            ];

            Log::error("Import batch processing failed", ['exception' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Validate a single record against the validation rules.
     *
     * @param array $record The record to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    protected function validateRecord(array $record)
    {
        if (empty($this->validationRules)) {
            return ['valid' => true, 'errors' => []];
        }

        $validator = Validator::make($record, $this->validationRules, $this->validationMessages);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all(),
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Handle potential conflicts based on the configured strategy.
     *
     * @param array $record The record to check for conflicts
     * @return array Result with 'success' boolean and optional 'message'
     */
    protected function handleConflict(array $record)
    {
        if (!$this->repository) {
            throw new Exception("Repository is required for conflict handling");
        }

        // Check for unique identifier to detect conflicts
        $uniqueIdentifiers = ['id', 'uuid', 'email', 'username', 'code']; // Common identifiers
        $existingRecord = null;

        foreach ($uniqueIdentifiers as $identifier) {
            if (!empty($record[$identifier])) {
                $existingRecord = $this->repository->findBy($identifier, $record[$identifier]);
                if ($existingRecord) {
                    break;
                }
            }
        }

        // If no conflict found, just create the record
        if (!$existingRecord) {
            try {
                $this->repository->create($record);
                return ['success' => true];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => "Failed to create record: {$e->getMessage()}",
                ];
            }
        }

        // Handle based on conflict strategy
        switch ($this->conflictStrategy) {
            case 'skip':
                return [
                    'success' => true,
                    'message' => 'Record already exists - skipped',
                ];

            case 'update':
                try {
                    $this->repository->update($existingRecord->id, $record);
                    return ['success' => true];
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'message' => "Failed to update existing record: {$e->getMessage()}",
                    ];
                }

            case 'duplicate':
                try {
                    // Remove identifiers to avoid conflicts
                    foreach ($uniqueIdentifiers as $identifier) {
                        unset($record[$identifier]);
                    }

                    $this->repository->create($record);
                    return ['success' => true];
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'message' => "Failed to create duplicate record: {$e->getMessage()}",
                    ];
                }

            default:
                return [
                    'success' => false,
                    'message' => "Unknown conflict resolution strategy: {$this->conflictStrategy}",
                ];
        }
    }

    /**
     * Validate the uploaded file.
     *
     * @param UploadedFile $file The file to validate
     * @param string $type Expected file type ('csv' or 'excel')
     * @return bool True if file is valid
     * 
     * @throws Exception If the file is invalid
     */
    protected function validateFile(UploadedFile $file, string $type)
    {
        // Check if the file is valid
        if (!$file->isValid()) {
            throw new Exception("Uploaded file is not valid: " . $file->getErrorMessage());
        }

        // Check file size
        $maxSize = $this->config['allowed_file_size'];
        if ($file->getSize() > $maxSize * 1024) {
            throw new Exception("File size exceeds maximum allowed size of {$maxSize}KB");
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());

        if ($type === 'csv' && $extension !== 'csv') {
            throw new Exception("Invalid file format. Expected CSV file.");
        }

        if ($type === 'excel' && !in_array($extension, ['xlsx', 'xls'])) {
            throw new Exception("Invalid file format. Expected Excel file (.xlsx or .xls).");
        }

        return true;
    }

    /**
     * Store the uploaded file temporarily.
     *
     * @param UploadedFile $file The file to store
     * @return string The path to the stored file
     */
    protected function storeFile(UploadedFile $file)
    {
        $fileName = Str::random(16) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($this->config['directory'], $fileName, $this->config['disk']);

        return $path;
    }

    /**
     * Import Excel file using Laravel Excel package.
     *
     * @param string $filePath Path to the stored Excel file
     * @param array $options Import options
     * @return array Import results
     */
    protected function importWithLaravelExcel(string $filePath, array $options)
    {
        // Implementation would use Maatwebsite\Excel\Facades\Excel
        // This is a placeholder that would be implemented with the actual library
        throw new Exception("Laravel Excel implementation not available in this sample");
    }

    /**
     * Import Excel file using PhpSpreadsheet directly.
     *
     * @param string $filePath Path to the stored Excel file
     * @param array $options Import options
     * @return array Import results
     */
    protected function importWithPhpSpreadsheet(string $filePath, array $options)
    {
        // Implementation would use PhpOffice\PhpSpreadsheet\IOFactory
        // This is a placeholder that would be implemented with the actual library
        throw new Exception("PhpSpreadsheet implementation not available in this sample");
    }

    /**
     * Create a preview for a CSV file.
     *
     * @param string $filePath Path to the stored CSV file
     * @param int $rows Number of preview rows to return
     * @return array Preview data
     */
    protected function createCsvPreview(string $filePath, int $rows)
    {
        $preview = [
            'headers' => [],
            'rows' => [],
            'row_count' => 0,
        ];

        $fullPath = Storage::disk($this->config['disk'])->path($filePath);
        $csv = new SplFileObject($fullPath, 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        // Get headers from first row
        if (!$csv->eof()) {
            $preview['headers'] = $csv->current();
            $csv->next();
        }

        // Get sample rows
        $rowCount = 0;
        while (!$csv->eof() && $rowCount < $rows) {
            $row = $csv->current();

            if (!empty($row) && !$this->isRowEmpty($row)) {
                $preview['rows'][] = array_combine(
                    $preview['headers'],
                    count($preview['headers']) === count($row) ? $row : array_pad($row, count($preview['headers']), null)
                );
                $rowCount++;
            }

            $csv->next();
        }

        // Count total rows
        $totalRows = 0;
        $csv->rewind();
        if (!$csv->eof()) {
            $csv->next(); // Skip header row
        }

        while (!$csv->eof()) {
            $row = $csv->current();

            if (!empty($row) && !$this->isRowEmpty($row)) {
                $totalRows++;
            }

            $csv->next();
        }

        $preview['row_count'] = $totalRows;

        return $preview;
    }

    /**
     * Create a preview for an Excel file.
     *
     * @param string $filePath Path to the stored Excel file
     * @param int $rows Number of preview rows to return
     * @return array Preview data
     */
    protected function createExcelPreview(string $filePath, int $rows)
    {
        // This would be implemented using Laravel Excel or PhpSpreadsheet
        // Since we're only showing a code example, return a placeholder
        return [
            'headers' => ['Column A', 'Column B', 'Column C'],
            'rows' => [
                ['Value A1', 'Value B1', 'Value C1'],
                ['Value A2', 'Value B2', 'Value C2'],
            ],
            'row_count' => 2,
        ];
    }

    /**
     * Apply column mapping to a data row.
     *
     * @param array $row The data row to transform
     * @return array Transformed row with column mapping applied
     */
    protected function applyColumnMapping(array $row)
    {
        if (empty($this->columnMapping)) {
            return $row;
        }

        $mappedRow = [];

        foreach ($this->columnMapping as $fileColumn => $dbColumn) {
            if (isset($row[$fileColumn])) {
                $mappedRow[$dbColumn] = $row[$fileColumn];
            }
        }

        return $mappedRow;
    }

    /**
     * Automatically map columns based on file headers and database schema.
     *
     * @param array $headers File column headers
     * @return void
     */
    protected function autoMapColumns(array $headers)
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
            return;
        }

        // Get table name from model
        $model = new $this->modelClass;
        $table = $model->getTable();

        // Get database columns
        $dbColumns = Schema::getColumnListing($table);

        // Create mapping
        $mapping = [];
        foreach ($headers as $header) {
            $sanitized = Str::snake($header);

            // Direct match
            if (in_array($sanitized, $dbColumns)) {
                $mapping[$header] = $sanitized;
                continue;
            }

            // Try to find similar columns
            foreach ($dbColumns as $column) {
                if (Str::contains($sanitized, $column) || Str::contains($column, $sanitized)) {
                    $mapping[$header] = $column;
                    break;
                }
            }
        }

        $this->columnMapping = $mapping;
    }

    /**
     * Check if a CSV row is completely empty.
     *
     * @param array $row The row to check
     * @return bool True if the row is empty
     */
    protected function isRowEmpty(array $row)
    {
        // Filter out empty values
        $filteredRow = array_filter($row, function ($value) {
            return $value !== null && $value !== '';
        });

        return count($filteredRow) === 0;
    }

    /**
     * Merge results from multiple batches.
     *
     * @param array $results1 First batch results
     * @param array $results2 Second batch results
     * @return array Merged results
     */
    protected function mergeResults(array $results1, array $results2)
    {
        return [
            'processed' => $results1['processed'] + $results2['processed'],
            'successful' => $results1['successful'] + $results2['successful'],
            'failures' => $results1['failures'] + $results2['failures'],
            'errors' => array_merge($results1['errors'], $results2['errors']),
        ];
    }
}
