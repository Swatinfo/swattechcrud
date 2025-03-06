<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SwatTech Crud Generator Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains all configuration settings for the Crud generator.
    | You can customize paths, namespaces, behavior, and appearance of the
    | generated files to match your application's requirements.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Path Configurations
    |--------------------------------------------------------------------------
    |
    | Define the paths where generated files should be placed. These paths
    | are relative to your Laravel application's base path.
    |
    */
    'paths' => [
        'models' => 'app/Models',
        'controllers' => [
            'web' => 'app/Http/Controllers',
            'api' => 'app/Http/Controllers/API',
        ],
        'requests' => 'app/Http/Requests',
        'resources' => 'app/Http/Resources',
        'repositories' => 'app/Repositories',
        'services' => 'app/Services',
        'policies' => 'app/Policies',
        'observers' => 'app/Observers',
        'factories' => 'database/factories',
        'seeders' => 'database/seeders',
        'migrations' => 'database/migrations',
        'views' => 'resources/views',
        'routes' => [
            'web' => 'routes/web.php',
            'api' => 'routes/api.php',
        ],
        'tests' => [
            'unit' => 'tests/Unit',
            'feature' => 'tests/Feature',
            'browser' => 'tests/Browser',
        ],
        'events' => 'app/Events',
        'listeners' => 'app/Listeners',
        'jobs' => 'app/Jobs',
        'lang' => 'resources/lang',
        'notifications' => 'app/Notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespace Configurations
    |--------------------------------------------------------------------------
    |
    | Define the namespaces for the generated PHP classes. These should align
    | with your application's namespacing and the PSR-4 autoloading settings.
    |
    */
    'namespaces' => [
        'models' => 'App\\Models',
        'controllers' => [
            'web' => 'App\\Http\\Controllers',
            'api' => 'App\\Http\\Controllers\\API',
        ],
        'requests' => 'App\\Http\\Requests',
        'resources' => 'App\\Http\\Resources',
        'repositories' => 'App\\Repositories',
        'services' => 'App\\Services',
        'policies' => 'App\\Policies',
        'observers' => 'App\\Observers',
        'factories' => 'Database\\Factories',
        'seeders' => 'Database\\Seeders',
        'events' => 'App\\Events',
        'listeners' => 'App\\Listeners',
        'jobs' => 'App\\Jobs',
        'notifications' => 'App\\Notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection Settings
    |--------------------------------------------------------------------------
    |
    | Configure the database connection to use for analysis and generation.
    | By default, the package will use your application's default connection.
    |
    */
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
        'schema_analyzer' => [
            'exclude_tables' => [
                'migrations', 'password_reset_tokens', 'failed_jobs', 'personal_access_tokens',
            ],
            'include_views' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Detection Settings
    |--------------------------------------------------------------------------
    |
    | Configure how relationships are detected and generated. These settings
    | determine how foreign keys and naming conventions are interpreted.
    |
    */
    'relationships' => [
        'naming_conventions' => [
            // Foreign key pattern: table_name_id or table_name_foreign_key
            'foreign_key_pattern' => [
                '{table}_id',
                '{table}_{key}',
                '{key}',
            ],
            // Polymorphic type and ID column patterns
            'polymorphic_type_pattern' => '{name}_type',
            'polymorphic_id_pattern' => '{name}_id',
            // Pivot table naming pattern
            'pivot_table_pattern' => '{table1}_{table2}',
            // Relationship method naming 
            'belongs_to_method' => '{model}',
            'has_many_method' => '{models}',
            'has_one_method' => '{model}',
            'belongs_to_many_method' => '{models}',
            'morph_many_method' => '{models}',
            'morph_to_method' => '{name}',
        ],
        'detection_strategies' => [
            'belongs_to' => true,
            'has_many' => true,
            'has_one' => true,
            'belongs_to_many' => true,
            'polymorphic' => true,
        ],
        'generate_inverse' => true, // Generate inverse relationships automatically
        'timestamps_on_pivots' => true, // Add timestamps to pivot tables
        'custom_relationships' => [
            // Define custom relationships that can't be auto-detected
            // 'users' => [
            //     [
            //         'type' => 'hasMany',
            //         'model' => 'Comment',
            //         'foreign_key' => 'posted_by',
            //     ]
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Settings
    |--------------------------------------------------------------------------
    |
    | Configure how Eloquent models are generated and what features they use.
    |
    */
    'models' => [
        'soft_deletes' => true, // Enable SoftDeletes when deleted_at column is present
        'timestamps' => true, // Enable timestamps when created_at/updated_at columns are present
        'camel_case_properties' => true, // Use camelCase for property names
        'include_all_columns' => false, // Include all columns in fillable (false = safe approach)
        'mass_assignable_strategy' => 'fillable', // Options: fillable, guarded
        'hidden_columns' => ['password', 'remember_token', 'api_token'], // Attributes to hide
        'cast_dates' => true, // Auto-cast date columns
        'add_attribute_casts' => true, // Add type casting for attributes
        'with_factory' => true, // Generate a factory for the model
        'with_seeder' => true, // Generate a seeder for the model
        'with_relationships' => true, // Include relationships in the model
        'phpDoc_timestamps' => true, // Add PHPDoc blocks for timestamp methods
        'table_naming' => 'snake_plural', // Options: snake_plural, snake_singular, keep
    ],

    /*
    |--------------------------------------------------------------------------
    | Stub Path Customization
    |--------------------------------------------------------------------------
    |
    | You can customize the stubs used to generate files. Publish the package's
    | stubs and modify them to match your coding style and requirements.
    |
    */
    'stubs' => [
        'path' => resource_path('stubs/crud'), // Path to custom stubs
        'use_custom' => false, // Whether to use custom stubs
        'override_default_with_custom' => false, // Override default stubs with custom ones if both exist
        'force_overwrite' => false, // Force overwrite if files exist
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Settings
    |--------------------------------------------------------------------------
    |
    | Configure how the authorization and policies are generated.
    |
    */
    'authorization' => [
        'generate_policies' => true, // Generate policy classes for each model
        'use_gates' => true, // Use Laravel's Gate facade
        'permissions_table' => 'permissions', // For role-based permissions
        'roles_table' => 'roles', // For role-based permissions
        'default_policy_permissions' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'
        ],
        'implement_ownership' => true, // Add ownership checks to policies
        'owner_column' => 'user_id', // Column to use for ownership
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how caching is used within the generated repositories.
    |
    */
    'cache' => [
        'enabled' => true, // Enable caching in repositories
        'lifetime' => 60, // Cache lifetime in minutes
        'driver' => null, // Cache driver (null = use default)
        'prefix' => 'crud_generator', // Cache key prefix
        'use_tags' => true, // Use cache tags (requires Redis/Memcached)
        'auto_flush_on_save' => true, // Auto flush cache on model changes
        'skip_cache_for' => [
            // Methods that should skip cache
            'create', 'update', 'delete', 'restore', 'forceDelete'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vuexy Theme Settings
    |--------------------------------------------------------------------------
    |
    | Configure the Vuexy theme integration for generated views.
    |
    */
    'theme' => [
        'name' => 'vuexy', // Theme name
        'assets' => [
            'css' => [
                'app' => '/assets/compiled/css/app.css',
                'bootstrap' => '/assets/compiled/css/bootstrap.css',
                'theme' => '/assets/compiled/css/app-dark.css',
                'colors' => '/assets/compiled/css/colors.css',
            ],
            'js' => [
                'app' => '/assets/compiled/js/app.js',
                'bootstrap' => '/assets/compiled/js/bootstrap.js',
                'theme' => '/assets/compiled/js/theme.js',
                'components' => '/assets/compiled/js/components.js',
            ],
            'icons' => [
                'type' => 'feather', // Options: feather, fontawesome, bootstrap
                'path' => '/assets/compiled/css/icons/feather-icons.css',
            ],
        ],
        'components' => [
            'card' => 'components.card',
            'data_table' => 'components.data-table',
            'form' => 'components.form',
            'filter' => 'components.filter',
            'modal' => 'components.modal',
            'tabs' => 'components.tabs',
            'file_upload' => 'components.file-upload',
            'charts' => 'components.charts',
            'export_buttons' => 'components.export-buttons',
            'bulk_actions' => 'components.bulk-actions',
            'nested_form' => 'components.nested-form',
        ],
        'layout' => [
            'master' => 'layouts.app',
            'content_section' => 'content',
            'use_breadcrumbs' => true,
            'use_flash_messages' => true,
            'use_back_button' => true,
            'use_card_wrapper' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Type Mappings
    |--------------------------------------------------------------------------
    |
    | Define mappings from database column types to PHP types and HTML input types.
    |
    */
    'field_types' => [
        'mappings' => [
            // MySQL/MariaDB type => [php_type, html_input_type, validation_rules]
            'bigint' => ['int', 'number', 'integer'],
            'int' => ['int', 'number', 'integer'],
            'integer' => ['int', 'number', 'integer'],
            'mediumint' => ['int', 'number', 'integer'],
            'smallint' => ['int', 'number', 'integer'],
            'tinyint' => ['bool', 'checkbox', 'boolean'],
            'boolean' => ['bool', 'checkbox', 'boolean'],
            'decimal' => ['float', 'number', 'numeric'],
            'double' => ['float', 'number', 'numeric'],
            'float' => ['float', 'number', 'numeric'],
            'char' => ['string', 'text', 'string|max:1'],
            'nchar' => ['string', 'text', 'string'],
            'varchar' => ['string', 'text', 'string|max:255'],
            'nvarchar' => ['string', 'text', 'string|max:255'],
            'text' => ['string', 'textarea', 'string'],
            'ntext' => ['string', 'textarea', 'string'],
            'mediumtext' => ['string', 'textarea', 'string'],
            'longtext' => ['string', 'textarea', 'string'],
            'json' => ['array', 'textarea', 'json'],
            'jsonb' => ['array', 'textarea', 'json'],
            'binary' => ['binary', 'file', 'file'],
            'blob' => ['binary', 'file', 'file'],
            'mediumblob' => ['binary', 'file', 'file'],
            'longblob' => ['binary', 'file', 'file'],
            'date' => ['Carbon', 'date', 'date'],
            'datetime' => ['Carbon', 'datetime-local', 'date'],
            'timestamp' => ['Carbon', 'datetime-local', 'date'],
            'time' => ['Carbon', 'time', 'date_format:H:i:s'],
            'year' => ['int', 'number', 'integer|min:1900|max:2100'],
            'enum' => ['string', 'select', 'in:$values'],
            'set' => ['array', 'select-multiple', 'array'],
            'geometry' => ['string', 'text', 'string'],
            'point' => ['string', 'text', 'string'],
            'linestring' => ['string', 'text', 'string'],
            'polygon' => ['string', 'text', 'string'],
            'multipoint' => ['string', 'text', 'string'],
            'multilinestring' => ['string', 'text', 'string'],
            'multipolygon' => ['string', 'text', 'string'],
            'geometrycollection' => ['string', 'text', 'string'],
            'bit' => ['bool', 'checkbox', 'boolean'],
            'uuid' => ['string', 'text', 'uuid'],
            'ipaddress' => ['string', 'text', 'ip'],
            'macaddress' => ['string', 'text', 'regex:/^([0-9A-Fa-f]{2}[:]){5}([0-9A-Fa-f]{2})$/'],
        ],
        'special_fields' => [
            // Special handling for common field names regardless of type
            'email' => ['string', 'email', 'email'],
            'password' => ['string', 'password', 'confirmed'],
            'url' => ['string', 'url', 'url'],
            'slug' => ['string', 'text', 'alpha_dash'],
            'created_at' => ['Carbon', 'datetime-local', 'date'],
            'updated_at' => ['Carbon', 'datetime-local', 'date'],
            'deleted_at' => ['Carbon', 'datetime-local', 'date'],
        ],
        'custom_casts' => [
            // Add custom Eloquent casts for specific columns
            // 'settings' => 'array',
            // 'options' => 'json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Export/Import Format Options
    |--------------------------------------------------------------------------
    |
    | Configure the formats and options for data export and import.
    |
    */
    'export_import' => [
        'formats' => [
            'csv' => [
                'enabled' => true,
                'delimiter' => ',',
                'enclosure' => '"',
                'line_ending' => "\n",
                'use_bom' => true,
            ],
            'excel' => [
                'enabled' => true,
                'extension' => 'xlsx', // xlsx or xls
                'with_headings' => true,
            ],
            'pdf' => [
                'enabled' => true,
                'orientation' => 'portrait', // portrait or landscape
                'paper_size' => 'a4',
                'font' => 'helvetica',
            ],
        ],
        'chunk_size' => 1000, // Process in chunks for large datasets
        'disk' => 'local', // Disk to use for temporary storage
        'include_headings' => true, // Include column headings
        'include_timestamps' => false, // Include timestamps in exports
        'batch_processing' => true, // Use background jobs for processing
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rule Templates
    |--------------------------------------------------------------------------
    |
    | Define templates for common validation rules based on field types and names.
    | These will be used when generating form request classes.
    |
    */
    'validation' => [
        'rule_templates' => [
            'id' => 'sometimes|integer|exists:$TABLE,$COLUMN',
            'name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:$TABLE,$COLUMN,$IGNORE_ID',
            'url' => 'required|url|max:2048',
            'password' => 'required|string|min:8|confirmed',
            'content' => 'required|string',
            'description' => 'required|string',
            'phone' => 'required|string|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:$VALUES',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:2048',
            'file' => 'sometimes|file|max:10240',
            'date' => 'required|date',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'birthdate' => 'required|date|before:today',
            'time' => 'required|date_format:H:i',
            'color' => 'sometimes|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            'address' => 'required|string|max:1000',
            'zipcode' => 'required|string|max:20',
            'boolean' => 'sometimes|boolean',
            'array' => 'sometimes|array',
        ],
        'create_rule_modifiers' => [
            // Add or modify rules for create operations
            'required_without_defaults' => true, // Make fields required only if they don't have default values
        ],
        'update_rule_modifiers' => [
            // Add or modify rules for update operations
            'convert_required_to_sometimes' => true, // Convert 'required' to 'sometimes' for update operations
            'ignore_current_id' => true, // Add exception for the current ID in unique rules
        ],
        'skip_validation_for' => [
            'created_at', 'updated_at', 'deleted_at', 'remember_token'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generator Features
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific generator features.
    |
    */
    'features' => [
        'api' => [
            'enabled' => true,
            'version' => 'v1',
            'prefix' => 'api',
            'use_resources' => true, // Generate API Resources
            'use_pagination' => true, // Use pagination for collections
            'response_envelope' => true, // Wrap responses in a data envelope
            'fractal' => false, // Use Fractal transformers
            'swagger' => true, // Generate Swagger/OpenAPI documentation
        ],
        'tests' => [
            'enabled' => true,
            'browser_tests' => false, // Requires Dusk
            'api_tests' => true,
            'feature_tests' => true,
            'unit_tests' => true,
        ],
        'audit' => [
            'enabled' => true, // Generate auditing functionality
            'use_user_tracking' => true, // Track user who made changes
            'retention_period' => 30, // Days to keep audit records
        ],
        'activity_log' => [
            'enabled' => true, // Generate activity logging
            'ip_tracking' => true, // Track IP addresses
            'user_agent_tracking' => true, // Track user agents
        ],
        'translations' => [
            'enabled' => true, // Generate translation files
            'default_locale' => 'en',
            'supported_locales' => ['en', 'es', 'fr', 'de'],
        ],
        'soft_deletes' => [
            'enabled' => true, // Use soft deletes
            'recovery_ui' => true, // Generate trash/recovery UI
        ],
    ],
];