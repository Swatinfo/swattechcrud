<?php

namespace SwatTech\Crud\Features\Internationalization;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SwatTech\Crud\Services\BaseService;
use Exception;

/**
 * TranslationManager
 *
 * A service class for managing multi-language translations in the application.
 * Supports dynamic language detection, translation memory, handling of missing translations,
 * pluralization, context-based translations, and import/export functionality.
 *
 * @package SwatTech\Crud\Features\Internationalization
 */
class TranslationManager extends BaseService
{
    /**
     * The translation repository instance.
     *
     * @var mixed
     */
    protected $translationRepository;
    
    /**
     * Configuration for translation functionality.
     *
     * @var array
     */
    protected $config;
    
    /**
     * Translation memory cache.
     *
     * @var array
     */
    protected $translationMemory = [];
    
    /**
     * Currently active locale.
     *
     * @var string
     */
    protected $currentLocale;
    
    /**
     * Available locales in the application.
     *
     * @var array
     */
    protected $availableLocales = [];
    
    /**
     * Create a new TranslationManager instance.
     *
     * @param mixed $translationRepository Optional repository for translation storage
     * @return void
     */
    public function __construct($translationRepository = null)
    {
        $this->translationRepository = $translationRepository;
        $this->currentLocale = App::getLocale();
        
        $this->config = config('crud.features.translations', [
            'default_locale' => 'en',
            'fallback_locales' => ['en'],
            'detect_from_browser' => true,
            'cache_translations' => true,
            'cache_lifetime' => 60, // minutes
            'translation_memory_size' => 1000,
            'auto_translate' => false,
            'auto_translate_provider' => null,
            'supported_locales' => [
                'en' => [
                    'name' => 'English',
                    'native' => 'English',
                    'direction' => 'ltr',
                ],
                'es' => [
                    'name' => 'Spanish',
                    'native' => 'Español',
                    'direction' => 'ltr',
                ],
                'fr' => [
                    'name' => 'French',
                    'native' => 'Français',
                    'direction' => 'ltr',
                ],
                'ar' => [
                    'name' => 'Arabic',
                    'native' => 'العربية',
                    'direction' => 'rtl',
                ],
            ],
            'missing_translation_handler' => 'log', // log, fallback, throw, or callback
            'translation_files_path' => resource_path('lang'),
        ]);
        
        $this->availableLocales = array_keys($this->config['supported_locales']);
    }
    
    /**
     * Manage translations for a specific group.
     *
     * @param string $group The translation group (e.g., 'validation', 'messages')
     * @param array $translations Key-value pairs of translations to manage
     * @param string $locale The locale for these translations (defaults to current locale)
     * @return bool True if translations were successfully managed
     * 
     * @throws Exception If translations cannot be saved
     */
    public function manageTranslations(string $group, array $translations, string $locale = null)
    {
        $locale = $locale ?? $this->currentLocale;
        
        if (!in_array($locale, $this->availableLocales)) {
            throw new Exception("Unsupported locale: {$locale}");
        }
        
        try {
            if ($this->translationRepository) {
                // Store using repository if available
                foreach ($translations as $key => $value) {
                    $this->translationRepository->createOrUpdate([
                        'group' => $group,
                        'key' => $key,
                        'locale' => $locale,
                        'value' => $value,
                    ]);
                }
            } else {
                // Store in language files
                $path = $this->config['translation_files_path'] . "/{$locale}/{$group}.php";
                
                // Make directory if it doesn't exist
                $directory = dirname($path);
                if (!File::isDirectory($directory)) {
                    File::makeDirectory($directory, 0755, true);
                }
                
                // Read existing translations or initialize empty array
                $existingTranslations = [];
                if (File::exists($path)) {
                    $existingTranslations = require $path;
                }
                
                // Merge with new translations
                $mergedTranslations = array_merge($existingTranslations, $translations);
                
                // Generate PHP file content
                $content = "<?php\n\nreturn " . var_export($mergedTranslations, true) . ";\n";
                
                // Save the file
                File::put($path, $content);
                
                // Clear translation cache if using the cache
                if ($this->config['cache_translations']) {
                    $cacheKey = "translations.{$locale}.{$group}";
                    Cache::forget($cacheKey);
                }
            }
            
            // Add to translation memory
            foreach ($translations as $key => $value) {
                $memoryKey = "{$locale}.{$group}.{$key}";
                $this->translationMemory[$memoryKey] = $value;
            }
            
            Log::info("Translations updated for group: {$group} in {$locale}", [
                'keys_count' => count($translations)
            ]);
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to manage translations: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Failed to manage translations: {$e->getMessage()}", 0, $e);
        }
    }
    
    /**
     * Detect the preferred language from a request.
     *
     * @param Request $request The HTTP request
     * @param bool $setLocale Whether to automatically set the detected locale
     * @return string The detected locale code
     */
    public function detectLanguage(Request $request, bool $setLocale = true)
    {
        $locale = $this->config['default_locale'];
        
        // Try to detect from URL parameter
        if ($request->has('lang')) {
            $requestLocale = $request->get('lang');
            if (in_array($requestLocale, $this->availableLocales)) {
                $locale = $requestLocale;
            }
        } 
        // Try to detect from session
        elseif ($request->session()->has('locale')) {
            $sessionLocale = $request->session()->get('locale');
            if (in_array($sessionLocale, $this->availableLocales)) {
                $locale = $sessionLocale;
            }
        } 
        // Try to detect from browser Accept-Language header
        elseif ($this->config['detect_from_browser']) {
            $browserLocales = $request->getLanguages();
            
            if (!empty($browserLocales)) {
                foreach ($browserLocales as $browserLocale) {
                    // Extract base language code (e.g., 'en' from 'en-US')
                    $baseLocale = explode('-', $browserLocale)[0];
                    
                    if (in_array($browserLocale, $this->availableLocales)) {
                        $locale = $browserLocale;
                        break;
                    } elseif (in_array($baseLocale, $this->availableLocales)) {
                        $locale = $baseLocale;
                        break;
                    }
                }
            }
        }
        
        // Set the locale if requested
        if ($setLocale) {
            App::setLocale($locale);
            $this->currentLocale = $locale;
        }
        
        Log::debug("Language detected from request", [
            'detected_locale' => $locale,
            'available_locales' => $this->availableLocales
        ]);
        
        return $locale;
    }
    
    /**
     * Configure fallback locales for translations.
     *
     * @param array $fallbacks Array of fallback locales in priority order
     * @return $this
     */
    public function configureFallbacks(array $fallbacks)
    {
        // Validate that all provided fallbacks are available locales
        $validFallbacks = array_filter($fallbacks, function ($locale) {
            return in_array($locale, $this->availableLocales);
        });
        
        if (empty($validFallbacks)) {
            $validFallbacks = [$this->config['default_locale']];
        }
        
        // Set the fallback locales in the config
        $this->config['fallback_locales'] = $validFallbacks;
        
        // Set Laravel's fallback locale to the first valid fallback
        App::setFallbackLocale($validFallbacks[0]);
        
        Log::info("Translation fallbacks configured", ['fallbacks' => $validFallbacks]);
        
        return $this;
    }
    
    /**
     * Set up translation memory for faster lookups and suggestions.
     *
     * @param int $size Maximum number of entries in the translation memory
     * @param bool $preload Whether to preload common translations
     * @return $this
     */
    public function setupTranslationMemory(int $size = 1000, bool $preload = true)
    {
        $this->config['translation_memory_size'] = $size;
        
        // Preload translation memory if requested
        if ($preload) {
            // Preload common translation groups
            $commonGroups = ['validation', 'auth', 'pagination', 'messages'];
            
            foreach ($this->availableLocales as $locale) {
                foreach ($commonGroups as $group) {
                    if (Lang::hasForLocale($group, $locale)) {
                        $translations = Lang::get($group, [], $locale);
                        
                        if (is_array($translations)) {
                            $this->flattenTranslations($translations, $locale, $group);
                        }
                    }
                }
            }
            
            // Ensure we don't exceed the memory size
            if (count($this->translationMemory) > $size) {
                $this->translationMemory = array_slice($this->translationMemory, 0, $size, true);
            }
        }
        
        Log::info("Translation memory configured", [
            'size' => $size,
            'preloaded' => $preload,
            'current_entries' => count($this->translationMemory)
        ]);
        
        return $this;
    }
    
    /**
     * Handle plural forms for a translation key.
     *
     * @param string $key The translation key
     * @param array $counts Array of count values with corresponding messages
     * @param array $replace Values to replace in the message
     * @param string $locale The locale (defaults to current)
     * @return string The pluralized translation
     */
    public function handlePluralForms(string $key, array $counts, array $replace = [], string $locale = null)
    {
        $locale = $locale ?? $this->currentLocale;
        
        foreach ($counts as $count => $message) {
            // Add the count to the replacements
            $countReplace = array_merge(['count' => $count], $replace);
            
            // Use Laravel's built-in choice method
            return Lang::choice($key, $count, $countReplace, $locale);
        }
        
        // Fallback if there are no counts (shouldn't happen)
        return Lang::get($key, $replace, $locale);
    }
    
    /**
     * Implement context-based translation.
     *
     * @param string $key The translation key
     * @param string $context The context identifier
     * @param array $replace Values to replace in the message
     * @param string $locale The locale (defaults to current)
     * @return string The context-specific translation
     */
    public function implementContextBasedTranslation(string $key, string $context, array $replace = [], string $locale = null)
    {
        $locale = $locale ?? $this->currentLocale;
        
        // First try the context-specific key
        $contextKey = $key . '_' . $context;
        
        if (Lang::has($contextKey, $locale)) {
            return Lang::get($contextKey, $replace, $locale);
        }
        
        // Fallback to the original key
        return Lang::get($key, $replace, $locale);
    }
    
    /**
     * Configure RTL (Right-to-Left) language support.
     *
     * @param array $rtlLocales Array of locales that use RTL
     * @return $this
     */
    public function configureRtlSupport(array $rtlLocales = ['ar', 'he', 'fa', 'ur'])
    {
        // Update the direction for provided RTL locales
        foreach ($rtlLocales as $locale) {
            if (isset($this->config['supported_locales'][$locale])) {
                $this->config['supported_locales'][$locale]['direction'] = 'rtl';
            }
        }
        
        // Create a helper method to check if current locale is RTL
        app()->singleton('isRtl', function () {
            $locale = $this->currentLocale;
            return isset($this->config['supported_locales'][$locale]) && 
                   $this->config['supported_locales'][$locale]['direction'] === 'rtl';
        });
        
        Log::info("RTL language support configured", ['rtl_locales' => $rtlLocales]);
        
        return $this;
    }
    
    /**
     * Set up automatic translation for missing translations.
     *
     * @param string $provider Translation service provider to use
     * @param array $credentials API credentials for the translation provider
     * @return $this
     */
    public function setupAutoTranslation(string $provider = 'google', array $credentials = [])
    {
        $this->config['auto_translate'] = true;
        $this->config['auto_translate_provider'] = $provider;
        $this->config['auto_translate_credentials'] = $credentials;
        
        // Optionally, set up a custom missing translation handler
        $this->config['missing_translation_handler'] = 'auto_translate';
        
        Log::info("Automatic translation configured", [
            'provider' => $provider,
            'has_credentials' => !empty($credentials)
        ]);
        
        return $this;
    }
    
    /**
     * Handle missing translations according to the configured strategy.
     *
     * @param string $key The missing translation key
     * @param array $replace Values to replace in the message
     * @param string $locale The locale where translation is missing
     * @return string|null The handled missing translation or null
     */
    public function handleMissingTranslations(string $key, array $replace = [], string $locale = null)
    {
        $locale = $locale ?? $this->currentLocale;
        $handler = $this->config['missing_translation_handler'];
        
        if ($handler === 'log') {
            Log::warning("Missing translation: {$key} for locale: {$locale}");
            return $key;
        } elseif ($handler === 'fallback') {
            // Try fallback locales in order
            foreach ($this->config['fallback_locales'] as $fallbackLocale) {
                if (Lang::has($key, $fallbackLocale)) {
                    $translation = Lang::get($key, $replace, $fallbackLocale);
                    Log::info("Used fallback translation for {$key}: {$locale} -> {$fallbackLocale}");
                    return $translation;
                }
            }
            return $key;
        } elseif ($handler === 'throw') {
            throw new Exception("Missing translation: {$key} for locale: {$locale}");
        } elseif ($handler === 'auto_translate' && $this->config['auto_translate']) {
            // Attempt automatic translation
            // This would be implemented with the selected translation provider
            Log::info("Auto-translation requested for {$key} in {$locale}");
            
            // Placeholder implementation - in a real app, this would call a translation API
            return "[AUTO] " . $key;
        } elseif (is_callable($handler)) {
            // Custom handler function
            return call_user_func($handler, $key, $replace, $locale);
        }
        
        // Default fallback
        return $key;
    }
    
    /**
     * Import/export translations from/to different formats.
     *
     * @param string $format Format for import/export (json, csv, php, yaml)
     * @param string $action Action to perform ('import' or 'export')
     * @param string $filePath File path for import or export
     * @param string $locale Locale to import/export (null for all locales)
     * @return mixed Result of import/export operation
     * 
     * @throws Exception If import/export operation fails
     */
    public function importExportTranslations(string $format, string $action, string $filePath, string $locale = null)
    {
        try {
            $supportedFormats = ['json', 'csv', 'php', 'yaml'];
            
            if (!in_array($format, $supportedFormats)) {
                throw new Exception("Unsupported format: {$format}. Supported formats: " . implode(', ', $supportedFormats));
            }
            
            if ($action === 'export') {
                return $this->exportTranslations($format, $filePath, $locale);
            } elseif ($action === 'import') {
                return $this->importTranslations($format, $filePath, $locale);
            } else {
                throw new Exception("Unsupported action: {$action}. Use 'import' or 'export'.");
            }
        } catch (Exception $e) {
            Log::error("Translation import/export failed: {$e->getMessage()}", ['exception' => $e]);
            throw new Exception("Translation import/export failed: {$e->getMessage()}", 0, $e);
        }
    }
    
    /**
     * Get all available locales with their information.
     *
     * @return array List of available locales with metadata
     */
    public function getAvailableLocales()
    {
        return $this->config['supported_locales'];
    }
    
    /**
     * Get the current locale with its information.
     *
     * @return array Current locale information
     */
    public function getCurrentLocale()
    {
        $locale = $this->currentLocale;
        return [
            'code' => $locale,
            'info' => $this->config['supported_locales'][$locale] ?? [
                'name' => $locale,
                'native' => $locale,
                'direction' => 'ltr',
            ],
        ];
    }
    
    /**
     * Check if the current language direction is RTL.
     *
     * @return bool True if current language is RTL
     */
    public function isRtl()
    {
        $locale = $this->currentLocale;
        return isset($this->config['supported_locales'][$locale]) && 
               $this->config['supported_locales'][$locale]['direction'] === 'rtl';
    }
    
    /**
     * Flatten a nested translations array for storage in translation memory.
     *
     * @param array $translations The translations to flatten
     * @param string $locale The locale code
     * @param string $group The translation group
     * @param string $prefix The current key prefix
     * @return void
     */
    protected function flattenTranslations(array $translations, string $locale, string $group, string $prefix = '')
    {
        foreach ($translations as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value)) {
                $this->flattenTranslations($value, $locale, $group, $fullKey);
            } else {
                $memoryKey = "{$locale}.{$group}.{$fullKey}";
                $this->translationMemory[$memoryKey] = $value;
            }
        }
    }
    
    /**
     * Export translations to a file in the specified format.
     *
     * @param string $format Export format
     * @param string $filePath File path for export
     * @param string $locale Locale to export (null for all locales)
     * @return bool Success status
     * 
     * @throws Exception If export fails
     */
    protected function exportTranslations(string $format, string $filePath, string $locale = null)
    {
        // Implementation would depend on the format
        // This is a simplified placeholder implementation
        
        $locales = $locale ? [$locale] : $this->availableLocales;
        $allTranslations = [];
        
        // Collect translations for all specified locales
        foreach ($locales as $localeCode) {
            $allTranslations[$localeCode] = [];
            
            // Get all translation files for this locale
            $langPath = $this->config['translation_files_path'] . "/{$localeCode}";
            
            if (File::isDirectory($langPath)) {
                $files = File::files($langPath);
                
                foreach ($files as $file) {
                    $group = pathinfo($file, PATHINFO_FILENAME);
                    $translations = require $file->getPathname();
                    $allTranslations[$localeCode][$group] = $translations;
                }
            }
        }
        
        // Format and save based on requested format
        switch ($format) {
            case 'json':
                $content = json_encode($allTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                File::put($filePath, $content);
                break;
                
            case 'php':
                $content = "<?php\n\nreturn " . var_export($allTranslations, true) . ";\n";
                File::put($filePath, $content);
                break;
                
            case 'csv':
                $file = fopen($filePath, 'w');
                
                // Write header row
                fputcsv($file, array_merge(['locale', 'group', 'key'], $locales));
                
                // Write data rows
                foreach ($allTranslations as $localeCode => $groups) {
                    foreach ($groups as $group => $translations) {
                        $this->writeCsvTranslations($file, $translations, $localeCode, $group);
                    }
                }
                
                fclose($file);
                break;
                
            case 'yaml':
                // This would require a YAML library
                throw new Exception("YAML export not implemented. Please install a YAML library.");
        }
        
        Log::info("Translations exported successfully", [
            'format' => $format,
            'locales' => $locales,
            'output_file' => $filePath
        ]);
        
        return true;
    }
    
    /**
     * Import translations from a file in the specified format.
     *
     * @param string $format Import format
     * @param string $filePath File path to import from
     * @param string $locale Target locale (if format contains multiple locales)
     * @return bool Success status
     * 
     * @throws Exception If import fails
     */
    protected function importTranslations(string $format, string $filePath, string $locale = null)
    {
        // Check if file exists
        if (!File::exists($filePath)) {
            throw new Exception("Import file not found: {$filePath}");
        }
        
        switch ($format) {
            case 'json':
                $content = File::get($filePath);
                $translations = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON file: " . json_last_error_msg());
                }
                
                // Process the imported translations
                foreach ($translations as $localeCode => $groups) {
                    // Skip if we're only importing for a specific locale
                    if ($locale && $locale !== $localeCode) {
                        continue;
                    }
                    
                    foreach ($groups as $group => $items) {
                        $this->manageTranslations($group, $items, $localeCode);
                    }
                }
                break;
                
            case 'php':
                $translations = require $filePath;
                
                // Process the imported translations
                foreach ($translations as $localeCode => $groups) {
                    // Skip if we're only importing for a specific locale
                    if ($locale && $locale !== $localeCode) {
                        continue;
                    }
                    
                    foreach ($groups as $group => $items) {
                        $this->manageTranslations($group, $items, $localeCode);
                    }
                }
                break;
                
            case 'csv':
                $file = fopen($filePath, 'r');
                $headers = fgetcsv($file);
                
                // Validate CSV format
                if (!$headers || count($headers) < 3 || $headers[0] !== 'locale' || $headers[1] !== 'group' || $headers[2] !== 'key') {
                    throw new Exception("Invalid CSV format. Expected columns: locale, group, key, ...");
                }
                
                // Process rows
                while (($row = fgetcsv($file)) !== false) {
                    if (count($row) < 3) {
                        continue; // Skip invalid rows
                    }
                    
                    $rowLocale = $row[0];
                    $group = $row[1];
                    $key = $row[2];
                    
                    // Skip if we're only importing for a specific locale
                    if ($locale && $locale !== $rowLocale) {
                        continue;
                    }
                    
                    // Get value from the appropriate column
                    $localeIndex = array_search($rowLocale, $headers);
                    if ($localeIndex === false || !isset($row[$localeIndex]) || $row[$localeIndex] === '') {
                        continue;
                    }
                    
                    $value = $row[$localeIndex];
                    $this->manageTranslations($group, [$key => $value], $rowLocale);
                }
                
                fclose($file);
                break;
                
            case 'yaml':
                // This would require a YAML library
                throw new Exception("YAML import not implemented. Please install a YAML library.");
        }
        
        Log::info("Translations imported successfully", [
            'format' => $format,
            'import_file' => $filePath,
            'target_locale' => $locale ?? 'all'
        ]);
        
        return true;
    }
    
    /**
     * Write translations to a CSV file in a flattened format.
     *
     * @param resource $file CSV file handle
     * @param array $translations Translations to write
     * @param string $locale Locale code
     * @param string $group Translation group
     * @param string $prefix Current key prefix
     * @return void
     */
    protected function writeCsvTranslations($file, array $translations, string $locale, string $group, string $prefix = '')
    {
        foreach ($translations as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value)) {
                $this->writeCsvTranslations($file, $value, $locale, $group, $fullKey);
            } else {
                // Create a row with locale, group, key, and value
                $row = [$locale, $group, $fullKey, $value];
                fputcsv($file, $row);
            }
        }
    }
}